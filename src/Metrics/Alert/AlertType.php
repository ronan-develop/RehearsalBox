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
}
