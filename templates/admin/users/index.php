<?php
/**
 * @var string $csrfToken
 * @var list<\App\Entity\AdminUserItem> $items
 * @var list<\App\Entity\Group> $groups
 * @var int $currentUserId
 * @var \App\Entity\Enum\UserRole $currentUserRole
 */
?>
<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken) ?>">
    <title>Admin — Utilisateurs — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
</head>
<body>
    <div class="rb-admin-page">
        <h1>Utilisateurs</h1>
        <p class="rb-admin-subtitle">Comptes, accès et déblocage.</p>
        <p class="rb-admin-crosslink"><a href="/admin/groups">← Groupes</a></p>

        <form data-async data-endpoint="/api/admin/users" data-method="POST" class="rb-admin-form rb-card" data-user-create-form>
            <p class="rb-admin-note">
                Aucun mot de passe n'est choisi ici : l'utilisateur utilise « Mot de passe oublié »
                sur la page de connexion pour définir le sien lors de sa première connexion.
            </p>
            <div class="rb-field">
                <label for="user-email">Adresse e-mail</label>
                <input type="email" id="user-email" name="email" class="rb-input" required maxlength="190" autocomplete="off">
                <span class="rb-field-error" data-field-error="email"></span>
            </div>
            <div class="rb-field">
                <label for="user-display-name">Nom affiché</label>
                <input type="text" id="user-display-name" name="displayName" class="rb-input" required maxlength="100" autocomplete="off">
                <span class="rb-field-error" data-field-error="displayName"></span>
            </div>
            <div class="rb-field">
                <label for="user-role">Rôle</label>
                <select id="user-role" name="role" class="rb-input">
                    <option value="musicien" selected>Musicien</option>
                    <option value="admin">Administrateur</option>
                </select>
                <span class="rb-field-error" data-field-error="role"></span>
            </div>
            <div class="rb-field">
                <label for="user-group">Groupe (facultatif)</label>
                <select id="user-group" name="groupId" class="rb-input">
                    <option value="">Aucun groupe</option>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= e((string) $group->id()) ?>"><?= e($group->name()) ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="rb-field-error" data-field-error="groupId"></span>
            </div>
            <button type="submit" class="rb-btn-primary">Créer le compte</button>
        </form>

        <div class="rb-user-list" data-user-list data-current-user-id="<?= e((string) $currentUserId) ?>">
            <?php foreach ($items as $item): ?>
                <?php
                $user = $item->user();
                $isSelf = $user->id() === $currentUserId;
                ?>
                <article class="rb-user-card rb-card" data-user-card data-user-id="<?= e((string) $user->id()) ?>">
                    <div class="rb-user-main">
                        <h3><?= e($user->displayName()) ?></h3>
                        <p class="rb-user-email"><?= e($user->email()) ?></p>
                        <p class="rb-user-badges">
                            <span class="rb-badge"><?= $user->role() === \App\Entity\Enum\UserRole::Admin ? 'Administrateur' : 'Musicien' ?></span>
                            <?php if (!$user->isActive()): ?><span class="rb-badge rb-badge-warn">Désactivé</span><?php endif; ?>
                            <?php if ($item->isLocked()): ?><span class="rb-badge rb-badge-warn">Verrouillé</span><?php endif; ?>
                        </p>
                        <?php if ($item->groups() !== []): ?>
                            <p class="rb-user-groups">
                                <?php foreach ($item->groups() as $group): ?>
                                    <span class="rb-user-group"><?= e($group->name()) ?></span>
                                <?php endforeach; ?>
                            </p>
                        <?php endif; ?>
                    </div>
                    <div class="rb-user-actions">
                        <?php if ($item->isLocked()): ?>
                            <button type="button" class="rb-btn" data-unlock-user-button data-user-id="<?= e((string) $user->id()) ?>">Débloquer</button>
                        <?php endif; ?>
                        <?php if (!$user->isActive()): ?>
                            <button type="button" class="rb-btn" data-activate-user-button data-user-id="<?= e((string) $user->id()) ?>">Réactiver</button>
                        <?php elseif (!$isSelf): ?>
                            <button type="button" class="rb-btn rb-btn-danger" data-deactivate-user-button data-user-id="<?= e((string) $user->id()) ?>">Désactiver</button>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
    <?php require __DIR__ . '/../../partials/nav.php'; ?>
    <rb-confirm-modal></rb-confirm-modal>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
