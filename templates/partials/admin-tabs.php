<?php
/**
 * Onglets des pages admin « Groupes » / « Utilisateurs » (#155) : deux pages distinctes
 * (navigation serveur, simples liens), l'onglet courant est marqué aria-current="page".
 *
 * @var string $adminTab 'bookings', 'slots', 'groups', 'users' ou 'assemblies'
 */
?>
<nav class="rb-admin-tabs" aria-label="Administration">
    <a href="/admin/bookings" class="rb-admin-tab"<?= $adminTab === 'bookings' ? ' aria-current="page"' : '' ?>>
        Réservations <span class="rb-badge rb-badge-warn rb-bottom-nav-badge" data-bookings-badge hidden>0</span>
    </a>
    <a href="/admin/slots" class="rb-admin-tab"<?= $adminTab === 'slots' ? ' aria-current="page"' : '' ?>>
        Créneaux
    </a>
    <a href="/admin/groups" class="rb-admin-tab"<?= $adminTab === 'groups' ? ' aria-current="page"' : '' ?>>
        Groupes
    </a>
    <a href="/admin/users" class="rb-admin-tab"<?= $adminTab === 'users' ? ' aria-current="page"' : '' ?>>
        Utilisateurs
    </a>
    <a href="/admin/assemblies" class="rb-admin-tab"<?= $adminTab === 'assemblies' ? ' aria-current="page"' : '' ?>>
        Assemblées
    </a>
</nav>
