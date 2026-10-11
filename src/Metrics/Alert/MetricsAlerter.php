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
                $this->subject($due),
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
     * Le sujet nomme l'indicateur (« Alerte : fichier de clés de chiffrement ») ; à plusieurs, le nombre puis les deux premiers.
     *
     * @param list<Alert> $due
     */
    private function subject(array $due): string
    {
        $labels = array_map(static fn (Alert $a): string => $a->type->label(), $due);
        if (count($labels) === 1) {
            return 'Alerte : ' . $labels[0];
        }

        return 'Alertes (' . count($labels) . ') : ' . implode(', ', array_slice($labels, 0, 2)) . (count($labels) > 2 ? '…' : '');
    }

    /**
     * Un lien par page concernée, sans doublon. Il passe par la connexion (`/login?next=`) : les pages réservées au propriétaire
     * répondent 404 à un visiteur, le lien direct n'aboutirait donc jamais sans session. Après connexion on arrive sur la bonne page.
     *
     * @param list<Alert> $due
     *
     * @return list<array{label: string, url: string}>
     */
    private function links(array $due): array
    {
        $links = [];
        foreach (array_unique(array_map(static fn (Alert $a): string => $a->page, $due)) as $page) {
            $links[] = [
                'label' => str_starts_with($page, '/admin/ops') ? 'Voir l\'exploitation' : 'Voir le tableau de bord',
                'url' => $this->mailbox->url('/login?next=' . rawurlencode($page)),
            ];
        }

        return $links;
    }
}
