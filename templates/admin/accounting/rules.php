<?php
/**
 * Règles des libellés (#362) : la mémoire explicite de l'import. Un libellé de banque qui contient le motif reçoit cette catégorie et ce moyen de
 * paiement (une proposition, jamais une décision). Visibles, modifiables, supprimables. Tout texte est échappé ; aucun style ni script en ligne.
 *
 * @var string $csrfToken
 * @var array<string, mixed> $page voir ImportView::rules
 * @var \App\Account\Entity\UserRole $currentUserRole
 */
$adminTab = 'accounting';
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <title>Admin — Règles des libellés — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
    <link rel="stylesheet" href="/assets/css/pages/accounting.css">
</head>
<body>
    <div class="rb-admin-page">
        <?php $backHref = '/admin/accounting/imports'; $backLabel = 'Retour à l\'import'; require __DIR__ . '/../../partials/back-button.php'; ?>
        <?php require __DIR__ . '/../../partials/admin-tabs.php'; ?>
        <h1>Règles des libellés</h1>
        <p class="rb-admin-subtitle">Quand vous cochez « Retenir pour la prochaine fois » en validant une ligne, une règle est créée ici : un libellé de banque qui contient ce motif reçoit la même catégorie. Rien d'automatique : l'import ne fait que proposer.</p>

        <section data-rules>
            <?php if ($page['rules'] === []): ?>
                <p class="rb-admin-note">Aucune règle retenue pour l'instant.</p>
            <?php else: ?>
                <ul class="rb-entry-list">
                    <?php foreach ($page['rules'] as $rule): ?>
                        <li class="rb-card rb-entry" data-rule data-rule-id="<?= e((string) $rule['id']) ?>">
                            <p class="rb-entry-label">Un libellé qui contient « <?= e($rule['pattern']) ?> » <span class="rb-entry-note">(<?= e($rule['directionLabel']) ?>)</span></p>
                            <div class="rb-field">
                                <label for="rule-category-<?= e((string) $rule['id']) ?>">Catégorie</label>
                                <select id="rule-category-<?= e((string) $rule['id']) ?>" class="rb-input" data-field="category">
                                    <?php foreach ($rule['categories'] as $option): ?>
                                        <option value="<?= e($option['value']) ?>"<?= $rule['category'] === $option['value'] ? ' selected' : '' ?>><?= e($option['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="rb-field-error" role="alert" data-field-error="category" hidden></p>
                            </div>
                            <div class="rb-field">
                                <label for="rule-method-<?= e((string) $rule['id']) ?>">Moyen de paiement</label>
                                <select id="rule-method-<?= e((string) $rule['id']) ?>" class="rb-input" data-field="paymentMethod">
                                    <?php foreach ($page['methods'] as $option): ?>
                                        <option value="<?= e($option['value']) ?>"<?= $rule['method'] === $option['value'] ? ' selected' : '' ?>><?= e($option['label']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="rb-field-error" role="alert" data-field-error="paymentMethod" hidden></p>
                            </div>
                            <p class="rb-field-error" role="alert" data-form-error hidden></p>
                            <div class="rb-entry-actions">
                                <button type="button" class="rb-btn rb-btn-primary" data-action="save-rule">Enregistrer</button>
                                <button type="button" class="rb-btn rb-btn-danger" data-action="delete-rule">Supprimer la règle</button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    </div>

    <?php require __DIR__ . '/../../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
