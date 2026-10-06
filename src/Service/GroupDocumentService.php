<?php

declare(strict_types=1);

namespace App\Service;

use App\Database\TransactionRunner;
use App\Entity\Enum\GroupUserRole;
use App\Entity\GroupDocument;
use App\Repository\Contract\GroupDocumentRepositoryInterface;
use App\Repository\Contract\GroupRepositoryInterface;
use App\Security\Exception\AccessDeniedException;
use App\Service\Contract\GroupFilesPurgerInterface;
use App\Service\Exception\InvalidUploadException;
use App\Service\Exception\StorageQuotaExceededException;

final class GroupDocumentService implements GroupFilesPurgerInterface
{
    private const MAX_SIZE_BYTES = 10 * 1024 * 1024;
    /** Longueur maximale du nom d'origine conservé (la colonne en accepte 255) : borné avant la base, jamais une erreur SQL. */
    private const MAX_NAME_LENGTH = 150;

    /** @var array<string, string> */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    public function __construct(
        private readonly GroupDocumentRepositoryInterface $documentRepository,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly TransactionRunner $transactions,
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

        try {
            // « Compter puis ajouter » est ATOMIQUE : le groupe est verrouillé le temps de la transaction, donc des envois
            // simultanés ne peuvent pas dépasser le quota.
            return $this->transactions->run(function () use ($groupId, $actorUserId, $originalName, $storedName, $realMimeType, $destinationPath): GroupDocument {
                $this->documentRepository->lockGroupQuota($groupId);
                if ($this->documentRepository->countByGroup($groupId) >= $this->maxDocumentsPerGroup) {
                    throw new StorageQuotaExceededException('Nombre maximum de documents atteint pour ce groupe.');
                }

                return $this->documentRepository->save(new GroupDocument(
                    0,
                    $groupId,
                    self::safeName($originalName),
                    $storedName,
                    $realMimeType,
                    filesize($destinationPath),
                    $actorUserId,
                ));
            });
        } catch (\Throwable $e) {
            // Quota atteint, base indisponible… : le fichier copié ne reste jamais orphelin sur le disque.
            @unlink($destinationPath);

            throw $e;
        }
    }

    /** @return list<GroupDocument> */
    public function listForGroup(int $groupId, int $actorUserId): array
    {
        if (!$this->groupRepository->isMember($groupId, $actorUserId)) {
            throw new AccessDeniedException("Vous n'appartenez pas à ce groupe.");
        }

        return $this->documentRepository->findByGroup($groupId);
    }

    public function resolveDownload(int $documentId, int $actorUserId): GroupDocument
    {
        $document = $this->documentRepository->findById($documentId);
        if ($document === null || !$this->groupRepository->isMember($document->groupId(), $actorUserId)) {
            throw $this->documentAccessDenied();
        }

        return $document;
    }

    public function pathOf(GroupDocument $document): string
    {
        return $this->storagePath . '/' . $document->storedName();
    }

    public function delete(int $documentId, int $actorUserId): void
    {
        $document = $this->documentRepository->findById($documentId);
        if ($document === null || $this->groupRepository->roleOf($document->groupId(), $actorUserId) !== GroupUserRole::Gestionnaire) {
            throw $this->documentAccessDenied();
        }

        // La ligne d'abord, le fichier ensuite : si la base échoue, le fichier est toujours là (jamais une ligne sans fichier).
        $this->documentRepository->delete($documentId);
        $this->remove([$this->pathOf($document)]);
    }

    public function filesOf(int $groupId): array
    {
        return array_map(fn (GroupDocument $document): string => $this->pathOf($document), $this->documentRepository->findByGroup($groupId));
    }

    public function remove(array $paths): void
    {
        foreach ($paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Nom d'origine tel qu'il sera conservé et affiché : sans chemin, sans caractère de contrôle, borné en longueur (jamais
     * coupé au milieu d'un caractère, extension conservée). Il ne sert jamais à construire un chemin sur le disque.
     */
    private static function safeName(string $originalName): string
    {
        $name = (string) preg_replace('/[\x00-\x1F\x7F]+/u', '', $originalName);
        $name = trim((string) preg_replace('~^.*[\\\\/]~u', '', $name));
        if ($name === '' || trim($name, '.') === '') {
            return 'document';
        }
        if (mb_strlen($name) <= self::MAX_NAME_LENGTH) {
            return $name;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = $extension !== '' && mb_strlen($extension) <= 10 ? '.' . $extension : '';

        return mb_substr($name, 0, self::MAX_NAME_LENGTH - mb_strlen($suffix)) . $suffix;
    }

    private function assertActorIsManager(int $groupId, int $actorUserId): void
    {
        if ($this->groupRepository->roleOf($groupId, $actorUserId) !== GroupUserRole::Gestionnaire) {
            throw new AccessDeniedException("Vous n'êtes pas gestionnaire de ce groupe.");
        }
    }

    /** Document interdit et document inexistant : même réponse (IDOR, #118). */
    private function documentAccessDenied(): AccessDeniedException
    {
        return new AccessDeniedException('Accès refusé.');
    }
}
