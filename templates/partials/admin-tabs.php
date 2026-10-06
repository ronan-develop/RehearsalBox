<?php
/**
 * Onglets des pages admin « Groupes » / « Utilisateurs » (#155) : deux pages distinctes
 * (navigation serveur, simples liens), l'onglet courant est marqué aria-current="page".
 *
 * @var string $adminTab 'groups', 'users' ou 'bookings'
 */
?>
<nav class="rb-admin-tabs" aria-label="Administration">
    <a href="/admin/groups" class="rb-admin-tab"<?= $adminTab === 'groups' ? ' aria-current="page"' : '' ?>>
        Groupes
    </a>
    <a href="/admin/bookings" class="rb-admin-tab"<?= $adminTab === 'bookings' ? ' aria-current="page"' : '' ?>>
        Réservations
    </a>
    <a href="/admin/users" class="rb-admin-tab"<?= $adminTab === 'users' ? ' aria-current="page"' : '' ?>>
        Utilisateurs
    </a>
</nav>
