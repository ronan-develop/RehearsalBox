<?php
/**
 * Cotisations par personne (#362) : qui a payé quel mois, calculé à partir du livre. Rendu serveur ; les saisies et les dispenses passent
 * par l'API (JS). Chaque état est écrit en toutes lettres (jamais la couleur seule). Tout texte est échappé ici ; aucun style ni script en ligne.
 *
 * @var string $csrfToken
 * @var array<string, mixed> $page voir CotisationView::page
 * @var \App\Account\Entity\UserRole $currentUserRole
 */
$year = $page['year'];
$adminTab = 'accounting';
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <title>Admin — Cotisations — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
    <link rel="stylesheet" href="/assets/css/pages/accounting.css">
</head>
<body>
    <div class="rb-admin-page">
        <?php $backHref = '/admin/accounting' . ($year === null ? '' : '?year=' . $year['id']); $backLabel = 'Retour à la comptabilité'; require __DIR__ . '/../../partials/back-button.php'; ?>
        <?php require __DIR__ . '/../../partials/admin-tabs.php'; ?>
        <h1>Cotisations</h1>
        <p class="rb-admin-subtitle">Une cotisation par adhérent et par mois. Le tableau se lit dans le livre : une cotisation enregistrée pour un mois le marque « Payée ».</p>

        <section data-cotisations>
            <?php if ($year === null): ?>
                <p class="rb-admin-note">Aucun exercice pour l'instant : créez-le d'abord dans la comptabilité.</p>
            <?php else: ?>
                <?php if (count($page['years']) > 1): ?>
                    <nav class="rb-year-switch" aria-label="Exercices">
                        <?php foreach ($page['years'] as $choice): ?>
                            <a href="<?= e($choice['url']) ?>" class="rb-year-link"<?= $choice['selected'] ? ' aria-current="page"' : '' ?>><?= e($choice['label']) ?></a>
                        <?php endforeach; ?>
                    </nav>
                <?php endif; ?>
                <h2><?= e($year['label']) ?></h2>
                <p class="rb-admin-note"><?= $page['dueCount'] === 0 ? 'Aucune cotisation en retard.' : e((string) $page['dueCount']) . ' cotisation' . ($page['dueCount'] > 1 ? 's' : '') . ' à pointer.' ?></p>

                <?php if ($year['isOpen']): ?>
                    <form class="rb-card rb-dues-form" data-dues-form data-year-id="<?= e((string) $year['id']) ?>" data-default-amount="<?= e($page['defaultAmount']) ?>" hidden>
                        <h2 data-dues-title>Pointer une cotisation</h2>
                        <input type="hidden" name="memberUserId" value="">
                        <input type="hidden" name="periodMonth" value="">
                        <div class="rb-field">
                            <label for="dues-date">Date du paiement</label>
                            <input type="date" id="dues-date" name="occurredOn" class="rb-input" required>
                            <p class="rb-field-error" role="alert" data-field-error="occurredOn" hidden></p>
                        </div>
                        <div class="rb-field">
                            <label for="dues-amount">Montant (en euros)</label>
                            <input type="text" id="dues-amount" name="amount" class="rb-input" inputmode="decimal" autocomplete="off" required>
                            <p class="rb-field-error" role="alert" data-field-error="amount" hidden></p>
                        </div>
                        <div class="rb-field">
                            <label for="dues-method">Moyen de paiement</label>
                            <select id="dues-method" name="paymentMethod" class="rb-input" required>
                                <option value="virement">Virement</option>
                                <option value="especes">Espèces</option>
                                <option value="cheque">Chèque</option>
                                <option value="carte">Carte</option>
                            </select>
                            <p class="rb-field-error" role="alert" data-field-error="paymentMethod" hidden></p>
                        </div>
                        <p class="rb-field-error" role="alert" data-field-error="memberUserId" hidden></p>
                        <p class="rb-field-error" role="alert" data-field-error="periodMonth" hidden></p>
                        <p class="rb-field-error" role="alert" data-form-error hidden></p>
                        <div class="rb-entry-actions">
                            <button type="submit" class="rb-btn rb-btn-primary">Enregistrer la cotisation</button>
                            <button type="button" class="rb-btn" data-action="cancel-dues">Annuler</button>
                        </div>
                    </form>
                <?php endif; ?>

                <?php if ($page['rows'] === []): ?>
                    <p class="rb-admin-note">Aucun adhérent actif.</p>
                <?php else: ?>
                    <div class="rb-dues-scroll">
                        <table class="rb-dues-table">
                            <caption>Cotisations par adhérent et par mois, exercice <?= e($year['label']) ?></caption>
                            <thead>
                                <tr>
                                    <th scope="col">Adhérent</th>
                                    <?php foreach ($page['months'] as $month): ?><th scope="col" title="<?= e($month['long']) ?>"><?= e($month['short']) ?></th><?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($page['rows'] as $row): ?>
                                    <tr>
                                        <th scope="row"><?= e($row['name']) ?></th>
                                        <?php foreach ($row['cells'] as $i => $cell): ?>
                                            <td class="rb-dues-cell rb-dues-<?= e($cell['state']) ?>">
                                                <?php if ($cell['isDue'] && $year['isOpen']): ?>
                                                    <button type="button" class="rb-btn rb-dues-pay" data-action="pay-dues" data-member-id="<?= e((string) $row['id']) ?>" data-member-name="<?= e($row['name']) ?>" data-month="<?= e($cell['key']) ?>" data-month-label="<?= e($page['months'][$i]['long']) ?>">À payer</button>
                                                    <button type="button" class="rb-btn rb-dues-exempt" data-action="exempt-dues" data-member-id="<?= e((string) $row['id']) ?>" data-month="<?= e($cell['key']) ?>" data-exempt="1" aria-label="Dispenser <?= e($row['name']) ?> pour <?= e($page['months'][$i]['long']) ?>">Dispenser</button>
                                                <?php elseif ($cell['isExempt'] && $year['isOpen']): ?>
                                                    <button type="button" class="rb-btn rb-dues-exempt" data-action="exempt-dues" data-member-id="<?= e((string) $row['id']) ?>" data-month="<?= e($cell['key']) ?>" data-exempt="0" aria-label="Retirer la dispense de <?= e($row['name']) ?> pour <?= e($page['months'][$i]['long']) ?>">Dispensée (retirer)</button>
                                                <?php else: ?>
                                                    <?= e($cell['label']) ?>
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <?php require __DIR__ . '/../../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
