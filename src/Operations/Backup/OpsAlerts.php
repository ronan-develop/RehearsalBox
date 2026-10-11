<?php

declare(strict_types=1);

namespace App\Operations\Backup;

use App\Metrics\Alert\Alert;
use App\Metrics\Alert\AlertType;
use App\Metrics\Report\HealthStatus;

/**
 * Les alertes de protection des sauvegardes (#375), fournies à AlertEvaluator : seule une carte ROUGE alerte (un orange se lit sur la
 * page, il ne réveille personne). Phrases fixes : jamais de chemin du serveur, de nom de clé ni de détail d'erreur. Le dump quotidien trop
 * ancien est déjà couvert par l'alerte des mesures (`backup_hours`).
 */
final class OpsAlerts
{
    private const PAGE = '/admin/ops';

    private const SENTENCES = [
        'key_file' => [AlertType::KeyFile, 'Le fichier de clés de chiffrement est absent, illisible ou trop ouvert : sans lui, les messages seraient perdus.'],
        'task_backup' => [AlertType::BackupTask, 'Le dernier passage de la tâche de sauvegarde n\'a pas produit de dump.'],
        'offsite' => [AlertType::OffsiteOld, 'Aucune copie des sauvegardes hors du serveur depuis plus d\'un mois.'],
    ];

    public function __construct(private readonly ProtectionHealthBuilder $protection)
    {
    }

    /** @return list<Alert> */
    public function alerts(): array
    {
        $cards = $this->protection->cards();
        $alerts = [];
        foreach (self::SENTENCES as $indicator => [$type, $sentence]) {
            if (($cards[$indicator]->status ?? null) === HealthStatus::Critical) {
                $alerts[] = new Alert($type, $sentence, self::PAGE);
            }
        }

        return $alerts;
    }
}
