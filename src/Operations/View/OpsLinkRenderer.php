<?php

declare(strict_types=1);

namespace App\Operations\View;

use App\Account\Entity\UserRole;
use App\Metrics\MetricsAccess;
use App\Security\AuthGuard;
use App\View\TemplateRendererInterface;

/**
 * Ajoute à chaque gabarit d'administrateur l'indicateur `showOpsLink` (#400) : le lien « Ops » de la barre du bas n'est montré qu'au
 * propriétaire (MetricsAccess), pas aux autres administrateurs, sans que chaque contrôleur ait à le calculer. Le compte n'est interrogé
 * que pour un gabarit qui porte le rôle administrateur ; ce n'est qu'un confort d'affichage, la page reste protégée par son contrôleur.
 */
final class OpsLinkRenderer implements TemplateRendererInterface
{
    public function __construct(
        private readonly TemplateRendererInterface $inner,
        private readonly AuthGuard $authGuard,
        private readonly MetricsAccess $access,
    ) {
    }

    public function render(string $template, array $data = []): string
    {
        if (($data['currentUserRole'] ?? null) === UserRole::Admin) {
            $data['showOpsLink'] = $this->access->allows($this->authGuard->currentUserOrNull());
        }

        return $this->inner->render($template, $data);
    }
}
