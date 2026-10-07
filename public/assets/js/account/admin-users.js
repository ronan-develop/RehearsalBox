/**
 * Gestion des comptes (admin, #138) : création sans mot de passe, activation,
 * désactivation, déblocage — tout en XHR, jamais de rechargement de page.
 * Après chaque action réussie, la liste est rechargée depuis l'API (une seule
 * source de vérité : l'état affiché est celui du serveur).
 */
import { apiFetch } from '../core/api.js';
import { showToast } from '../core/toast.js';
import { confirmAction } from '../ui/rb-confirm-dialog.js';
import { escapeHtml } from '../core/html.js';

/** Miroir JS de templates/admin/users/index.php (une carte par compte). */
export function renderUserCard(user, { currentUserId } = {}) {
  const id = escapeHtml(user.id);
  const isSelf = user.id === currentUserId;
  const groups = (user.groups ?? []).map((group) => `<span class="rb-user-group">${escapeHtml(group.name)}</span>`).join('\n');

  const unlock = user.isLocked
    ? `<button type="button" class="rb-btn" data-unlock-user-button data-user-id="${id}">Débloquer</button>`
    : '';
  let toggle = '';
  if (!user.isActive) {
    toggle = `<button type="button" class="rb-btn" data-activate-user-button data-user-id="${id}">Réactiver</button>`;
  } else if (!isSelf) {
    toggle = `<button type="button" class="rb-btn rb-btn-danger" data-deactivate-user-button data-user-id="${id}">Désactiver</button>`;
  }

  return `
    <article class="rb-user-card rb-card" data-user-card data-user-id="${id}">
      <div class="rb-user-main">
        <h3>${escapeHtml(user.displayName)}</h3>
        <p class="rb-user-email">${escapeHtml(user.email)}</p>
        <p class="rb-user-badges">
          <span class="rb-badge">${user.role === 'admin' ? 'Administrateur' : 'Musicien'}</span>
          ${user.isActive ? '' : '<span class="rb-badge rb-badge-warn">Désactivé</span>'}
          ${user.isLocked ? '<span class="rb-badge rb-badge-warn">Verrouillé</span>' : ''}
        </p>
        ${groups ? `<p class="rb-user-groups">${groups}</p>` : ''}
      </div>
      <div class="rb-user-actions">
        ${unlock}
        ${toggle}
      </div>
    </article>
  `;
}

export function renderUserList(users, currentUserId) {
  return users.map((user) => renderUserCard(user, { currentUserId })).join('');
}

async function refreshList(list) {
  const data = await apiFetch('/api/admin/users');
  list.innerHTML = renderUserList(data.users, Number(list.dataset.currentUserId));
}

async function runAction(list, request, successMessage) {
  try {
    await request();
    await refreshList(list);
    showToast(successMessage, 'success');
  } catch (error) {
    showToast(error.message, 'error');
  }
}

export function initAdminUsers(root = document) {
  const list = root.querySelector('[data-user-list]');
  if (!list) {
    return;
  }

  const form = root.querySelector('rb-async-form[endpoint="/api/admin/users"]');
  form?.addEventListener('async-success', async () => {
    form.reset();
    try {
      await refreshList(list);
    } catch (error) {
      showToast(error.message, 'error');
    }
    showToast('Compte créé. La personne doit utiliser « Mot de passe oublié » pour sa première connexion.', 'success');
  });
  form?.addEventListener('async-error', (event) => showToast(event.detail.message, 'error'));

  root.addEventListener('click', async (event) => {
    const deactivate = event.target.closest('[data-deactivate-user-button]');
    if (deactivate) {
      const confirmed = await confirmAction('Désactiver ce compte ? Il ne pourra plus se connecter et ses sessions seront fermées.');
      if (confirmed) {
        await runAction(list, () => apiFetch(`/api/admin/users/${deactivate.dataset.userId}`, { method: 'PATCH', body: JSON.stringify({ active: false }) }), 'Compte désactivé.');
      }
      return;
    }

    const activate = event.target.closest('[data-activate-user-button]');
    if (activate) {
      await runAction(list, () => apiFetch(`/api/admin/users/${activate.dataset.userId}`, { method: 'PATCH', body: JSON.stringify({ active: true }) }), 'Compte réactivé.');
      return;
    }

    const unlock = event.target.closest('[data-unlock-user-button]');
    if (unlock) {
      await runAction(list, () => apiFetch(`/api/admin/users/${unlock.dataset.userId}/unlock`, { method: 'POST', body: JSON.stringify({}) }), 'Compte débloqué.');
    }
  });
}
