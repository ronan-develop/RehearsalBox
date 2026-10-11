<?php

declare(strict_types=1);

namespace App\Metrics\Alert;

use App\Mail\Mailbox;
use Psr\Clock\ClockInterface;

/**
 * Prévient par e-mail quand un seuil rouge est franchi (#199), sans noyer : UN seul message regroupe toutes les alertes dues (un
 * résumé, jamais une rafale), et un type d'alerte déjà envoyé n'est pas renvoyé avant `$minGapHours` heures. Le destinataire est le
 * propriétaire du tableau de bord (`metrics.viewer_email`). Un échec d'envoi n'a AUCUN effet sur le site : rien n'est marqué envoyé,
 * la prochaine collecte réessaie. Le message ne contient que des chiffres et un lien vers le tableau de bord.
 */
final class MetricsAlerter
{
    public function __construct(
        private readonly AlertEvaluator $evaluator,
        private readonly AlertRepositoryInterface $sent,
        private readonly Mailbox $mailbox,
        private readonly ClockInterface $clock,
        private readonly string $recipient,
        private readonly int $minGapHours,
    ) {
    }

    /** @return int nombre d'alertes envoyées (0 si rien d'à envoyer, destinataire non configuré ou échec d'envoi) */
    public function run(): int
    {
        if ($this->recipient === '') {
            return 0;
        }

        $now = $this->clock->now();
        $due = array_values(array_filter($this->evaluator->evaluate(), function (Alert $alert) use ($now): bool {
            $last = $this->sent->lastSentAt($alert->type);

            return $last === null || $last <= $now->modify('-' . $this->minGapHours . ' hours');
        }));
        if ($due === []) {
            return 0;
        }

        $delivered = $this->mailbox->sendSafely(
            fn () => $this->mailbox->compose(
                $this->recipient,
                count($due) === 1 ? 'Alerte de mesures' : 'Alertes de mesures (' . count($due) . ')',
                'metrics-alert',
                ['messages' => array_map(static fn (Alert $a): string => $a->message, $due), 'links' => $this->links($due)],
            ),
            'Alertes de mesures : envoi impossible',
        );
        if (!$delivered) {
            return 0;
        }
        foreach ($due as $alert) {
            $this->sent->markSent($alert->type, $now);
        }

        return count($due);
    }

    /**
     * Un lien par page concernée (mesures, exploitation), sans doublon.
     *
     * @param list<Alert> $due
     *
     * @return list<string>
     */
    private function links(array $due): array
    {
        return array_map($this->mailbox->url(...), array_values(array_unique(array_map(static fn (Alert $a): string => $a->page, $due))));
    }
}
