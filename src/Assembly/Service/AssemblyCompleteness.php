<?php

declare(strict_types=1);

namespace App\Assembly\Service;

use App\Assembly\Entity\Assembly;
use App\Assembly\Entity\AssemblyContent;
use App\Assembly\Entity\BoardPost;

/** #361 : règle pure, sans base ni session — un compte rendu est validable si rien d'exigé ne manque. */
final class AssemblyCompleteness
{
    /**
     * Les rapports (moral, financier) et les questions diverses sont facultatifs : ils ne figurent jamais ici.
     *
     * @return array<string, string> message français par champ manquant ; vide = validable
     */
    public function missing(Assembly $assembly, AssemblyContent $content): array
    {
        $missing = [];

        if ($assembly->location === null) {
            $missing['location'] = 'Le lieu de l\'assemblée est à renseigner.';
        }
        if ($assembly->openedAt === null) {
            $missing['openedAt'] = 'L\'heure d\'ouverture est à renseigner.';
        }
        if ($assembly->closedAt === null) {
            $missing['closedAt'] = 'L\'heure de clôture est à renseigner.';
        }
        if ($assembly->quorumReached === null) {
            $missing['quorumReached'] = 'Indiquez si le quorum a été atteint.';
        }

        $posts = [
            BoardPost::President,
            BoardPost::Tresorier,
            BoardPost::Secretaire,
        ];
        foreach ($posts as $post) {
            if (!isset($content->board[$post->value])) {
                $missing['board.' . $post->value] = 'Le poste de ' . $post->label() . ' est à pourvoir.';
            }
        }

        if ($content->attendees === []) {
            $missing['attendees'] = 'Ajoutez au moins une présence.';
        }
        if ($content->agenda === []) {
            $missing['agenda'] = 'Ajoutez au moins un point à l\'ordre du jour.';
        }

        foreach ($content->resolutions as $index => $resolution) {
            if ($resolution->outcome === null) {
                $missing['resolutions.' . $index . '.outcome'] = 'Indiquez le résultat du vote de la résolution ' . ($index + 1) . '.';
            }
        }

        return $missing;
    }
}
