<?php
/** @var \App\Entity\Enum\UserRole|null $currentUserRole */
?>
<?php if ($currentUserRole === \App\Entity\Enum\UserRole::Admin): ?>
<nav class="rb-bottom-nav">
    <a href="/" class="rb-bottom-nav-link">Disponibilités</a>
    <a href="/admin/slots" class="rb-bottom-nav-link">Créneaux</a>
    <a href="/admin/groups" class="rb-bottom-nav-link">Groupes</a>
    <button type="button" class="rb-bottom-nav-link rb-bottom-nav-logout" data-logout>Déconnexion</button>
</nav>
<?php elseif ($currentUserRole !== null): ?>
<div class="rb-logout-only">
    <button type="button" class="rb-btn-logout-centered" data-logout>Déconnexion</button>
    <span class="rb-scroll-hint" data-scroll-hint aria-hidden="true">↓</span>
</div>
<?php endif; ?>
