<?php

declare(strict_types=1);

namespace App\Metrics\Alert;

enum AlertType: string
{
    case CronSilent = 'cron_silent';
    case BackupOld = 'backup_old';
    case DiskLow = 'disk_low';
    case ServerErrors = 'server_errors';
    case Degradation = 'degradation';
    case Attack = 'attack';
    case KeyFile = 'key_file';
    case BackupTask = 'backup_task';
    case OffsiteOld = 'offsite_old';

    /** Ce que dit le sujet de l'e-mail : l'indicateur en cause, en quelques mots. */
    public function label(): string
    {
        return match ($this) {
            self::CronSilent => 'cron des relances silencieux',
            self::BackupOld => 'sauvegarde de la base trop ancienne',
            self::DiskLow => 'espace disque faible',
            self::ServerErrors => 'erreurs serveur',
            self::Degradation => 'site dégradé',
            self::Attack => 'rafales de connexions suspectes',
            self::KeyFile => 'fichier de clés de chiffrement',
            self::BackupTask => 'tâche de sauvegarde en échec',
            self::OffsiteOld => 'aucune copie hors serveur récente',
        };
    }
}
