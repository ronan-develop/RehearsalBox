<?php
/**
 * Nouveau message (#269) : tous les membres actifs, avec leurs groupes, jamais d'adresse. De vrais liens vers la page de
 * démarrage ; <rb-member-filter> n'ajoute que le filtre par nom (sans JS la liste complète reste utilisable).
 *
 * @var list<array{id: int, name: string, groups: string}> $picker
 */
?>
<div class="rb-chat-picker" data-chat-picker>
    <header class="rb-chat-thread-head">
        <a href="/messages" class="rb-back-link rb-chat-back" data-chat-back aria-label="Retour aux conversations"><?php require __DIR__ . '/../partials/icon-back.php'; ?></a>
        <h2 class="rb-chat-title rb-chat-title--static">Nouveau message</h2>
    </header>
    <rb-member-filter class="rb-chat-picker-body">
        <div class="rb-chat-picker-search">
            <input type="search" id="chat-picker-filter" class="rb-input" placeholder="Chercher un membre par nom" aria-label="Chercher un membre par nom" autocomplete="off" data-member-filter-input>
        </div>
        <ul class="rb-chat-picker-list" data-member-filter-list>
            <?php foreach ($picker as $member): ?>
                <li data-member-name="<?= e($member['name']) ?>">
                    <a href="/messages/direct/<?= e((string) $member['id']) ?>" class="rb-chat-picker-item">
                        <span class="rb-chat-picker-name"><?= e($member['name']) ?></span>
                        <?php if ($member['groups'] !== ''): ?><span class="rb-chat-picker-groups"><?= e($member['groups']) ?></span><?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="rb-chat-empty" data-member-filter-empty<?= $picker === [] ? '' : ' hidden' ?>>Aucun membre ne correspond.</p>
    </rb-member-filter>
</div>
