<?php
/**
 * Cartes des créneaux exceptionnels de la semaine (#243) : UN gabarit pour la page du tableau de bord et pour le fragment renvoyé
 * après l'acceptation d'une demande (le navigateur n'a plus de copie du balisage). Chaque carte est celle de `_occasional-card`, la
 * même que dans le planning (#461).
 *
 * @var list<\App\Planning\Entity\RequestableSlot> $exceptionalPlanningSlots
 */
foreach ($exceptionalPlanningSlots as $requestableSlot) {
    require __DIR__ . '/_occasional-card.php';
}
