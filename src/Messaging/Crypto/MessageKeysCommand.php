<?php

declare(strict_types=1);

namespace App\Messaging\Crypto;

/**
 * Commandes du propriétaire pour le fichier de clés (#171), derrière `bin/message-keys.php` : init, status, check (lancé par le
 * déploiement avant la bascule), export, import, rotate, transition (après la restauration d'un dump d'avant le chiffrement), strict. Ne renvoie jamais une clé hors `export` (la sauvegarde, À CONSERVER hors du serveur) : les messages d'aide, les erreurs
 * et `status` ne contiennent que des noms.
 */
final class MessageKeysCommand
{
    /**
     * @param \Closure(): int $plaintextCount nombre de textes encore en clair en base (lu seulement pour `strict`)
     * @param \Closure(): int $readable       déchiffre un échantillon de la base et rend le nombre de valeurs lues, ou lève
     *                                        MessageCipherException (lu seulement pour `check`)
     */
    public function __construct(
        private readonly MessageKeyFile $file,
        private readonly \Closure $plaintextCount,
        private readonly \Closure $readable,
    ) {
    }

    /** @return array{int, string} code de sortie (0 = succès, 1 = refus, 2 = commande inconnue) et texte à afficher */
    public function run(string $command, string $input = ''): array
    {
        try {
            return match ($command) {
                'init' => $this->init(),
                'status' => $this->status(),
                'export' => [0, $this->file->export()],
                'import' => $this->import($input),
                'rotate' => $this->rotate(),
                'strict' => $this->strict(),
                'transition' => $this->transition(),
                'check' => $this->check(),
                default => [2, "Commande inconnue. Usage : php bin/message-keys.php init|status|check|export|import|rotate|transition|strict\n"],
            };
        } catch (\LogicException|\InvalidArgumentException|MessageCipherException $e) {
            return [1, $e->getMessage() . "\n"];
        }
    }

    /** @return array{int, string} */
    private function init(): array
    {
        $this->file->init();

        return [0, "Fichier de clés créé (droits 600). SAUVEGARDEZ-LE maintenant hors du serveur (KeePass) : sans lui, les messages sont perdus.\n"
            . "  ssh <serveur> 'php <release>/bin/message-keys.php export' > sauvegarde-cles.json   (puis rangez ce fichier dans KeePass)\n"];
    }

    /** @return array{int, string} */
    private function status(): array
    {
        $status = $this->file->status();

        return [0, sprintf(
            "Clé courante : %s. Clés : %s. %s\n",
            $status['current'],
            implode(', ', $status['keys']),
            $status['allowPlaintext'] ? 'Mode transition : le texte en clair d\'avant reste lisible.' : 'Mode strict : le texte en clair est refusé.',
        )];
    }

    /** @return array{int, string} */
    private function import(string $input): array
    {
        $this->file->import($input);

        return [0, "Fichier de clés restauré (droits 600).\n"];
    }

    /** @return array{int, string} */
    private function rotate(): array
    {
        $id = $this->file->rotate();

        return [0, "Nouvelle clé courante : {$id}. Les anciennes restent pour lire.\n"
            . "Réécrivez maintenant les messages avec la nouvelle clé : php bin/encrypt-messages.php (puis sauvegardez à nouveau : export).\n"];
    }

    /** @return array{int, string} */
    private function transition(): array
    {
        $this->file->beginTransition();

        return [0, "Transition rouverte : le texte en clair d'avant le chiffrement est de nouveau lisible.\n"
            . "Étapes suivantes : php bin/encrypt-messages.php (chiffre ce qui est en clair), puis php bin/message-keys.php strict (referme la transition).\n"];
    }

    /** @return array{int, string} */
    private function check(): array
    {
        $this->file->cipher(); // fichier présent, droits 600, trousseau valide
        $verified = ($this->readable)();

        return [0, "Clés vérifiées : {$verified} valeur(s) chiffrée(s) de la base se déchiffrent.\n"];
    }

    /** @return array{int, string} */
    private function strict(): array
    {
        $left = ($this->plaintextCount)();
        if ($left > 0) {
            return [1, "Refusé : {$left} texte(s) encore en clair en base. Lancez d'abord php bin/encrypt-messages.php.\n"];
        }
        $this->file->endTransition();

        return [0, "Transition terminée : le texte en clair est désormais refusé.\n"];
    }
}
