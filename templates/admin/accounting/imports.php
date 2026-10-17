<?php
/**
 * Importer le fichier CSV de la banque (#362) : envoi avec aperçu, puis relecture ligne par ligne. RIEN n'entre dans le livre sans validation.
 * Les libellés de la banque (noms de tiers, parfois un IBAN) sont affichés échappés et ne sont jamais conservés au-delà de 30 jours. Rendu
 * serveur ; tout passe par l'API (JS). Aucun style ni script en ligne.
 *
 * @var string $csrfToken
 * @var array<string, mixed> $page voir ImportView::page
 * @var \App\Account\Entity\UserRole $currentUserRole
 */
$adminTab = 'accounting';
$profile = $page['profile'];
$selected = static fn (string $current, string $value): string => $current === $value ? ' selected' : '';
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <title>Admin — Importer la banque — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
    <link rel="stylesheet" href="/assets/css/pages/accounting.css">
</head>
<body>
    <div class="rb-admin-page">
        <?php $backHref = '/admin/accounting'; $backLabel = 'Retour à la comptabilité'; require __DIR__ . '/../../partials/back-button.php'; ?>
        <?php require __DIR__ . '/../../partials/admin-tabs.php'; ?>
        <h1>Importer la banque</h1>
        <p class="rb-admin-subtitle">Téléchargez vous-même le fichier CSV des mouvements depuis l'espace en ligne de la banque, puis déposez-le ici. Le site propose les écritures, vous relisez et vous validez : <strong>rien n'entre dans le livre sans votre validation</strong>. Le fichier n'est pas conservé et les lignes sont effacées après 30 jours.</p>

        <section data-imports>
            <form class="rb-card rb-import-form" data-import-form>
                <h2>Le fichier</h2>
                <div class="rb-field">
                    <label for="import-file">Fichier CSV (1 Mo au plus)</label>
                    <input type="file" id="import-file" name="file" class="rb-input" accept=".csv,text/csv,text/plain" required>
                    <p class="rb-field-error" role="alert" data-field-error="file" hidden></p>
                </div>

                <h3>Les colonnes de ce fichier</h3>
                <p class="rb-field-hint">Ouvrez le fichier dans un tableur pour voir à quelle colonne se trouve chaque information (la première colonne est la numéro 1). Ce choix est retenu pour la prochaine fois.</p>
                <div class="rb-field">
                    <label for="import-delimiter">Séparateur</label>
                    <select id="import-delimiter" name="delimiter" class="rb-input">
                        <option value="semicolon"<?= $selected($profile['delimiter'], 'semicolon') ?>>Point-virgule ( ; )</option>
                        <option value="comma"<?= $selected($profile['delimiter'], 'comma') ?>>Virgule ( , )</option>
                        <option value="tab"<?= $selected($profile['delimiter'], 'tab') ?>>Tabulation</option>
                    </select>
                    <p class="rb-field-error" role="alert" data-field-error="delimiter" hidden></p>
                </div>
                <div class="rb-field">
                    <label for="import-encoding">Encodage (si les accents sont abîmés, essayez l'autre)</label>
                    <select id="import-encoding" name="encoding" class="rb-input">
                        <option value="UTF-8"<?= $selected($profile['encoding'], 'UTF-8') ?>>UTF-8</option>
                        <option value="ISO-8859-1"<?= $selected($profile['encoding'], 'ISO-8859-1') ?>>ISO-8859-1 (ancien format Windows)</option>
                    </select>
                    <p class="rb-field-error" role="alert" data-field-error="encoding" hidden></p>
                </div>
                <div class="rb-field">
                    <label for="import-skip">Lignes d'en-tête à ignorer</label>
                    <input type="number" id="import-skip" name="skipRows" class="rb-input" min="0" max="10" value="<?= e($profile['skipRows']) ?>" required>
                    <p class="rb-field-error" role="alert" data-field-error="skipRows" hidden></p>
                </div>
                <div class="rb-field">
                    <label for="import-date-col">Colonne de la date</label>
                    <input type="number" id="import-date-col" name="dateColumn" class="rb-input" min="1" max="30" value="<?= e($profile['dateColumn']) ?>" required>
                    <p class="rb-field-error" role="alert" data-field-error="dateColumn" hidden></p>
                </div>
                <div class="rb-field">
                    <label for="import-date-format">Format de la date</label>
                    <select id="import-date-format" name="dateFormat" class="rb-input">
                        <option value="d/m/Y"<?= $selected($profile['dateFormat'], 'd/m/Y') ?>>05/11/2025</option>
                        <option value="d/m/y"<?= $selected($profile['dateFormat'], 'd/m/y') ?>>05/11/25</option>
                        <option value="d-m-Y"<?= $selected($profile['dateFormat'], 'd-m-Y') ?>>05-11-2025</option>
                        <option value="d.m.Y"<?= $selected($profile['dateFormat'], 'd.m.Y') ?>>05.11.2025</option>
                        <option value="Y-m-d"<?= $selected($profile['dateFormat'], 'Y-m-d') ?>>2025-11-05</option>
                    </select>
                    <p class="rb-field-error" role="alert" data-field-error="dateFormat" hidden></p>
                </div>
                <div class="rb-field">
                    <label for="import-label-col">Colonne du libellé</label>
                    <input type="number" id="import-label-col" name="labelColumn" class="rb-input" min="1" max="30" value="<?= e($profile['labelColumn']) ?>" required>
                    <p class="rb-field-error" role="alert" data-field-error="labelColumn" hidden></p>
                </div>
                <div class="rb-field">
                    <label for="import-mode">Les montants</label>
                    <select id="import-mode" name="amountMode" class="rb-input">
                        <option value="signed"<?= $selected($profile['amountMode'], 'signed') ?>>Une seule colonne (négatif = argent dépensé)</option>
                        <option value="split"<?= $selected($profile['amountMode'], 'split') ?>>Deux colonnes : débit et crédit</option>
                    </select>
                    <p class="rb-field-error" role="alert" data-field-error="amountMode" hidden></p>
                </div>
                <div class="rb-field" data-amount-signed>
                    <label for="import-amount-col">Colonne du montant</label>
                    <input type="number" id="import-amount-col" name="amountColumn" class="rb-input" min="1" max="30" value="<?= e($profile['amountColumn']) ?>">
                    <p class="rb-field-error" role="alert" data-field-error="amountColumn" hidden></p>
                </div>
                <div class="rb-field" data-amount-split hidden>
                    <label for="import-debit-col">Colonne des débits (argent dépensé)</label>
                    <input type="number" id="import-debit-col" name="debitColumn" class="rb-input" min="1" max="30" value="<?= e($profile['debitColumn']) ?>">
                    <p class="rb-field-error" role="alert" data-field-error="debitColumn" hidden></p>
                </div>
                <div class="rb-field" data-amount-split hidden>
                    <label for="import-credit-col">Colonne des crédits (argent reçu)</label>
                    <input type="number" id="import-credit-col" name="creditColumn" class="rb-input" min="1" max="30" value="<?= e($profile['creditColumn']) ?>">
                    <p class="rb-field-error" role="alert" data-field-error="creditColumn" hidden></p>
                </div>
                <div class="rb-field">
                    <label for="import-decimal">Séparateur des centimes</label>
                    <select id="import-decimal" name="decimalSeparator" class="rb-input">
                        <option value=","<?= $selected($profile['decimalSeparator'], ',') ?>>Virgule : 12,50</option>
                        <option value="."<?= $selected($profile['decimalSeparator'], '.') ?>>Point : 12.50</option>
                    </select>
                    <p class="rb-field-error" role="alert" data-field-error="decimalSeparator" hidden></p>
                </div>

                <p class="rb-field-error" role="alert" data-form-error hidden></p>
                <div class="rb-entry-actions">
                    <button type="button" class="rb-btn" data-action="preview-import">Voir un aperçu</button>
                    <button type="submit" class="rb-btn rb-btn-primary">Mettre en attente</button>
                </div>
                <div class="rb-import-preview" data-import-preview hidden>
                    <h3>Aperçu</h3>
                    <p class="rb-admin-note" data-preview-summary></p>
                    <ul class="rb-import-preview-list" data-preview-lines></ul>
                    <ul class="rb-field-error" data-preview-errors></ul>
                </div>
            </form>

            <h2>Lignes à relire</h2>
            <?php if ($page['lines'] === []): ?>
                <p class="rb-admin-note">Aucune ligne en attente. Déposez un fichier ci-dessus.</p>
            <?php else: ?>
                <p class="rb-admin-note">Vérifiez chaque ligne : catégorie, libellé, et pièce pour une dépense. Décochez celles que vous voulez traiter plus tard. Les propositions sont des aides, jamais des décisions.</p>
                <template data-members-template>
                    <option value="">Choisir l'adhérent</option>
                    <?php foreach ($page['members'] as $member): ?>
                        <option value="<?= e($member['value']) ?>"><?= e($member['label']) ?></option>
                    <?php endforeach; ?>
                </template>
                <ul class="rb-entry-list" data-import-lines>
                    <?php foreach ($page['lines'] as $line): ?>
                        <?php require __DIR__ . '/_import-line.php'; ?>
                    <?php endforeach; ?>
                </ul>
                <p class="rb-field-error" role="alert" data-validate-error hidden></p>
                <div class="rb-entry-actions rb-import-validate">
                    <button type="button" class="rb-btn rb-btn-primary" data-action="validate-import">Valider les écritures cochées</button>
                    <a class="rb-btn" href="/admin/accounting/rules">Règles retenues</a>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <?php require __DIR__ . '/../../partials/nav.php'; ?>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
