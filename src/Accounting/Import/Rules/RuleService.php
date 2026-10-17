<?php

declare(strict_types=1);

namespace App\Accounting\Import\Rules;

use App\Account\Entity\User;
use App\Accounting\Entity\EntryCategory;
use App\Accounting\Entity\PaymentMethod;
use App\Accounting\Exception\AccountingValidationException;
use App\Accounting\Exception\RuleNotFoundException;
use App\Accounting\Repository\Import\LabelRuleRepositoryInterface;
use App\Security\AdminAccess;
use Psr\Log\LoggerInterface;

/**
 * Mémoire des libellés (#362), réservée aux administrateurs actifs : la page des règles liste, change la cible (catégorie et moyen de paiement,
 * jamais le motif ni le sens) et supprime. Les règles se créent à la validation d'une ligne (« Retenir pour la prochaine fois »), pas ici.
 * Le journal ne reçoit que des identifiants.
 */
final class RuleService
{
    public function __construct(
        private readonly AdminAccess $access,
        private readonly LabelRuleRepositoryInterface $rules,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return list<LabelRule> */
    public function list(User $actor): array
    {
        $this->access->assertAdmin($actor);

        return $this->rules->all();
    }

    /**
     * @param array<string, mixed> $input `category`, `paymentMethod`
     * @throws RuleNotFoundException règle inconnue
     * @throws AccountingValidationException
     */
    public function retarget(User $actor, int $id, array $input): void
    {
        $this->access->assertAdmin($actor);
        $rule = $this->rules->find($id) ?? throw new RuleNotFoundException();
        $category = is_string($input['category'] ?? null) ? EntryCategory::tryFrom($input['category']) : null;
        $method = is_string($input['paymentMethod'] ?? null) ? PaymentMethod::tryFrom($input['paymentMethod']) : null;
        $errors = [];
        $errors += $category === null ? ['category' => 'Choisissez une catégorie.'] : [];
        $errors += $method === null ? ['paymentMethod' => 'Choisissez un moyen de paiement.'] : [];
        if ($category !== null && $category->direction() !== $rule->direction) {
            $errors['category'] = 'Cette catégorie ne correspond pas au sens de la règle.';
        }
        if ($errors !== [] || $category === null || $method === null) {
            throw new AccountingValidationException($errors);
        }
        $this->rules->retarget($id, $category, $method);
        $this->logger->info('Comptabilité : règle de libellé modifiée', ['actor' => $actor->id(), 'rule' => $id]);
    }

    public function delete(User $actor, int $id): void
    {
        $this->access->assertAdmin($actor);
        if (!$this->rules->delete($id)) {
            throw new RuleNotFoundException();
        }
        $this->logger->info('Comptabilité : règle de libellé supprimée', ['actor' => $actor->id(), 'rule' => $id]);
    }
}
