<?php
/** @var \App\Entity\Enum\UserRole $currentUserRole */
?>
<nav class="rb-bottom-nav">
    <a href="/" class="rb-bottom-nav-link">Disponibilités</a>
    <a href="/planning" class="rb-bottom-nav-link">Planning</a>
    <?php if ($currentUserRole === \App\Entity\Enum\UserRole::Admin): ?>
        <a href="/admin/slots" class="rb-bottom-nav-link">Créneaux</a>
        <a href="/admin/groups" class="rb-bottom-nav-link">Groupes</a>
    <?php endif; ?>
    <button type="button" class="rb-bottom-nav-link rb-bottom-nav-logout" data-logout>Déconnexion</button>
</nav>
