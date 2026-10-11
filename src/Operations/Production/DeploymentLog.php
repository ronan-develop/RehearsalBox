<?php

declare(strict_types=1);

namespace App\Operations\Production;

use App\Operations\BoundedFile;

/**
 * Lecture du journal des déploiements `shared/deployments.jsonl` (#396), écrit par bin/deploy.sh. Seuls les 64 Ko de fin sont lus ;
 * une ligne tronquée, invalide ou dont un champ sort de son motif est ignorée (jamais une exception) ; fichier absent = aucun déploiement.
 */
final class DeploymentLog
{
    public function __construct(private readonly ?string $file)
    {
    }

    /** @return list<Deployment> le plus récent d'abord */
    public function recent(int $limit = 20): array
    {
        $content = BoundedFile::tail($this->file);
        if ($content === null) {
            return [];
        }

        $deployments = [];
        foreach (array_reverse(explode("\n", $content)) as $line) {
            $deployment = $this->parse($line);
            if ($deployment !== null) {
                $deployments[] = $deployment;
            }
            if (count($deployments) >= $limit) {
                break;
            }
        }

        return $deployments;
    }

    private function parse(string $line): ?Deployment
    {
        $data = $line === '' ? null : json_decode($line, true, 3);
        if (!is_array($data) || ($data['v'] ?? null) !== 1) {
            return null;
        }
        [$release, $commit, $started, $outcome, $checks] = [$data['release'] ?? null, $data['commit'] ?? null, $data['startedAt'] ?? null, $data['outcome'] ?? null, $data['checks'] ?? null];
        $duration = $data['durationS'] ?? null;
        $switched = $data['switched'] ?? null;
        if (
            !is_string($release) || preg_match('/^\d{14}-[0-9a-f]{7,40}$/', $release) !== 1
            || !is_string($commit) || preg_match('/^[0-9a-f]{7,40}$/', $commit) !== 1
            || !is_string($started) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $started) !== 1
            || !is_int($duration) || $duration < 0 || $duration > 999999
            || !in_array($outcome, ['ok', 'failed'], true)
            || !is_bool($switched)
            || !in_array($checks, ['ci', 'local', 'skipped'], true)
        ) {
            return null;
        }
        $at = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $started, new \DateTimeZone('UTC'));
        if ($at === false || $at->format('Y-m-d\TH:i:s\Z') !== $started) {
            return null;
        }

        return new Deployment($release, $commit, $at, $duration, $outcome === 'ok', $switched, $checks);
    }
}
