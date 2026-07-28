<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\GroupDocument;
use App\Http\JsonResponse;
use App\Http\Request;
use App\Http\Response;
use App\Security\AuthGuard;
use App\Security\Exception\AccessDeniedException;
use App\Service\Exception\InvalidUploadException;
use App\Service\Exception\StorageQuotaExceededException;
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

        try {
            $document = $this->documentService->upload(
                (int) $groupId,
                $user->id(),
                $file['tmp_name'],
                $file['name'],
                $file['size'],
            );
        } catch (AccessDeniedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        } catch (InvalidUploadException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        } catch (StorageQuotaExceededException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 409);
        }

        return new JsonResponse(self::toArray($document), 201);
    }

    public function index(Request $request, string $groupId): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        try {
            $documents = $this->documentService->listForGroup((int) $groupId, $user->id());
        } catch (AccessDeniedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        }

        return new JsonResponse(['documents' => array_map(self::toArray(...), $documents)]);
    }

    public function download(Request $request, string $id): Response
    {
        $user = $this->authGuard->requireLogin();

        try {
            $path = $this->documentService->resolveDownloadPath((int) $id, $user->id());
        } catch (AccessDeniedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }

        $mimeType = (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';

        return new Response(
            body: (string) file_get_contents($path),
            statusCode: 200,
            headers: ['Content-Type' => $mimeType],
        );
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $user = $this->authGuard->requireLogin();

        try {
            $this->documentService->delete((int) $id, $user->id());
        } catch (AccessDeniedException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 403);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }

        return new JsonResponse([], 204);
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
