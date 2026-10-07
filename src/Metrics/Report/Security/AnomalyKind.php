<?php

declare(strict_types=1);

namespace App\Metrics\Report\Security;

enum AnomalyKind: string
{
    case Scanner = 'scanner';
    case Burst = 'burst';
    case CredentialStuffing = 'credential_stuffing';

    public function label(): string
    {
        return match ($this) {
            self::Scanner => 'Scanner de chemins',
            self::Burst => 'Rafale',
            self::CredentialStuffing => 'Échecs de connexion répétés',
        };
    }
}
