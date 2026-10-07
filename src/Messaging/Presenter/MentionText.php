<?php

declare(strict_types=1);

namespace App\Messaging\Presenter;

/**
 * Découpe le texte d'un message pour surligner les mentions VALIDÉES par le serveur (#178). Le texte reste du texte
 * brut : les gabarits échappent chaque segment, aucun HTML n'est fabriqué ici. Un « @Nom » absent de la liste des
 * mentions enregistrées n'est pas surligné, et un nom plus long qui commence pareil n'est pas confondu.
 */
final class MentionText
{
    /**
     * @param array<int, string> $labelsByUserId identifiant => « @Nom » enregistré pour ce message
     *
     * @return list<array{text: string, mention: bool, me: bool}>
     */
    public static function segments(string $body, array $labelsByUserId, int $viewerId): array
    {
        if ($body === '') {
            return [];
        }
        if ($labelsByUserId === []) {
            return [['text' => $body, 'mention' => false, 'me' => false]];
        }

        $labels = array_values(array_unique($labelsByUserId));
        usort($labels, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
        $pattern = '/(' . implode('|', array_map(static fn (string $label): string => preg_quote($label, '/'), $labels)) . ')(?![\p{L}\p{N}_])/u';

        $mine = [];
        foreach ($labelsByUserId as $userId => $label) {
            if ($userId === $viewerId) {
                $mine[$label] = true;
            }
        }

        $segments = [];
        foreach (preg_split($pattern, $body, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [$body] as $part) {
            $isMention = in_array($part, $labels, true);
            $segments[] = ['text' => $part, 'mention' => $isMention, 'me' => $isMention && isset($mine[$part])];
        }

        return $segments;
    }
}
