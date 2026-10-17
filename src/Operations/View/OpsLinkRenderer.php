<?php

declare(strict_types=1);

namespace App\Operations\View;

use App\Account\Entity\UserRole;
use App\Metrics\MetricsAccess;
use App\Metrics\OperationsAccess;
use App\Security\AuthGuard;
use App\View\TemplateRendererInterface;

/**
 * Ajoute à chaque gabarit d'administrateur deux indicateurs, sans que chaque contrôleur ait à les calculer : `showOpsLink` (#400, le lien
 * « Ops », pour tout administrateur actif : OperationsAccess) et `showRestoreLink` (le lien « Restauration » de l'exploitation, pour le
 * seul propriétaire : MetricsAccess, car elle remplace toute la base). Le compte n'est interrogé
 * que pour un gabarit qui porte le rôle administrateur ; ce n'est qu'un confort d'affichage, la page reste protégée par son contrôleur.
 */
final class OpsLinkRenderer implements TemplateRendererInterface
{
    public function __construct(
        private readonly TemplateRendererInterface $inner,
        private readonly AuthGuard $authGuard,
        private readonly OperationsAccess $operations,
        private readonly MetricsAccess $owner,
    ) {
    }

    public function render(string $template, array $data = []): string
    {
        if (($data['currentUserRole'] ?? null) === UserRole::Admin) {
            $user = $this->authGuard->currentUserOrNull();
            $data['showOpsLink'] = $this->operations->allows($user);
            $data['showRestoreLink'] = $this->owner->allows($user);
        }

        return $this->inner->render($template, $data);
    }
}
