<?php

declare(strict_types=1);

$defaults = [
    'debug' => false,
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'name' => 'rehearsalbox',
        'user' => 'root',
        'password' => '',
    ],
];

$localConfigFile = __DIR__ . '/config.local.php';
if (is_file($localConfigFile)) {
    $local = require $localConfigFile;

    return array_replace_recursive($defaults, $local);
}

return $defaults;
