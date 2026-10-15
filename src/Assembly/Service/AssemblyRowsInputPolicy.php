<?php

declare(strict_types=1);

namespace App\Assembly\Service;

use App\Assembly\Entity\AgendaItem;
use App\Assembly\Entity\Attendance;
use App\Assembly\Entity\Resolution;
use App\Assembly\Entity\ResolutionOutcome;
use App\Support\StrictId;

/** Saisie des lignes d'un compte rendu d'AG (#361) : présences, ordre du jour, résolutions. Erreurs par clés pointées (`attendees.2.name`). */
final class AssemblyRowsInputPolicy
{
    private const MAX_ATTENDEES = 200;
    private const MAX_AGENDA = 30;
    private const MAX_RESOLUTIONS = 30;

    public function __construct(private readonly AssemblyFieldRules $rules = new AssemblyFieldRules())
    {
    }

    /**
     * @param array<string, string> $errors
     *
     * @return list<array{userId: ?int, name: ?string, attendance: Attendance, proxyHolder: ?string}>
     */
    public function attendees(mixed $input, array &$errors): array
    {
        if (!is_array($input) || !array_is_list($input)) {
            $this->rules->fail($errors, 'attendees', 'La liste des présences doit être une liste.');

            return [];
        }
        if (count($input) > self::MAX_ATTENDEES) {
            $this->rules->fail($errors, 'attendees', 'Une assemblée ne peut pas compter plus de ' . self::MAX_ATTENDEES . ' présences.');

            return [];
        }

        $attendees = [];
        $seenUserIds = [];
        foreach ($input as $index => $row) {
            $prefix = 'attendees.' . $index;
            if (!is_array($row)) {
                $this->rules->fail($errors, $prefix . '.name', 'La présence est invalide.');
                continue;
            }

            $rawUserId = $row['userId'] ?? null;
            $userId = $rawUserId === null ? null : StrictId::from($rawUserId);
            if ($rawUserId !== null && $userId === null) {
                $this->rules->fail($errors, $prefix . '.userId', 'Le compte de la présence est invalide.');
            }
            $name = $this->rules->text($row['name'] ?? null, $prefix . '.name', AssemblyFieldRules::MAX_NAME, AssemblyFieldRules::CONTROL, $errors);
            if (($rawUserId !== null) === ($name !== null)) {
                $this->rules->fail($errors, $prefix . '.name', 'Indiquez soit un compte, soit un nom, pas les deux ni aucun.');
            }

            $attendance = is_string($row['attendance'] ?? null) ? Attendance::tryFrom($row['attendance']) : null;
            if ($attendance === null) {
                $this->rules->fail($errors, $prefix . '.attendance', 'La présence est présent, excusé ou représenté.');
            }

            $proxyHolder = $this->rules->text($row['proxyHolder'] ?? null, $prefix . '.proxyHolder', AssemblyFieldRules::MAX_NAME, AssemblyFieldRules::CONTROL, $errors);
            if ($attendance === Attendance::Represente && $proxyHolder === null) {
                $this->rules->fail($errors, $prefix . '.proxyHolder', 'Nommez la personne qui détient le pouvoir.');
            }
            if ($attendance !== null && $attendance !== Attendance::Represente && $proxyHolder !== null) {
                $this->rules->fail($errors, $prefix . '.proxyHolder', 'Un pouvoir ne se saisit que pour une personne représentée.');
            }

            if ($userId !== null) {
                if (in_array($userId, $seenUserIds, true)) {
                    $this->rules->fail($errors, $prefix . '.userId', 'Ce compte est déjà dans les présences.');
                }
                $seenUserIds[] = $userId;
            }

            $attendees[] = ['userId' => $userId, 'name' => $name, 'attendance' => $attendance, 'proxyHolder' => $proxyHolder];
        }

        return $attendees;
    }

    /**
     * @param array<string, string> $errors
     *
     * @return list<AgendaItem>
     */
    public function agenda(mixed $input, array &$errors): array
    {
        if (!is_array($input) || !array_is_list($input)) {
            $this->rules->fail($errors, 'agenda', 'L\'ordre du jour doit être une liste de points.');

            return [];
        }
        if (count($input) > self::MAX_AGENDA) {
            $this->rules->fail($errors, 'agenda', 'Un ordre du jour ne peut pas compter plus de ' . self::MAX_AGENDA . ' points.');

            return [];
        }

        $items = [];
        foreach ($input as $index => $row) {
            $prefix = 'agenda.' . $index;
            if (!is_array($row)) {
                $this->rules->fail($errors, $prefix . '.title', 'Le point est invalide.');
                continue;
            }

            $title = $this->rules->text($row['title'] ?? null, $prefix . '.title', AssemblyFieldRules::MAX_SHORT, null, $errors);
            if ($title === null) {
                $this->rules->fail($errors, $prefix . '.title', 'Le titre du point est obligatoire.');
            }
            $discussion = $this->rules->text($row['discussion'] ?? null, $prefix . '.discussion', AssemblyFieldRules::MAX_LONG, AssemblyFieldRules::LINES, $errors);

            $items[] = new AgendaItem($title ?? '', $discussion);
        }

        return $items;
    }

    /**
     * @param array<string, string> $errors
     *
     * @return list<Resolution>
     */
    public function resolutions(mixed $input, array &$errors): array
    {
        if (!is_array($input) || !array_is_list($input)) {
            $this->rules->fail($errors, 'resolutions', 'Les résolutions doivent être une liste.');

            return [];
        }
        if (count($input) > self::MAX_RESOLUTIONS) {
            $this->rules->fail($errors, 'resolutions', 'Un compte rendu ne peut pas compter plus de ' . self::MAX_RESOLUTIONS . ' résolutions.');

            return [];
        }

        $items = [];
        foreach ($input as $index => $row) {
            $prefix = 'resolutions.' . $index;
            if (!is_array($row)) {
                $this->rules->fail($errors, $prefix . '.text', 'La résolution est invalide.');
                continue;
            }

            $text = $this->rules->text($row['text'] ?? null, $prefix . '.text', AssemblyFieldRules::MAX_LONG, AssemblyFieldRules::LINES, $errors);
            if ($text === null) {
                $this->rules->fail($errors, $prefix . '.text', 'Le texte de la résolution est obligatoire.');
            }
            $for = $this->rules->vote($row['for'] ?? null, $prefix . '.for', $errors);
            $against = $this->rules->vote($row['against'] ?? null, $prefix . '.against', $errors);
            $abstain = $this->rules->vote($row['abstain'] ?? null, $prefix . '.abstain', $errors);
            $outcome = $this->outcome($row['outcome'] ?? null, $prefix . '.outcome', $errors);

            $items[] = new Resolution($text ?? '', $for, $against, $abstain, $outcome);
        }

        return $items;
    }

    /** @param array<string, string> $errors */
    private function outcome(mixed $value, string $key, array &$errors): ?ResolutionOutcome
    {
        if (is_string($value) && trim($value) === '') {
            return null;
        }
        if ($value === null) {
            return null;
        }
        $outcome = is_string($value) ? ResolutionOutcome::tryFrom($value) : null;
        if ($outcome === null) {
            $this->rules->fail($errors, $key, 'Le résultat du vote est adoptée, rejetée ou non renseigné.');
        }

        return $outcome;
    }
}
