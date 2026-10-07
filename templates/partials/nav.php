<?php
/** @var \App\Account\Entity\UserRole|null $currentUserRole */
?>
<?php if ($currentUserRole === \App\Account\Entity\UserRole::Admin): ?>
<nav class="rb-bottom-nav">
    <a href="/" class="rb-bottom-nav-link">Disponibilités</a>
    <a href="/admin/slots" class="rb-bottom-nav-link">Créneaux <span class="rb-badge rb-badge-warn rb-bottom-nav-badge" data-bookings-badge hidden>0</span></a>
    <a href="/admin/groups" class="rb-bottom-nav-link">Groupes</a>
    <a href="/account/password" class="rb-bottom-nav-link">Compte</a>
    <button type="button" class="rb-bottom-nav-link rb-bottom-nav-logout" data-logout>Déconnexion</button>
</nav>
<?php elseif ($currentUserRole !== null): ?>
<div class="rb-logout-only">
    <a href="/account/password" class="rb-btn-logout-centered rb-btn-logout-centered--secondary">Compte</a>
    <button type="button" class="rb-btn-logout-centered" data-logout>Déconnexion</button>
    <span class="rb-scroll-hint" data-scroll-hint aria-hidden="true">↓</span>
</div>
<?php endif; ?>
