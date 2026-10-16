<?php

declare(strict_types=1);

namespace App\Accounting\Service\Dues;

use App\Account\Entity\User;
use App\Accounting\Entity\FiscalYear;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Repository\EntryRepositoryInterface;
use App\Accounting\Repository\MemberDuesRepositoryInterface;
use App\Security\AdminAccess;
use App\Support\StrictId;
use Psr\Log\LoggerInterface;

/**
 * Cotisations par personne (#362), réservées aux administrateurs actifs : tableau adhérents × mois CALCULÉ à partir du livre (une cotisation
 * non annulée par adhérent et par mois = payée) et dispenses décidées par le trésorier. Un mois payé ne peut pas être dispensé. Aucun nom
 * n'entre dans le journal : seulement des identifiants.
 */
final class CotisationService
{
    private const MEMBER_LIMIT = 300;
    private const MAX_MONTHS = 25;

    public function __construct(
        private readonly AdminAccess $access,
        private readonly EntryRepositoryInterface $entries,
        private readonly MemberDuesRepositoryInterface $members,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function table(User $actor, FiscalYear $year, \DateTimeImmutable $today): CotisationTable
    {
        $this->access->assertAdmin($actor);
        $months = self::monthsOf($year);
        $paid = $this->entries->paidMonths($months[0], $months[count($months) - 1]);
        $exempt = $this->members->exemptions($months[0], $months[count($months) - 1]);
        $reached = $today->modify('first day of this month')->setTime(0, 0);
        $rows = [];
        foreach ($this->members->listActive(self::MEMBER_LIMIT) as $member) {
            $cells = [];
            foreach ($months as $month) {
                $key = $member->id . '|' . $month->format('Y-m-d');
                $cells[$month->format('Y-m')] = match (true) {
                    isset($paid[$key]) => CotisationCell::Payee,
                    isset($exempt[$key]) => CotisationCell::Dispensee,
                    $month <= $reached => CotisationCell::APayer,
                    default => CotisationCell::AVenir,
                };
            }
            $rows[] = ['member' => $member, 'cells' => $cells];
        }

        return new CotisationTable($months, $rows);
    }

    /** @return array<int, string> nom d'affichage des comptes actifs par identifiant (adhérents proposés à la saisie d'une cotisation) */
    public function memberNames(User $actor): array
    {
        $this->access->assertAdmin($actor);
        $names = [];
        foreach ($this->members->listActive(self::MEMBER_LIMIT) as $member) {
            $names[$member->id] = $member->displayName;
        }

        return $names;
    }

    /**
     * @param array<string, mixed> $input `memberUserId`, `periodMonth` (AAAA-MM), `exempt` (vrai pose la dispense, faux la retire)
     * @throws AccountingValidationException compte inconnu, mois invalide, mois déjà payé
     */
    public function setExemption(User $actor, array $input, ?\DateTimeImmutable $now = null): void
    {
        $this->access->assertAdmin($actor);
        $errors = [];
        $memberId = StrictId::from($input['memberUserId'] ?? null);
        $raw = $input['periodMonth'] ?? null;
        $month = is_string($raw) && preg_match('/\A[0-9]{4}-(?:0[1-9]|1[0-2])\z/D', $raw) === 1 && $raw >= '1900-01' && $raw <= '2999-12' ? new \DateTimeImmutable($raw . '-01') : null;
        $exempt = $input['exempt'] ?? null;
        if ($memberId === null || !$this->members->isActiveMember($memberId)) {
            $errors['memberUserId'] = 'Ce compte est introuvable ou désactivé.';
        }
        if ($month === null) {
            $errors['periodMonth'] = 'Choisissez le mois, par exemple 2025-10.';
        }
        if (!is_bool($exempt) && $exempt !== '1' && $exempt !== '0') {
            $errors['exempt'] = 'Indiquez si la dispense est posée ou retirée.';
        }
        if ($errors === [] && $memberId !== null && $month !== null && ($exempt === true || $exempt === '1') && $this->entries->memberMonthPaid($memberId, $month)) {
            $errors['periodMonth'] = 'La cotisation de ce mois est déjà payée : elle ne peut pas être dispensée.';
        }
        if ($errors !== [] || $memberId === null || $month === null) {
            throw new AccountingValidationException($errors);
        }
        $this->members->setExemption($memberId, $month, $exempt === true || $exempt === '1', $actor->id(), $now ?? new \DateTimeImmutable());
        $this->logger->info('Comptabilité : dispense de cotisation modifiée', ['actor' => $actor->id(), 'member' => $memberId]);
    }

    /** @return non-empty-list<\DateTimeImmutable> */
    private static function monthsOf(FiscalYear $year): array
    {
        $months = [];
        $month = $year->startsOn->modify('first day of this month')->setTime(0, 0);
        while ($month <= $year->endsOn && count($months) < self::MAX_MONTHS) {
            $months[] = $month;
            $month = $month->modify('+1 month');
        }

        return $months;
    }
}
