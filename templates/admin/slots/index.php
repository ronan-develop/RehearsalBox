<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrfToken ?? '') ?>">
    <title>Admin — Créneaux — RehearsalBox</title>
    <link rel="stylesheet" href="/assets/css/base.css">
    <link rel="stylesheet" href="/assets/css/pages/admin.css">
</head>
<body>
    <div class="rb-admin-page">
        <h1>Créneaux récurrents</h1>

        <form data-async data-endpoint="/api/admin/slots" data-method="POST" class="rb-admin-form">
            <div class="rb-field">
                <label for="groupId">Groupe</label>
                <select id="groupId" name="groupId" required>
                    <?php foreach ($groups as $group): ?>
                        <option value="<?= e((string) $group->id()) ?>"><?= e($group->name()) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="rb-field">
                <label for="weekday">Jour</label>
                <select id="weekday" name="weekday" required>
                    <option value="0">Lundi</option>
                    <option value="1">Mardi</option>
                    <option value="2">Mercredi</option>
                    <option value="3">Jeudi</option>
                    <option value="4">Vendredi</option>
                    <option value="5">Samedi</option>
                    <option value="6">Dimanche</option>
                </select>
            </div>
            <div class="rb-field">
                <label for="startTime">Début</label>
                <input type="time" id="startTime" name="startTime" required>
            </div>
            <div class="rb-field">
                <label for="endTime">Fin</label>
                <input type="time" id="endTime" name="endTime" required>
            </div>
            <span class="rb-field-error" data-field-error="startTime"></span>
            <button type="submit" class="rb-btn rb-auth-submit">Ajouter le créneau</button>
        </form>

        <table class="rb-admin-table">
            <thead>
                <tr><th>Jour</th><th>Horaire</th><th></th></tr>
            </thead>
            <tbody data-slot-list-body>
                <?php foreach ($slots as $slot): ?>
                    <tr data-slot-row data-slot-id="<?= e((string) $slot->id()) ?>">
                        <td><?= e($slot->weekday()->name) ?></td>
                        <td><?= e($slot->startTime()) ?> – <?= e($slot->endTime()) ?></td>
                        <td>
                            <button type="button" class="rb-btn rb-btn-danger" data-delete-slot-button
                                    data-slot-id="<?= e((string) $slot->id()) ?>">
                                Supprimer
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <script type="module" src="/assets/js/app.js"></script>
</body>
</html>
