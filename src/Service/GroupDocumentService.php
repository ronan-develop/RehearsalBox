<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Enum\GroupUserRole;
use App\Entity\GroupDocument;
use App\Repository\Contract\GroupDocumentRepositoryInterface;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Security\Exception\AccessDeniedException;
use App\Service\Exception\InvalidUploadException;
use App\Service\Exception\StorageQuotaExceededException;

final class GroupDocumentService
{
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024;

    /** @var array<string, string> */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function __construct(
        private readonly GroupDocumentRepositoryInterface $documentRepository,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly string $storagePath,
        private readonly int $maxDocumentsPerGroup,
    ) {
    }

    public function upload(int $groupId, int $actorUserId, string $tmpFilePath, string $originalName, int $declaredSize): GroupDocument
    {
        $this->assertActorIsManager($groupId, $actorUserId);

        if ($declaredSize > self::MAX_SIZE_BYTES || filesize($tmpFilePath) > self::MAX_SIZE_BYTES) {
            throw new InvalidUploadException('Le fichier dépasse la taille maximale autorisée (10 Mo).');
        }

        if ($this->documentRepository->countByGroup($groupId) >= $this->maxDocumentsPerGroup) {
            throw new StorageQuotaExceededException('Nombre maximum de documents atteint pour ce groupe.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $realMimeType = $finfo->file($tmpFilePath);

        if ($realMimeType === false || !isset(self::ALLOWED_MIME_TYPES[$realMimeType])) {
            throw new InvalidUploadException('Type de fichier non autorisé (PDF, JPEG, PNG uniquement).');
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . self::ALLOWED_MIME_TYPES[$realMimeType];
        $destinationPath = $this->storagePath . '/' . $storedName;

        if (!is_dir($this->storagePath) && !mkdir($this->storagePath, 0700, true) && !is_dir($this->storagePath)) {
            throw new InvalidUploadException('Impossible de préparer le stockage des documents.');
        }

        if (!copy($tmpFilePath, $destinationPath)) {
            throw new InvalidUploadException("Échec de l'enregistrement du fichier.");
        }

        return $this->documentRepository->save(new GroupDocument(
            0,
            $groupId,
            $originalName,
            $storedName,
            $realMimeType,
            filesize($destinationPath),
            $actorUserId,
        ));
    }

    /** @return list<GroupDocument> */
    public function listForGroup(int $groupId, int $actorUserId): array
    {
        if (!$this->groupRepository->isMember($groupId, $actorUserId)) {
            throw new AccessDeniedException("Vous n'appartenez pas à ce groupe.");
        }

        return $this->documentRepository->findByGroup($groupId);
    }

    public function resolveDownloadPath(int $documentId, int $actorUserId): string
    {
        $document = $this->documentRepository->findById($documentId);
        if ($document === null) {
            throw new \InvalidArgumentException("Document {$documentId} introuvable.");
        }

        if (!$this->groupRepository->isMember($document->groupId(), $actorUserId)) {
            throw new AccessDeniedException("Vous n'appartenez pas à ce groupe.");
        }

        return $this->storagePath . '/' . $document->storedName();
    }

    public function delete(int $documentId, int $actorUserId): void
    {
        $document = $this->documentRepository->findById($documentId);
        if ($document === null) {
            throw new \InvalidArgumentException("Document {$documentId} introuvable.");
        }

        $this->assertActorIsManager($document->groupId(), $actorUserId);

        $path = $this->storagePath . '/' . $document->storedName();
        if (is_file($path)) {
            unlink($path);
        }

        $this->documentRepository->delete($documentId);
    }

    private function assertActorIsManager(int $groupId, int $actorUserId): void
    {
        if ($this->groupRepository->roleOf($groupId, $actorUserId) !== GroupUserRole::Gestionnaire) {
            throw new AccessDeniedException("Vous n'êtes pas gestionnaire de ce groupe.");
        }
    }
}
