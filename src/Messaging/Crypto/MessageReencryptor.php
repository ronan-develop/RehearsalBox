<?php

declare(strict_types=1);

namespace App\Messaging\Crypto;

/**
 * Rattrapage du chiffrement (#171) : chiffre le texte de la messagerie écrit AVANT le chiffrement (corps des messages, anciennes
 * versions corrigées, titres) et, après une rotation de clé, réécrit avec la clé courante ce qui l'est avec une ancienne.
 * Idempotent : une valeur déjà chiffrée avec la clé courante n'est pas touchée. Chaque réécriture est une comparaison-et-échange
 * (`WHERE col = ancienne valeur`) : un message corrigé pendant le passage n'est jamais écrasé par son ancien texte.
 * Ne touche que ces trois colonnes de la base de l'application : aucun fichier, aucun autre texte.
 */
final class MessageReencryptor
{
    private const BATCH = 200;

    /** @var array<string, array{string, string}> libellé => [table, colonne] ; chaque table a une colonne `id` */
    private const TARGETS = [
        'conversation_messages.body' => ['conversation_messages', 'body'],
        'conversation_message_versions.body' => ['conversation_message_versions', 'body'],
        'conversations.title' => ['conversations', 'title'],
    ];

    public function __construct(private readonly \PDO $pdo, private readonly MessageCipher $cipher)
    {
    }

    /**
     * @return array<string, int> nombre de valeurs réécrites par colonne
     *
     * @throws MessageCipherException valeur non lisible (clair refusé hors transition, valeur altérée, clé inconnue) : le passage s'arrête
     */
    public function run(): array
    {
        $report = [];
        foreach (self::TARGETS as $label => [$table, $column]) {
            $report[$label] = $this->rewrite($table, $column);
        }

        return $report;
    }

    /**
     * Preuve que les clés LISENT ce qui est stocké : déchiffre les valeurs chiffrées les plus récentes de chaque colonne. Lancé par le
     * déploiement avant de basculer une release : une clé perdue ou fausse l'arrête au lieu de rendre les messages illisibles.
     *
     * @return int nombre de valeurs déchiffrées (le clair d'avant le chiffrement est ignoré)
     *
     * @throws MessageCipherException une valeur ne se déchiffre pas (le message nomme la colonne et l'identifiant, jamais le texte)
     */
    public function verifyReadable(int $sample = 50): int
    {
        $verified = 0;
        foreach (self::TARGETS as $label => [$table, $column]) {
            $statement = $this->pdo->prepare("SELECT id, `{$column}` AS value FROM `{$table}` WHERE `{$column}` IS NOT NULL ORDER BY id DESC LIMIT " . max(1, $sample));
            $statement->execute();
            foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                if (!$this->cipher->isEncrypted((string) $row['value'])) {
                    continue;
                }
                try {
                    $this->cipher->decrypt((string) $row['value']);
                } catch (MessageCipherException) {
                    throw new MessageCipherException("Lecture impossible : {$label} #{$row['id']} (clé perdue ou incorrecte ?).");
                }
                ++$verified;
            }
        }

        return $verified;
    }

    /** Valeurs qu'un passage réécrirait (clair ou ancienne clé), sans rien écrire : pour un essai à blanc. */
    public function pending(): int
    {
        return $this->countWhere(fn (string $stored): bool => !$this->cipher->isCurrent($stored));
    }

    /** Valeurs qui ne sont pas (encore) chiffrées : à zéro, on peut mettre fin à la transition. */
    public function countPlaintext(): int
    {
        return $this->countWhere(fn (string $stored): bool => !$this->cipher->isEncrypted($stored));
    }

    /** @param \Closure(string): bool $matches */
    private function countWhere(\Closure $matches): int
    {
        $count = 0;
        foreach (self::TARGETS as [$table, $column]) {
            $afterId = 0;
            while (($rows = $this->batch($table, $column, $afterId)) !== []) {
                foreach ($rows as $row) {
                    $afterId = (int) $row['id'];
                    $count += $matches((string) $row['value']) ? 1 : 0;
                }
            }
        }

        return $count;
    }

    private function rewrite(string $table, string $column): int
    {
        $update = $this->pdo->prepare("UPDATE `{$table}` SET `{$column}` = :new WHERE id = :id AND `{$column}` = :old");
        $rewritten = 0;
        $afterId = 0;
        while (($rows = $this->batch($table, $column, $afterId)) !== []) {
            foreach ($rows as $row) {
                $afterId = (int) $row['id'];
                $stored = (string) $row['value'];
                if ($this->cipher->isCurrent($stored)) {
                    continue;
                }
                $update->execute(['new' => $this->cipher->encrypt($this->cipher->decrypt($stored)), 'id' => $afterId, 'old' => $stored]);
                $rewritten += $update->rowCount();
            }
        }

        return $rewritten;
    }

    /** @return list<array{id: int|string, value: string}> les valeurs non nulles suivantes (noms de table et de colonne : constantes ci-dessus, jamais une entrée) */
    private function batch(string $table, string $column, int $afterId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT id, `{$column}` AS value FROM `{$table}` WHERE id > :after AND `{$column}` IS NOT NULL ORDER BY id LIMIT " . self::BATCH
        );
        $statement->execute(['after' => $afterId]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
