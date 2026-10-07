<?php

declare(strict_types=1);

namespace App\Logging;

use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

/**
 * Journal applicatif PSR-3 (#193) : une ligne par évènement dans un fichier hors de la racine web, rotation par taille, niveau
 * minimal configurable. Volontairement minuscule (pas de Monolog sur un mutualisé : un seul fichier, un seul format).
 *
 * Règles de confidentialité appliquées ICI, pas laissées aux appelants : le contexte ne garde que des valeurs scalaires (un
 * objet est réduit à sa classe, un tableau est ignoré) et aucun retour à la ligne ne peut forger une fausse ligne. Les
 * appelants ne passent de toute façon que des identifiants numériques et des classes d'exceptions, jamais d'adresse ni de texte.
 * Une panne d'écriture (disque plein, droits) ne fait JAMAIS échouer la requête.
 */
final class FileLogger extends AbstractLogger
{
    private const SEVERITY = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /**
     * @param int $maxBytes taille à partir de laquelle le fichier est archivé
     * @param int $keep     nombre de fichiers archivés conservés (app.log.1 … app.log.N)
     */
    public function __construct(
        private readonly string $file,
        private readonly ClockInterface $clock,
        private readonly string $minLevel = LogLevel::WARNING,
        private readonly int $maxBytes = 1_000_000,
        private readonly int $keep = 5,
    ) {
        if (!isset(self::SEVERITY[$minLevel])) {
            throw new InvalidArgumentException('Niveau de journal inconnu : ' . $minLevel);
        }
    }

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (!is_string($level) || !isset(self::SEVERITY[$level])) {
            throw new InvalidArgumentException('Niveau de journal inconnu.');
        }
        if (self::SEVERITY[$level] < self::SEVERITY[$this->minLevel]) {
            return;
        }

        $line = sprintf('%s %s %s', $this->clock->now()->format(\DATE_ATOM), strtoupper($level), self::oneLine((string) $message));
        $json = self::contextJson($context);
        if ($json !== '') {
            $line .= ' ' . $json;
        }

        try {
            $this->write($line . "\n");
        } catch (\Throwable) {
            // Le journal est un auxiliaire : sa panne ne doit jamais devenir celle du site.
        }
    }

    private function write(string $line): void
    {
        $dir = dirname($this->file);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return;
        }
        $this->rotateIfNeeded();
        @file_put_contents($this->file, $line, \FILE_APPEND | \LOCK_EX);
    }

    private function rotateIfNeeded(): void
    {
        clearstatcache(true, $this->file);
        if (!is_file($this->file) || filesize($this->file) < $this->maxBytes) {
            return;
        }
        // app.log.N est écrasé, chaque archive vieillit d'un cran, puis le fichier courant devient app.log.1.
        for ($i = $this->keep; $i >= 1; --$i) {
            $from = $i === 1 ? $this->file : $this->file . '.' . ($i - 1);
            if (is_file($from)) {
                @rename($from, $this->file . '.' . $i);
            }
        }
    }

    /** @param array<array-key, mixed> $context */
    private static function contextJson(array $context): string
    {
        $kept = [];
        foreach ($context as $key => $value) {
            $name = self::oneLine((string) $key);
            if (is_scalar($value) || $value === null) {
                $kept[$name] = is_string($value) ? self::oneLine($value) : $value;
            } elseif (is_object($value)) {
                $kept[$name] = $value::class;
            }
        }

        return $kept === [] ? '' : (string) json_encode($kept, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    private static function oneLine(string $text): string
    {
        return str_replace(["\r", "\n"], ' ', $text);
    }
}
