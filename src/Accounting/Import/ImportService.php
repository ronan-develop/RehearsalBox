<?php

declare(strict_types=1);

namespace App\Accounting\Import;

use App\Account\Entity\User;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Import\Parse\CsvImportParser;
use App\Accounting\Import\Parse\CsvProfile;
use App\Accounting\Import\Parse\ImportProfilePolicy;
use App\Accounting\Import\Parse\ParseResult;
use App\Accounting\Repository\Import\ImportRepositoryInterface;
use App\Database\TransactionRunner;
use App\Security\AdminAccess;
use Psr\Log\LoggerInterface;

/**
 * Import du CSV de la banque (#362), réservé aux administrateurs actifs. Le fichier est lu d'après un profil de colonnes choisi par le
 * trésorier, montré en APERÇU, puis mis en SALLE D'ATTENTE : rien n'entre dans le livre sans validation. Une ligne déjà vue (même date, montant,
 * sens et libellé) n'est jamais remise en attente ; un même fichier ne s'importe qu'une fois. Le fichier lui-même n'est pas conservé, les
 * lignes sont purgées après 30 jours, le journal ne reçoit que des identifiants et des compteurs (jamais un libellé).
 */
final class ImportService
{
    public const RETENTION = '-30 days';
    public const HASH_RETENTION = '-400 days';
    private const PENDING_LIMIT = 500;

    public function __construct(
        private readonly AdminAccess $access,
        private readonly ImportProfilePolicy $profiles,
        private readonly CsvImportParser $parser,
        private readonly ImportRepositoryInterface $imports,
        private readonly TransactionRunner $transactions,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** Le dernier profil utilisé, proposé à l'import suivant. */
    public function savedProfile(User $actor): ?CsvProfile
    {
        $this->access->assertAdmin($actor);

        return $this->imports->profile();
    }

    /**
     * Essai à blanc : ce que le fichier donnerait avec ce profil, sans rien écrire.
     *
     * @param array<string, mixed> $profileInput voir ImportProfilePolicy::parse
     * @throws AccountingValidationException profil invalide
     */
    public function preview(User $actor, string $content, array $profileInput): ParseResult
    {
        $this->access->assertAdmin($actor);

        return $this->parser->parse($content, $this->profiles->parse($profileInput));
    }

    /**
     * @param array<string, mixed> $profileInput voir ImportProfilePolicy::parse
     * @return array{batchId: int, staged: int, duplicates: int, errors: int}
     * @throws AccountingValidationException profil invalide, fichier illisible, sans aucune ligne lisible, ou déjà importé
     */
    public function stage(User $actor, string $content, array $profileInput, ?\DateTimeImmutable $now = null): array
    {
        $this->access->assertAdmin($actor);
        $profile = $this->profiles->parse($profileInput);
        $result = $this->parser->parse($content, $profile);
        if ($result->lines === []) {
            throw new AccountingValidationException(['file' => $result->errors[0] ?? 'Aucune ligne lisible : vérifiez les colonnes du profil.']);
        }
        $now ??= new \DateTimeImmutable();

        $summary = $this->transactions->run(function () use ($actor, $content, $profile, $result, $now): array {
            $batch = $this->imports->openBatch(hash('sha256', $content), $actor->id(), $now) ?? throw new AccountingValidationException(['file' => 'Ce fichier a déjà été importé.']);
            $staged = 0;
            $duplicates = 0;
            $ranks = [];
            foreach ($result->lines as $line) {
                $base = LineHasher::hash($line, 0);
                $rank = $ranks[$base] = ($ranks[$base] ?? -1) + 1;
                if (!$this->imports->claimHash(LineHasher::hash($line, $rank), $now)) {
                    ++$duplicates;
                    continue;
                }
                $this->imports->addLine($batch, $line, $now);
                ++$staged;
            }
            $errors = count(array_filter(array_keys($result->errors), static fn (int $k): bool => $k > 0));
            $this->imports->closeBatch($batch, $staged, $duplicates, $errors);
            $this->imports->saveProfile($profile, $actor->id(), $now);

            return ['batchId' => $batch, 'staged' => $staged, 'duplicates' => $duplicates, 'errors' => $errors];
        });
        $this->logger->info('Comptabilité : fichier de la banque mis en attente', ['actor' => $actor->id(), 'batch' => $summary['batchId'], 'staged' => $summary['staged'], 'duplicates' => $summary['duplicates']]);

        return $summary;
    }

    /** @return list<ImportLine> */
    public function pending(User $actor): array
    {
        $this->access->assertAdmin($actor);

        return $this->imports->pending(self::PENDING_LIMIT);
    }

    /** Écarte une ligne en attente (elle ne reviendra pas : son empreinte est gardée). Faux si elle n'était plus en attente. */
    public function discard(User $actor, int $lineId, ?\DateTimeImmutable $now = null): bool
    {
        $this->access->assertAdmin($actor);

        return $this->imports->decide($lineId, ImportLine::DISCARDED, null, $now ?? new \DateTimeImmutable());
    }

    /** Purge (tâche planifiée) : les lignes de plus de 30 jours, et les empreintes de plus de 400 jours. */
    public function purgeExpired(\DateTimeImmutable $now): int
    {
        $deleted = $this->imports->purge($now->modify(self::RETENTION), $now->modify(self::HASH_RETENTION));
        $this->logger->info('Comptabilité : lignes de la banque purgées', ['deleted' => $deleted]);

        return $deleted;
    }
}
