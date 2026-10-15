<?php

declare(strict_types=1);

namespace App\Assembly\Service;

use App\Assembly\Entity\AssemblyKind;
use App\Assembly\Entity\BoardPost;
use App\Assembly\Exception\AssemblyValidationException;
use App\Support\StrictId;

/**
 * Règle de saisie d'un compte rendu d'AG (#361) : tout ce qui entre est validé AVANT la base, et toutes les erreurs sont
 * remontées ensemble, par champ (clés pointées : `attendees.2.name`, `board.tresorier`). Un brouillon presque vide est accepté.
 * Les textes sont seulement rognés et bornés : l'échappement se fait à l'affichage.
 */
final class AssemblyInputPolicy
{
    public function __construct(
        private readonly AssemblyFieldRules $rules = new AssemblyFieldRules(),
        private readonly AssemblyRowsInputPolicy $rows = new AssemblyRowsInputPolicy(),
    ) {
    }

    /**
     * Type et date : tout ce qu'il faut pour ouvrir un brouillon.
     *
     * @param array<string, mixed> $input
     * @return array{0: AssemblyKind, 1: \DateTimeImmutable}
     */
    public function parseCreation(array $input): array
    {
        $errors = [];
        $kind = $this->kind($input, $errors);
        $heldOn = $this->heldOn($input, $errors);
        if ($errors !== [] || $kind === null || $heldOn === null) {
            throw new AssemblyValidationException($errors);
        }

        return [$kind, $heldOn];
    }

    /** @param array<string, mixed> $input JSON décodé du compte rendu */
    public function parse(array $input): AssemblyDraft
    {
        $errors = [];

        $kind = $this->kind($input, $errors);
        $heldOn = $this->heldOn($input, $errors);
        $location = $this->rules->text($input['location'] ?? null, 'location', AssemblyFieldRules::MAX_SHORT, null, $errors);
        $openedAt = $this->rules->time($input['openedAt'] ?? null, 'openedAt', $errors);
        $closedAt = $this->rules->time($input['closedAt'] ?? null, 'closedAt', $errors);
        if ($openedAt !== null && $closedAt !== null && $openedAt >= $closedAt) {
            $this->rules->fail($errors, 'closedAt', 'La clôture doit être après l\'ouverture.');
        }
        $quorumReached = $this->quorum($input['quorumReached'] ?? null, $errors);
        $moralReport = $this->rules->text($input['moralReport'] ?? null, 'moralReport', AssemblyFieldRules::MAX_LONG, AssemblyFieldRules::LINES, $errors);
        $financialReport = $this->rules->text($input['financialReport'] ?? null, 'financialReport', AssemblyFieldRules::MAX_LONG, AssemblyFieldRules::LINES, $errors);
        $otherBusiness = $this->rules->text($input['otherBusiness'] ?? null, 'otherBusiness', AssemblyFieldRules::MAX_LONG, AssemblyFieldRules::LINES, $errors);
        $board = $this->board($input['board'] ?? [], $errors);
        $attendees = $this->rows->attendees($input['attendees'] ?? [], $errors);
        $agenda = $this->rows->agenda($input['agenda'] ?? [], $errors);
        $resolutions = $this->rows->resolutions($input['resolutions'] ?? [], $errors);

        if ($errors !== [] || $kind === null || $heldOn === null) {
            throw new AssemblyValidationException($errors);
        }

        return new AssemblyDraft(
            $kind,
            $heldOn,
            $location,
            $openedAt,
            $closedAt,
            $quorumReached,
            $moralReport,
            $financialReport,
            $otherBusiness,
            $board,
            $attendees,
            $agenda,
            $resolutions,
        );
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private function kind(array $input, array &$errors): ?AssemblyKind
    {
        $value = $input['kind'] ?? null;
        $kind = is_string($value) ? AssemblyKind::tryFrom($value) : null;
        if ($kind === null) {
            $this->rules->fail($errors, 'kind', 'Le type d\'assemblée est ordinaire ou extraordinaire.');
        }

        return $kind;
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, string> $errors
     */
    private function heldOn(array $input, array &$errors): ?\DateTimeImmutable
    {
        $value = $input['heldOn'] ?? null;
        $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->rules->fail($errors, 'heldOn', 'La date de l\'assemblée est obligatoire et doit exister (AAAA-MM-JJ).');

            return null;
        }

        return $date;
    }

    /** @param array<string, string> $errors */
    private function quorum(mixed $value, array &$errors): ?bool
    {
        if ($value === null || is_bool($value)) {
            return $value;
        }
        $this->rules->fail($errors, 'quorumReached', 'Le quorum doit être oui, non ou non renseigné.');

        return null;
    }

    /**
     * @param array<string, string> $errors
     *
     * @return array<string, int> identifiant de compte par valeur de BoardPost
     */
    private function board(mixed $input, array &$errors): array
    {
        if (!is_array($input)) {
            $this->rules->fail($errors, 'board', 'Le bureau doit être décrit poste par poste.');

            return [];
        }

        $userIds = [];
        foreach (BoardPost::cases() as $post) {
            $key = 'board.' . $post->value;
            $raw = $input[$post->value] ?? null;
            if ($raw === null) {
                continue;
            }
            $userId = StrictId::from($raw);
            if ($userId === null) {
                $this->rules->fail($errors, $key, 'Le compte du poste est invalide.');
            } elseif (in_array($userId, $userIds, true)) {
                $this->rules->fail($errors, $key, 'Ce compte occupe déjà un autre poste du bureau.');
            } else {
                $userIds[$post->value] = $userId;
            }
        }

        return $userIds;
    }

}
