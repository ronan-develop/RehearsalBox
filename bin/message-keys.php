<?php

declare(strict_types=1);

// Clés du chiffrement des messages (#171) : php bin/message-keys.php <init|status|check|export|import|rotate|transition|strict>
//   init    crée le fichier de clés (une seule fois, jamais écrasé) ; en production, le déploiement le fait au premier passage
//   status  clé courante, noms des clés, transition ou strict (jamais une clé)
//   check   prouve que les clés lisent ce qui est en base (lancé par le déploiement AVANT la bascule : un échec l'arrête)
//   export  affiche le fichier COMPLET : à ranger hors du serveur (KeePass), sans lui les messages sont perdus
//   import  restaure une sauvegarde depuis l'entrée standard (serveur sans fichier de clés)
//   rotate  ajoute une clé et la rend courante, puis lancer bin/encrypt-messages.php pour réécrire les messages
//   transition  rouvre la transition (le clair d'avant redevient lisible) : après la restauration d'un dump d'AVANT le chiffrement ;
//           puis bin/encrypt-messages.php, puis strict (voir .claude/deploiement.md, « Restaurer la base »)
//   strict  met fin à la transition (refusé tant qu'un texte est encore en clair en base)
// Le fichier (config/message-keys.json, lien vers shared/ en production) est hors dépôt, hors base, en 0600.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

use App\Database\ConnectionFactory;
use App\Messaging\Crypto\MessageKeyFile;
use App\Messaging\Crypto\MessageKeysCommand;
use App\Messaging\Crypto\MessageReencryptor;

$config = require __DIR__ . '/../config/config.php';
$file = new MessageKeyFile($config['messages']['key_file']);

$reencryptor = static fn (): MessageReencryptor => new MessageReencryptor((new ConnectionFactory($config['db']))->create(), $file->cipher());
$command = new MessageKeysCommand(
    $file,
    static fn (): int => $reencryptor()->countPlaintext(),
    static fn (): int => $reencryptor()->verifyReadable(),
);

[$code, $output] = $command->run($argv[1] ?? '', ($argv[1] ?? '') === 'import' ? (string) stream_get_contents(STDIN) : '');
fwrite($code === 0 ? STDOUT : STDERR, $output);
exit($code);
