<?php

declare(strict_types=1);

namespace App\Accounting\Service;

use App\Account\Entity\User;
use App\Accounting\Entity\FiscalYear;
use App\Accounting\Entity\Statement;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Exception\FiscalYearLockedException;
use App\Accounting\Exception\FiscalYearNotFoundException;
use App\Accounting\Exception\StatementNotFoundException;
use App\Accounting\Repository\FiscalYearRepositoryInterface;
use App\Accounting\Repository\StatementRepositoryInterface;
use App\Database\TransactionRunner;
use App\Group\Exception\StorageQuotaExceededException;
use App\Security\AdminAccess;
use Psr\Log\LoggerInterface;

/**
 * Relevés de compte (#362), réservés aux administrateurs actifs. Un relevé est une PIÈCE ARCHIVÉE : jamais modifié ni supprimé ; déposé
 * par erreur, il est REMPLACÉ par un nouveau (l'ancien reste, marqué « remplacé »), tant que l'exercice est ouvert. Le dépôt se fait sous le
 * verrou de la ligne d'exercice (comme les écritures : plafonds, doublon et gel jugés sans concurrence) ; le fichier est copié AVANT la
 * transaction et retiré si elle échoue : jamais de fichier orphelin. Le journal ne reçoit que des identifiants.
 */
final class StatementService
{
    public const MAX_PER_YEAR = 60;
    public const MAX_BYTES_PER_YEAR = 200 * 1024 * 1024;
    private const NAME_MAX = 150;

    public function __construct(
        private readonly AdminAccess $access,
        private readonly StatementInputPolicy $policy,
        private readonly FiscalYearRepositoryInterface $years,
        private readonly StatementGapCalculator $gaps,
        private readonly StatementRepositoryInterface $statements,
        private readonly StatementFileStore $files,
        private readonly TransactionRunner $transactions,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $input voir StatementInputPolicy::parse
     * @throws FiscalYearNotFoundException
     * @throws FiscalYearLockedException exercice clos
     * @throws AccountingValidationException période, solde, relevé remplacé, fichier refusé ou doublon
     * @throws StorageQuotaExceededException plafond de relevés ou de volume atteint pour l'exercice
     */
    public function deposit(User $actor, int $yearId, array $input, string $uploadedPath, string $originalName, int $declaredSize, ?\DateTimeImmutable $now = null): Statement
    {
        $this->access->assertAdmin($actor);
        $now ??= new \DateTimeImmutable();
        [$start, $end, $balance, $replaces] = $this->policy->parse($input);
        $year = $this->years->find($yearId) ?? throw new FiscalYearNotFoundException();
        if (!$year->isOpen()) {
            throw new FiscalYearLockedException('Cet exercice est clos : les relevés ne changent plus.');
        }
        if ($start < $year->startsOn || $end > $year->endsOn) {
            throw new AccountingValidationException(['periodStart' => 'La période doit tenir dans l\'exercice (du ' . $year->startsOn->format('d/m/Y') . ' au ' . $year->endsOn->format('d/m/Y') . ').']);
        }

        $stored = $this->files->store($uploadedPath, $declaredSize);
        try {
            $id = $this->transactions->run(function () use ($yearId, $replaces, $stored, $originalName, $start, $end, $balance, $actor, $now): int {
                $locked = $this->years->lock($yearId) ?? throw new FiscalYearNotFoundException();
                if (!$locked->isOpen()) {
                    throw new FiscalYearLockedException('Cet exercice est clos : les relevés ne changent plus.');
                }
                $this->assertRoomFor($yearId, $stored->sizeBytes);
                if ($this->statements->hashExists($yearId, $stored->sha256)) {
                    throw new AccountingValidationException(['file' => 'Ce fichier est déjà déposé dans cet exercice.']);
                }
                $replaced = $replaces === null ? null : $this->replaceable($yearId, $replaces);
                $id = $this->statements->insert($yearId, $start, $end, $balance, self::safeName($originalName), $stored->storedName, $stored->mime, $stored->sizeBytes, $stored->sha256, $actor->id(), $now);
                if ($replaced !== null && !$this->statements->markReplaced($replaced, $id)) {
                    throw new AccountingValidationException(['replaces' => 'Ce relevé a déjà été remplacé.']);
                }

                return $id;
            });
        } catch (\Throwable $e) {
            $this->files->remove($stored->storedName);

            throw $e;
        }
        $this->logger->info('Comptabilité : dépôt de relevé', ['actor' => $actor->id(), 'year' => $yearId, 'statement' => $id]);

        return $this->statements->find($id) ?? throw new StatementNotFoundException();
    }

    /** @return list<StatementReview> par période, avec le solde de la banque selon le livre et l'écart avec le relevé */
    public function review(User $actor, int $yearId): array
    {
        $this->access->assertAdmin($actor);
        $year = $this->years->find($yearId) ?? throw new FiscalYearNotFoundException();

        return array_map(fn (Statement $s): StatementReview => $this->gaps->review($year, $s), $this->statements->listByYear($yearId, self::MAX_PER_YEAR));
    }

    public function find(User $actor, int $id): Statement
    {
        $this->access->assertAdmin($actor);

        return $this->statements->find($id) ?? throw new StatementNotFoundException();
    }

    /** Le relevé à servir : 404 si l'identifiant est inconnu OU si son fichier a disparu du stockage (jamais une réponse vide). */
    public function download(User $actor, int $id): Statement
    {
        $statement = $this->find($actor, $id);
        if (!is_file($this->files->pathOf($statement->storedName))) {
            $this->logger->error('Comptabilité : fichier de relevé absent du stockage', ['statement' => $id]);

            throw new StatementNotFoundException();
        }

        return $statement;
    }

    public function pathOf(User $actor, int $id): string
    {
        return $this->files->pathOf($this->find($actor, $id)->storedName);
    }

    /** @throws StorageQuotaExceededException */
    private function assertRoomFor(int $yearId, int $bytes): void
    {
        if ($this->statements->countByYear($yearId) >= self::MAX_PER_YEAR) {
            throw new StorageQuotaExceededException('Nombre maximum de relevés atteint pour cet exercice.');
        }
        if ($this->statements->totalBytesByYear($yearId) + $bytes > self::MAX_BYTES_PER_YEAR) {
            throw new StorageQuotaExceededException('Le volume maximal de relevés est atteint pour cet exercice.');
        }
    }

    /** @throws AccountingValidationException le relevé n'existe pas dans CET exercice ou a déjà été remplacé */
    private function replaceable(int $yearId, int $replaces): int
    {
        $old = $this->statements->find($replaces);
        if ($old === null || $old->fiscalYearId !== $yearId || $old->isReplaced()) {
            throw new AccountingValidationException(['replaces' => 'Ce relevé ne peut pas être remplacé.']);
        }

        return $old->id;
    }

    /**
     * Nom d'origine tel qu'il sera conservé et affiché : sans chemin, sans caractère de contrôle ni de format (dont l'inversion du sens
     * d'écriture), borné en longueur avec son extension. Il ne sert jamais à construire un chemin sur le disque.
     */
    private static function safeName(string $originalName): string
    {
        $name = (string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $originalName);
        $name = trim((string) preg_replace('~^.*[\\\\/]~u', '', $name));
        if ($name === '' || trim($name, '.') === '') {
            return 'releve';
        }
        if (mb_strlen($name) <= self::NAME_MAX) {
            return $name;
        }
        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = $extension !== '' && mb_strlen($extension) <= 10 ? '.' . $extension : '';

        return mb_substr($name, 0, self::NAME_MAX - mb_strlen($suffix)) . $suffix;
    }
}
