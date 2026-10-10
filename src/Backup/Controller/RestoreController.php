<?php

declare(strict_types=1);

namespace App\Backup\Controller;

use App\Account\Service\CurrentPasswordVerifier;
use App\Backup\BackupException;
use App\Backup\Restore\BackupCatalog;
use App\Backup\Restore\DocumentOrphanReport;
use App\Backup\Restore\RestoreStatus;
use App\Http\ErrorPage;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Response;
use App\Metrics\MetricsAccess;
use App\Security\AuthGuard;
use App\Security\CsrfTokenManager;
use App\View\TemplateRendererInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Restauration de la base depuis la page d'administration (#241). Réservée au PROPRIÉTAIRE (même compte unique que le tableau de bord
 * des mesures, MetricsAccess) : pour tous les autres, y compris les autres administrateurs, la page n'existe pas (404 uniforme).
 * Lancer une restauration exige, en plus de la session : le mot de passe actuel (verrouillage après échecs, comme ailleurs) et le mot
 * « RESTAURER » tapé en toutes lettres. Le travail est fait par un processus détaché (RestoreLauncher) ; l'état se lit dans un fichier.
 */
final class RestoreController
{
    public const CONFIRM_WORD = 'RESTAURER';

    /** @param \Closure(string): void $launch lance bin/restore-db.php pour le nom de sauvegarde reçu ; lève BackupException */
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly AuthGuard $authGuard,
        private readonly MetricsAccess $access,
        private readonly BackupCatalog $catalog,
        private readonly RestoreStatus $status,
        private readonly DocumentOrphanReport $documents,
        private readonly CurrentPasswordVerifier $passwordVerifier,
        private readonly \Closure $launch,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly \DateTimeZone $localTimezone,
        private readonly CsrfTokenManager $csrfTokenManager,
    ) {
    }

    public function page(Request $request): Response
    {
        $user = $this->authGuard->currentUserOrNull();
        if ($user === null || !$this->access->allows($user)) {
            return ErrorPage::response(404);
        }

        return new Response($this->renderer->render('admin/restore/index', [
            'backups' => $this->catalog->list(),
            'state' => $this->status->read(),
            'running' => $this->status->isRunning(),
            'missingFiles' => $this->documents->missingFiles(),
            'orphanFiles' => $this->documents->orphanFiles(),
            'confirmWord' => self::CONFIRM_WORD,
            'csrfToken' => $this->csrfTokenManager->getToken(),
            'currentUserRole' => $user->role(),
            'localTimezone' => $this->localTimezone,
        ]));
    }

    public function start(Request $request): JsonResponse
    {
        $user = $this->authGuard->currentUserOrNull();
        if ($user === null || !$this->access->allows($user)) {
            return new JsonResponse(['error' => 'Introuvable.'], 404);
        }

        $file = $request->body('file');
        $entry = is_string($file) ? $this->catalog->find($file) : null;
        if ($entry === null) {
            return new JsonResponse(['error' => 'Validation échouée', 'fields' => ['file' => 'Sauvegarde introuvable.']], 422);
        }
        if ($request->body('confirmation') !== self::CONFIRM_WORD) {
            return new JsonResponse(['error' => 'Validation échouée', 'fields' => ['confirmation' => 'Saisir « ' . self::CONFIRM_WORD . ' » pour confirmer.']], 422);
        }
        $password = $request->body('password');
        // UserValidationException (mot de passe faux ou compte verrouillé) : traduite en 422 par le noyau.
        $this->passwordVerifier->assertMatches($user, is_string($password) ? $password : '', \DateTimeImmutable::createFromInterface($this->clock->now()));

        if ($this->status->isRunning()) {
            return new JsonResponse(['error' => 'Une restauration est déjà en cours.'], 409);
        }

        // Journal de l'action : qui et quelle sauvegarde (aucun mot de passe, aucune donnée de la base).
        $this->logger->warning('Restauration de la base demandée.', ['user_id' => $user->id(), 'backup' => $entry->file]);
        try {
            ($this->launch)($entry->file);
        } catch (BackupException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }

        return new JsonResponse(['started' => true, 'file' => $entry->file], 202);
    }
}
