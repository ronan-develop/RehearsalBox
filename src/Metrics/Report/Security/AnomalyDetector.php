<?php

declare(strict_types=1);

namespace App\Metrics\Report\Security;

use App\Metrics\MetricEventType;

/**
 * Repère les anomalies dans les évènements de sécurité (#197), par empreinte d'adresse : fonction pure, sans horloge ni base,
 * donc testable avec des séries simulées. Trois règles, aux seuils réglables (`metrics.anomalies`) :
 * - scanner : au moins `scannerHits` pages introuvables sur des chemins de balayage ;
 * - rafale : au moins `burstEvents` évènements de sécurité en `burstMinutes` minutes ;
 * - échecs de connexion répétés (bourrage d'identifiants) : au moins `stuffingFailures` échecs en `stuffingMinutes` minutes.
 * Sans empreinte (secret non configuré), les évènements sont regroupés ensemble sous « — ». On rapporte, on ne bannit pas.
 */
final class AnomalyDetector
{
    public function __construct(
        private readonly int $scannerHits = 3,
        private readonly int $burstEvents = 30,
        private readonly int $burstMinutes = 5,
        private readonly int $stuffingFailures = 10,
        private readonly int $stuffingMinutes = 60,
    ) {
    }

    /**
     * @param list<SecurityEvent> $events
     *
     * @return list<Anomaly> les plus récentes d'abord
     */
    public function detect(array $events): array
    {
        $byFingerprint = [];
        foreach ($events as $event) {
            $byFingerprint[$event->ipHash ?? ''][] = $event;
        }

        $found = [];
        foreach ($byFingerprint as $fingerprint => $list) {
            usort($list, static fn (SecurityEvent $a, SecurityEvent $b): int => $a->at <=> $b->at);
            $short = $fingerprint === '' ? '—' : substr((string) $fingerprint, 0, 8);

            $scans = array_values(array_filter($list, static fn (SecurityEvent $e): bool => $e->type === MetricEventType::NotFound && ScannerPaths::matches($e->route)));
            if (count($scans) >= $this->scannerHits) {
                $found[] = new Anomaly(AnomalyKind::Scanner, $short, count($scans), $scans[0]->at, $scans[count($scans) - 1]->at);
            }

            $burst = self::densestWindow($list, $this->burstMinutes, $this->burstEvents);
            if ($burst !== null) {
                $found[] = new Anomaly(AnomalyKind::Burst, $short, ...$burst);
            }

            $logins = array_values(array_filter($list, static fn (SecurityEvent $e): bool => $e->type === MetricEventType::LoginFailed));
            $stuffing = self::densestWindow($logins, $this->stuffingMinutes, $this->stuffingFailures);
            if ($stuffing !== null) {
                $found[] = new Anomaly(AnomalyKind::CredentialStuffing, $short, ...$stuffing);
            }
        }

        usort($found, static fn (Anomaly $a, Anomaly $b): int => $b->lastAt <=> $a->lastAt);

        return $found;
    }

    /**
     * La fenêtre glissante la plus fournie, si elle atteint le seuil.
     *
     * @param list<SecurityEvent> $sorted triés par date
     *
     * @return array{int, \DateTimeImmutable, \DateTimeImmutable}|null [nombre, début, fin]
     */
    private static function densestWindow(array $sorted, int $minutes, int $threshold): ?array
    {
        $best = null;
        $from = 0;
        foreach ($sorted as $to => $event) {
            while ($event->at->getTimestamp() - $sorted[$from]->at->getTimestamp() > $minutes * 60) {
                ++$from;
            }
            $count = $to - $from + 1;
            if ($count >= $threshold && ($best === null || $count > $best[0])) {
                $best = [$count, $sorted[$from]->at, $event->at];
            }
        }

        return $best;
    }
}
