<?php
/**
 * « À faire » du trésorier : calculé à partir de l'état du livre, rien à configurer. Chaque tâche dit son état EN TOUTES LETTRES (jamais la
 * couleur seule), son échéance et mène à l'action. Les libellés ne portent ni montant ni nom.
 *
 * @var array{items: list<array<string, mixed>>, hidden: int, lateCount: int, total: int} $todo
 * @var array<string, mixed> $year
 */
?>
<section class="rb-card rb-todo" aria-labelledby="todo-title" data-todo>
    <h2 id="todo-title">À faire</h2>
    <?php if ($todo['total'] === 0): ?>
        <p class="rb-admin-note">Tout est à jour. Rien à faire pour l'instant.</p>
    <?php else: ?>
        <ul class="rb-todo-list">
            <?php foreach ($todo['items'] as $task): ?>
                <li class="rb-todo-item<?= $task['late'] ? ' rb-todo-late' : '' ?>">
                    <p class="rb-todo-label">
                        <span class="rb-badge<?= $task['late'] ? ' rb-badge-warn' : '' ?>"><?= e($task['stateLabel']) ?></span>
                        <?= e($task['label']) ?>
                        <?php if ($task['due'] !== ''): ?><span class="rb-entry-note">(<?= e($task['due']) ?>)</span><?php endif; ?>
                    </p>
                    <div class="rb-entry-actions">
                        <a class="rb-btn" href="<?= e($task['url']) ?>"><?= e($task['action']) ?></a>
                        <?php if ($task['emptyMonth'] !== ''): ?>
                            <button type="button" class="rb-btn" data-action="empty-month" data-year-id="<?= e((string) $year['id']) ?>" data-month="<?= e($task['emptyMonth']) ?>">Rien à saisir ce mois-ci</button>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($todo['hidden'] > 0): ?>
            <p class="rb-admin-note">Et <?= e((string) $todo['hidden']) ?> autre<?= $todo['hidden'] > 1 ? 's' : '' ?> tâche<?= $todo['hidden'] > 1 ? 's' : '' ?> : elles apparaîtront quand les premières seront faites.</p>
        <?php endif; ?>
    <?php endif; ?>
</section>
