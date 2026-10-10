<?php

declare(strict_types=1);

// Rattrapage du chiffrement des messages (#171) : php bin/encrypt-messages.php [--dry-run]
// Chiffre le texte de la messagerie écrit avant le chiffrement (messages, anciennes versions, titres) et, après une rotation de
// clé, réécrit avec la clé courante ce qui l'est avec une ancienne. Idempotent, sans risque d'écraser un message corrigé pendant
// le passage. À lancer APRÈS la sauvegarde de la base (le déploiement la fait) ; --dry-run ne fait que compter.
// Ne touche que le texte de la messagerie de CETTE application : aucun fichier, aucun autre texte. Ne s'affiche jamais un texte ni une clé.

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

require __DIR__ . '/../vendor/autoload.php';

use App\Database\ConnectionFactory;
use App\Messaging\Crypto\MessageCipherException;
use App\Messaging\Crypto\MessageKeyFile;
use App\Messaging\Crypto\MessageReencryptor;

$config = require __DIR__ . '/../config/config.php';

try {
    $cipher = (new MessageKeyFile($config['messages']['key_file']))->cipher();
    $reencryptor = new MessageReencryptor((new ConnectionFactory($config['db']))->create(), $cipher);

    if (in_array('--dry-run', $argv, true)) {
        echo $reencryptor->pending() . " valeur(s) à chiffrer ou à réécrire (essai à blanc : rien n'a été modifié).\n";
        exit(0);
    }

    foreach ($reencryptor->run() as $column => $count) {
        echo "{$column} : {$count} valeur(s) réécrite(s).\n";
    }
    echo 'Restent en clair : ' . $reencryptor->countPlaintext() . ". Quand c'est 0 : php bin/message-keys.php strict\n";
} catch (MessageCipherException $e) {
    fwrite(STDERR, 'Arrêt : ' . $e->getMessage() . "\n");
    exit(1);
}
