<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GroupDocument;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Service\GroupDocumentService;

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
            (int) $groupId,
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

        $documents = $this->documentService->listForGroup((int) $groupId, $user->id());

        return new JsonResponse(['documents' => array_map(self::toArray(...), $documents)]);
    }

    public function download(Request $request, string $id): Response
    {
        $user = $this->authGuard->requireLogin();

        try {
            $document = $this->documentService->resolveDownload((int) $id, $user->id());
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }

        $path = $this->documentService->pathOf($document);
        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';

        return new Response(
            body: (string) file_get_contents($path),
            statusCode: 200,
            headers: [
                'Content-Type' => $mimeType,
                'X-Content-Type-Options' => 'nosniff',
                'Content-Disposition' => self::inlineDisposition($document->originalName()),
            ],
        );
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        try {
            $this->documentService->delete((int) $id, $user->id());
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }

        return new JsonResponse([], 204);
    }

    /**
     * Affichage en ligne (PDF, JPEG, PNG validés par finfo, avec nosniff : le navigateur ne réinterprète pas le type). Le nom d'origine vient de
     * l'utilisateur : repli ASCII sans guillemet, antislash ni saut de ligne, et
     * forme RFC 5987 (filename*) encodée pour les accents.
     */
    private static function inlineDisposition(string $originalName): string
    {
        $fallback = preg_replace('/[^A-Za-z0-9._ -]+/', '_', $originalName) ?? '';
        $fallback = trim($fallback) !== '' ? $fallback : 'document';

        return sprintf('inline; filename="%s"; filename*=UTF-8\'\'%s', $fallback, rawurlencode($originalName));
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
