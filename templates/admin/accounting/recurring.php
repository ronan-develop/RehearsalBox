<?php
/**
 * Écritures récurrentes (#362) : échéances à confirmer, puis modèles (loyer, électricité…). Rien n'est écrit sans confirmation. Rendu serveur ;
 * les décisions passent par l'API (JS). Tout texte est échappé ici ; aucun style ni script en ligne.
 *
 * @var string $csrfToken
 * @var array<string, mixed> $page voir RecurringView::page
 * @var int|null $yearId
 * @var \App\Account\Entity\UserRole $currentUserRole
 */
$adminTab = 'accounting';
$form = $page['form'];
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <title>Admin — Écritures récurrentes — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
    <link rel="stylesheet" href="/assets/css/pages/accounting.css">
</head>
<body>
    <div class="rb-admin-page">
        <?php $backHref = '/admin/accounting' . ($yearId === null ? '' : '?year=' . $yearId); $backLabel = 'Retour à la comptabilité'; require __DIR__ . '/../../partials/back-button.php'; ?>
        <?php require __DIR__ . '/../../partials/admin-tabs.php'; ?>
        <h1>Écritures récurrentes</h1>
        <p class="rb-admin-subtitle">Le loyer, l'électricité, l'assurance… reviennent à date fixe. Chaque échéance attend ici votre confirmation : rien n'est écrit dans le livre tout seul.</p>

        <section data-recurring>
            <h2>À confirmer</h2>
            <?php if (!$page['yearOpen']): ?>
                <p class="rb-admin-note">Il n'y a pas d'exercice ouvert : créez-le dans la comptabilité pour confirmer des échéances.</p>
            <?php elseif ($page['pending'] === []): ?>
                <p class="rb-admin-note">Aucune échéance à confirmer pour l'instant.</p>
            <?php else: ?>
                <ul class="rb-entry-list">
                    <?php foreach ($page['pending'] as $due): ?>
                        <li class="rb-card rb-entry rb-entry-<?= e($due['direction']) ?>" data-occurrence>
                            <form data-occurrence-form data-template-id="<?= e((string) $due['templateId']) ?>" data-period="<?= e($due['period']) ?>">
                                <div class="rb-entry-head">
                                    <span class="rb-entry-date">Échéance du <?= e($due['dueOn']) ?></span>
                                    <?php if (!$due['isVariable']): ?><span class="rb-entry-amount rb-amount-<?= e($due['direction']) ?>"><?= e($due['amount']) ?></span><?php endif; ?>
                                </div>
                                <p class="rb-entry-label"><?= e($due['label']) ?></p>
                                <?php if ($due['isVariable']): ?>
                                    <div class="rb-field">
                                        <label for="due-amount-<?= e($due['templateId'] . $due['period']) ?>">Montant à saisir (en euros)</label>
                                        <input type="text" id="due-amount-<?= e($due['templateId'] . $due['period']) ?>" name="amount" class="rb-input" inputmode="decimal" placeholder="0,00" autocomplete="off" required>
                                        <p class="rb-field-error" role="alert" data-field-error="amount" hidden></p>
                                    </div>
                                <?php endif; ?>
                                <?php if ($due['needsVoucher']): ?>
                                    <div class="rb-field">
                                        <label for="due-voucher-<?= e($due['templateId'] . $due['period']) ?>">Pièce justificative (facture, ticket…)</label>
                                        <input type="text" id="due-voucher-<?= e($due['templateId'] . $due['period']) ?>" name="voucherRef" class="rb-input" maxlength="100" autocomplete="off" required>
                                        <p class="rb-field-error" role="alert" data-field-error="voucherRef" hidden></p>
                                    </div>
                                <?php endif; ?>
                                <p class="rb-field-error" role="alert" data-field-error="period" hidden></p>
                                <p class="rb-field-error" role="alert" data-field-error="occurredOn" hidden></p>
                                <p class="rb-field-error" role="alert" data-form-error hidden></p>
                                <div class="rb-entry-actions">
                                    <button type="submit" class="rb-btn rb-btn-primary">Confirmer</button>
                                    <button type="button" class="rb-btn" data-action="ignore-occurrence">Pas cette fois</button>
                                </div>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <h2>Modèles</h2>
            <?php if ($page['templates'] === []): ?>
                <p class="rb-admin-note">Aucun modèle : ajoutez le loyer, l'électricité…</p>
            <?php else: ?>
                <ul class="rb-entry-list">
                    <?php foreach ($page['templates'] as $template): ?>
                        <li class="rb-card rb-entry" data-template="<?= e((string) json_encode($template, JSON_UNESCAPED_UNICODE)) ?>">
                            <div class="rb-entry-head">
                                <span class="rb-entry-label"><?= e($template['label']) ?></span>
                                <span class="rb-entry-amount"><?= e($template['amount']) ?></span>
                            </div>
                            <p class="rb-entry-meta">
                                <span class="rb-badge"><?= e($template['categoryLabel']) ?></span>
                                <span><?= e($template['periodicityLabel']) ?>, le <?= e((string) $template['dueDay']) ?></span>
                                <span><?= e($template['methodLabel']) ?></span>
                                <?php if (!$template['isActive']): ?><span class="rb-badge rb-badge-warn">Désactivé</span><?php endif; ?>
                            </p>
                            <div class="rb-entry-actions">
                                <button type="button" class="rb-btn" data-action="edit-template">Modifier</button>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>

            <div class="rb-ledger-new">
                <button type="button" class="rb-btn rb-btn-primary" data-action="new-template">Nouveau modèle</button>
                <form class="rb-card rb-entry-form" data-template-form hidden>
                    <h2 data-template-title>Nouveau modèle</h2>
                    <input type="hidden" name="id" value="">
                    <div class="rb-field">
                        <label for="tpl-label">Libellé</label>
                        <input type="text" id="tpl-label" name="label" class="rb-input" maxlength="150" required autocomplete="off">
                        <p class="rb-field-error" role="alert" data-field-error="label" hidden></p>
                    </div>
                    <div class="rb-field">
                        <label for="tpl-direction">Type</label>
                        <select id="tpl-direction" name="direction" class="rb-input" required>
                            <?php foreach ($form['directions'] as $option): ?>
                                <option value="<?= e($option['value']) ?>"><?= e($option['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="rb-field-error" role="alert" data-field-error="direction" hidden></p>
                    </div>
                    <div class="rb-field">
                        <label for="tpl-category">Catégorie</label>
                        <select id="tpl-category" name="category" class="rb-input" required data-categories="<?= e((string) json_encode($form['categories'], JSON_UNESCAPED_UNICODE)) ?>">
                            <?php foreach ($form['categories'] as $option): ?>
                                <option value="<?= e($option['value']) ?>"><?= e($option['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="rb-field-error" role="alert" data-field-error="category" hidden></p>
                    </div>
                    <div class="rb-field">
                        <label for="tpl-method">Moyen de paiement</label>
                        <select id="tpl-method" name="paymentMethod" class="rb-input" required>
                            <?php foreach ($form['methods'] as $option): ?>
                                <option value="<?= e($option['value']) ?>"><?= e($option['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="rb-field-error" role="alert" data-field-error="paymentMethod" hidden></p>
                    </div>
                    <div class="rb-field">
                        <label for="tpl-amount">Montant (en euros)</label>
                        <input type="text" id="tpl-amount" name="amount" class="rb-input" inputmode="decimal" placeholder="Laisser vide s'il varie" autocomplete="off">
                        <p class="rb-field-hint">Vide pour l'électricité : le montant se saisit à chaque échéance.</p>
                        <p class="rb-field-error" role="alert" data-field-error="amount" hidden></p>
                    </div>
                    <div class="rb-field">
                        <label for="tpl-voucher">Pièce justificative habituelle (facultatif)</label>
                        <input type="text" id="tpl-voucher" name="voucherRef" class="rb-input" maxlength="100" autocomplete="off">
                        <p class="rb-field-error" role="alert" data-field-error="voucherRef" hidden></p>
                    </div>
                    <div class="rb-field">
                        <label for="tpl-periodicity">Rythme</label>
                        <select id="tpl-periodicity" name="periodicity" class="rb-input" required>
                            <?php foreach ($form['periodicities'] as $option): ?>
                                <option value="<?= e($option['value']) ?>"><?= e($option['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="rb-field-error" role="alert" data-field-error="periodicity" hidden></p>
                    </div>
                    <div class="rb-field">
                        <label for="tpl-day">Jour du mois (1 à 28)</label>
                        <input type="number" id="tpl-day" name="dueDay" class="rb-input" min="1" max="28" value="5" required>
                        <p class="rb-field-error" role="alert" data-field-error="dueDay" hidden></p>
                    </div>
                    <div class="rb-field">
                        <label for="tpl-first">Premier mois</label>
                        <input type="month" id="tpl-first" name="firstMonth" class="rb-input" value="<?= e($form['firstMonth']) ?>" required>
                        <p class="rb-field-hint">Les échéances suivent à partir de ce mois, au rythme choisi.</p>
                        <p class="rb-field-error" role="alert" data-field-error="firstMonth" hidden></p>
                    </div>
                    <label class="rb-field-inline"><input type="checkbox" name="isActive" value="1" checked> Actif</label>
                    <p class="rb-field-error" role="alert" data-form-error hidden></p>
                    <div class="rb-entry-actions">
                        <button type="submit" class="rb-btn rb-btn-primary">Enregistrer le modèle</button>
                        <button type="button" class="rb-btn" data-action="cancel-template">Annuler</button>
                    </div>
                </form>
            </div>
        </section>
    </div>

    <?php require __DIR__ . '/../../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
