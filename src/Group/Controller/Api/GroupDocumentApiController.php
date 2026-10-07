<?php

declare(strict_types=1);

namespace App\Group\Controller\Api;

use App\Group\Entity\GroupDocument;
use App\Http\FileResponse;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Group\Service\GroupDocumentService;
use App\Support\StrictId;

final class GroupDocumentApiController
{
    public function __construct(
        private readonly GroupDocumentService $documentService,
        private readonly AuthGuard $authGuard,
    ) {
    }

    public function store(Request $request, string $groupId): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $file = $request->file('document');
        if ($file === null || $file['error'] !== UPLOAD_ERR_OK) {
            return new JsonResponse(['error' => 'Fichier manquant ou invalide.'], 422);
        }

        $document = $this->documentService->upload(
            StrictId::orDenied($groupId),
            $user->id(),
            $file['tmp_name'],
            $file['name'],
            $file['size'],
        );

        return new JsonResponse(self::toArray($document), 201);
    }

    public function index(Request $request, string $groupId): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $documents = $this->documentService->listForGroup(StrictId::orDenied($groupId), $user->id());

        return new JsonResponse(['documents' => array_map(self::toArray(...), $documents)]);
    }

    /**
     * Téléchargement d'un document (#222). Le fichier vient d'un utilisateur : il est servi comme PIÈCE JOINTE pour les PDF
     * (un PDF actif ne s'ouvre jamais dans l'origine du site) et en ligne pour les images seulement, avec dans tous les cas
     * `nosniff` et une CSP qui interdit tout contenu actif (`sandbox`). Envoyé en flux (jamais chargé en mémoire) ; un fichier
     * absent du disque est un 404, jamais une réponse vide.
     */
    public function download(Request $request, string $id): Response
    {
        $user = $this->authGuard->requireLogin();

        $document = $this->documentService->resolveDownload(StrictId::orDenied($id), $user->id());
        $path = $this->documentService->pathOf($document);
        if (!is_file($path)) {
            error_log(sprintf('Document #%d : fichier absent du stockage.', $document->id()));

            return new JsonResponse(['error' => 'Document introuvable.'], 404);
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';

        return new FileResponse($path, $mimeType, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            'Content-Disposition' => self::disposition($mimeType, $document->originalName()),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        $this->documentService->delete(StrictId::orDenied($id), $user->id());

        return new JsonResponse([], 204);
    }

    /**
     * Pièce jointe pour tout ce qui n'est pas une image (PDF) ; en ligne pour JPEG et PNG, qui n'ont pas de contenu actif. Le nom
     * d'origine vient de l'utilisateur : repli ASCII sans guillemet, antislash ni saut de ligne, et forme RFC 5987 (filename*)
     * encodée pour les accents.
     */
    private static function disposition(string $mimeType, string $originalName): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $originalName) ?? '';
        $fallback = trim($fallback) !== '' ? $fallback : 'document';

        $type = in_array($mimeType, ['image/jpeg', 'image/png'], true) ? 'inline' : 'attachment';

        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $type, $fallback, rawurlencode($originalName));
    }

    /** @return array<string, mixed> */
    private static function toArray(GroupDocument $document): array
    {
        return [
            'id' => $document->id(),
            'originalName' => $document->originalName(),
            'mimeType' => $document->mimeType(),
            'sizeBytes' => $document->sizeBytes(),
        ];
    }
}
