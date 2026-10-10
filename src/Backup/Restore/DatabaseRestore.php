<?php

declare(strict_types=1);

namespace App\Backup\Restore;

use App\Backup\BackupException;
use App\Backup\DumpVerifier;
use Symfony\Component\Clock\ClockInterface;

/**
 * Restauration COMPLÈTE de la base depuis un dump du dossier de sauvegarde (#241). Rien n'est détruit avant que tout ait été vérifié :
 *  1. confirmation = nom exact de la base de production (sinon rien ne bouge) ; une seule restauration à la fois (verrou) ;
 *  2. le dump doit être dans le catalogue (nom exact, jamais un chemin) et passer le DumpVerifier ;
 *  3. essai à blanc dans la base temporaire (vide exigée, vidée ensuite) : un import impossible est découvert sans toucher la production ;
 *  4. dump de l'état ACTUEL (« retour du retour ») : s'il échoue, la production n'est pas touchée ;
 *  5. seulement alors : suppression des tables de la production, import, contrôle du nombre de tables.
 * Si l'étape 5 échoue, le message nomme le dump de sécurité à remettre. Chaque étape est écrite dans RestoreStatus.
 * Les migrations manquantes et le contrôle des clés de chiffrement relèvent du script appelant (bin/restore-db.php).
 */
final class DatabaseRestore
{
    /**
     * @param \Closure(string): string          $safetyBackup crée le dump de l'état actuel pour l'étiquette reçue, renvoie son nom de fichier
     * @param \Closure(string, string): void    $importer     importe le dump (chemin) dans le schéma reçu ; lève BackupException
     * @param string|null                       $scratchSchema base temporaire pour l'essai à blanc ; null = pas d'essai
     * @param \Closure(): string                $followUp     suites (migrations, clés, documents) lancées dans le verrou une fois l'import réussi, sous la même base ; renvoie les notes à afficher
     */
    public function __construct(
        private readonly BackupCatalog $catalog,
        private readonly DumpVerifier $verifier,
        private readonly \Closure $safetyBackup,
        private readonly \Closure $importer,
        private readonly SchemaTools $schemas,
        private readonly RestoreStatus $status,
        private readonly string $productionSchema,
        private readonly ?string $scratchSchema,
        private readonly ClockInterface $clock,
        private readonly \Closure $followUp,
    ) {
    }

    /**
     * @return array{safetyBackup: string, tables: int, rehearsed: bool, notes: string}
     *
     * @throws BackupException
     */
    public function restore(string $file, string $confirmation): array
    {
        if ($confirmation !== $this->productionSchema) {
            throw new BackupException('Confirmation absente ou fausse : saisir le nom exact de la base pour restaurer.');
        }
        if (!$this->status->acquire()) {
            throw new BackupException('Une restauration est déjà en cours.');
        }

        $safety = '';
        try {
            $this->status->begin($file);

            return $this->run($file, $safety);
        } catch (\Throwable $e) {
            $message = $e instanceof BackupException ? $e->getMessage() : 'Erreur inattendue pendant la restauration.';
            $this->status->fail($message, $safety);
            throw $e instanceof BackupException ? $e : new BackupException($message, 0, $e);
        } finally {
            $this->status->release();
        }
    }

    /** @return array{safetyBackup: string, tables: int, rehearsed: bool, notes: string} */
    private function run(string $file, string &$safety): array
    {
        $entry = $this->catalog->find($file) ?? throw new BackupException('Sauvegarde inconnue : elle doit figurer dans le dossier de sauvegarde.');
        $path = $this->catalog->pathOf($entry);

        $this->status->step('verify');
        $tables = $this->verifier->verify($path);

        $rehearsed = $this->rehearse($path, $tables);

        $this->status->step('safety-backup');
        $safety = ($this->safetyBackup)($this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('YmdHis') . '-avant-restauration');

        try {
            $this->status->step('drop');
            $this->schemas->dropAllTables($this->productionSchema);
            $this->status->step('import');
            ($this->importer)($path, $this->productionSchema);
            $imported = count($this->schemas->tables($this->productionSchema));
            if ($imported !== $tables) {
                throw new BackupException("Import incomplet ({$imported} tables sur {$tables}).");
            }
        } catch (\Throwable $e) {
            $reason = $e instanceof BackupException ? $e->getMessage() : 'Erreur inattendue.';
            throw new BackupException("{$reason} La production a été vidée : restaurer le dump de sécurité {$safety} pour revenir à l'état d'avant.", 0, $e);
        }

        $this->status->step('follow-up');
        $notes = ($this->followUp)();
        $this->status->finish($safety, $notes);

        return ['safetyBackup' => $safety, 'tables' => $tables, 'rehearsed' => $rehearsed, 'notes' => $notes];
    }

    /** Essai à blanc ; renvoie false s'il n'y a pas de base temporaire. */
    private function rehearse(string $path, int $expectedTables): bool
    {
        if ($this->scratchSchema === null) {
            return false;
        }
        if (!$this->schemas->isEmpty($this->scratchSchema)) {
            throw new BackupException('La base temporaire n\'est pas vide : elle n\'est pas utilisée (rien n\'y est supprimé). La vider d\'abord si elle ne sert plus.');
        }

        $this->status->step('rehearsal');
        try {
            ($this->importer)($path, $this->scratchSchema);
            $imported = count($this->schemas->tables($this->scratchSchema));
            if ($imported !== $expectedTables) {
                throw new BackupException("Essai à blanc incomplet ({$imported} tables sur {$expectedTables}) : la production n'a pas été touchée.");
            }
        } finally {
            $this->schemas->dropAllTables($this->scratchSchema);
        }

        return true;
    }
}
