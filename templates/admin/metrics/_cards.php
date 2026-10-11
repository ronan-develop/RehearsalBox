<?php
/**
 * Grille de cartes d'état, partagée par les mesures et l'exploitation (#196, #375) : l'état est toujours dit par une icône ET un libellé.
 *
 * @var iterable<\App\Metrics\Report\StatusCard> $cards
 */
?>
<div class="rb-metrics-cards">
    <?php foreach ($cards as $card): ?>
        <div class="rb-metrics-card"<?= $card->status === null ? '' : ' data-status="' . e($card->status->value) . '"' ?>>
            <p class="rb-metrics-card-label"><?= e($card->label) ?></p>
            <p class="rb-metrics-card-value"><?= e($card->value) ?></p>
            <?php if ($card->status !== null): ?>
                <p class="rb-metrics-card-status"><span aria-hidden="true"><?= e($card->status->icon()) ?></span> <?= e($card->status->label()) ?></p>
            <?php endif; ?>
            <?php if ($card->hint !== ''): ?>
                <p class="rb-metrics-card-hint"><?= e($card->hint) ?></p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
