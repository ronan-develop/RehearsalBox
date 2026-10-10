/**
 * Gestion des comptes (admin, #138) : création sans mot de passe, activation,
 * désactivation, déblocage — tout en XHR, jamais de rechargement de page.
 * Édition d'un compte (#272) : nom, e-mail, rôle, groupes (composant rb-user-groups).
 * Après chaque action réussie, la liste est rechargée depuis l'API (une seule
 * source de vérité : l'état affiché est celui du serveur), en gardant ouverts
 * les panneaux d'édition déjà ouverts.
 */
import { apiFetch } from '../core/api.js';
import { showToast } from '../core/toast.js';
import { confirmAction } from '../ui/rb-confirm-dialog.js';
import { escapeHtml } from '../core/html.js';

/** Attribut JSON : échappé comme le gabarit PHP (e() sur le JSON encodé), pour une valeur entre apostrophes. */
function jsonAttr(value) {
  return escapeHtml(JSON.stringify(value));
}

/** Miroir du panneau d'édition de templates/admin/users/index.php. */
function renderEditPanel(user, { isSelf, allGroups }) {
  const id = escapeHtml(user.id);
  const email = escapeHtml(user.email);
  const emailDisabled = isSelf ? ' disabled' : '';
  const emailNote = isSelf ? '<p class="rb-admin-note">Pour modifier votre propre adresse, utilisez Mon compte.</p>' : '';

  const role = isSelf
    ? ''
    : `
        <div class="rb-field">
          <label for="user-${id}-role">Rôle</label>
          <select id="user-${id}-role" class="rb-input" data-user-role-select data-user-id="${id}">
            <option value="admin"${user.role === 'admin' ? ' selected' : ''}>Administrateur</option>
            <option value="musicien"${user.role === 'musicien' ? ' selected' : ''}>Musicien</option>
          </select>
          <button type="button" class="rb-btn" data-user-role-save>Changer le rôle</button>
        </div>`;

  return `
      <div class="rb-user-edit" data-user-edit hidden>
        <form data-user-identity-form data-user-id="${id}">
          <div class="rb-field">
            <label for="user-${id}-display-name">Nom affiché</label>
            <input type="text" id="user-${id}-display-name" name="displayName" class="rb-input" required maxlength="100" value="${escapeHtml(user.displayName)}">
          </div>
          <div class="rb-field">
            <label for="user-${id}-email">Adresse e-mail</label>
            <input type="email" id="user-${id}-email" name="email" class="rb-input" required maxlength="190" value="${email}"${emailDisabled}>
            ${emailNote}
          </div>
          <p class="rb-field-error" data-user-edit-error role="alert" hidden></p>
          <button type="submit" class="rb-btn-primary">Enregistrer</button>
        </form>${role}
        <rb-user-groups user-id="${id}" groups='${jsonAttr(user.groups ?? [])}' all-groups='${jsonAttr(allGroups)}'></rb-user-groups>
      </div>`;
}

/** Miroir JS de templates/admin/users/index.php (une carte par compte). */
export function renderUserCard(user, { currentUserId, allGroups = [] } = {}) {
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
        <button type="button" class="rb-btn" data-edit-user-button data-user-id="${id}">Modifier</button>
      </div>${renderEditPanel(user, { isSelf, allGroups })}
    </article>
  `;
}

export function renderUserList(users, currentUserId, allGroups = []) {
  return users.map((user) => renderUserCard(user, { currentUserId, allGroups })).join('');
}

/** Tous les groupes, posés par le gabarit sur [data-user-list] (data-groups). */
function readAllGroups(list) {
  return JSON.parse(list.dataset.groups || '[]');
}

/** Ids des comptes dont le panneau d'édition est ouvert, pour le rouvrir après rechargement. */
function openEditIds(list) {
  return [...list.querySelectorAll('[data-user-card]')]
    .filter((card) => !card.querySelector('[data-user-edit]').hidden)
    .map((card) => card.dataset.userId);
}

async function refreshList(list) {
  const openIds = openEditIds(list);
  const data = await apiFetch('/api/admin/users');
  list.innerHTML = renderUserList(data.users, Number(list.dataset.currentUserId), readAllGroups(list));
  for (const card of list.querySelectorAll('[data-user-card]')) {
    if (openIds.includes(card.dataset.userId)) {
      card.querySelector('[data-user-edit]').hidden = false;
    }
  }
}

/** Erreur 422 affichée dans le panneau de la carte : premier message de champ, sinon le message général. */
function showCardError(card, error) {
  const box = card.querySelector('[data-user-edit-error]');
  box.textContent = Object.values(error.fields ?? {}).find((message) => typeof message === 'string') ?? error.message;
  box.hidden = false;
}

function clearCardError(card) {
  const box = card.querySelector('[data-user-edit-error]');
  box.textContent = '';
  box.hidden = true;
}

async function runAction(list, request, successMessage, card = null) {
  if (card) {
    clearCardError(card);
  }
  try {
    await request();
    await refreshList(list);
    showToast(successMessage, 'success');
  } catch (error) {
    if (card && error.status === 422) {
      showCardError(card, error);
    } else {
      showToast(error.message, 'error');
    }
  }
}

function cardOf(event) {
  return event.target.closest('[data-user-card]');
}

async function saveIdentity(list, form) {
  const emailField = form.elements.email;
  const emailChanged = emailField.value !== emailField.defaultValue;
  const body = { displayName: form.elements.displayName.value, email: emailField.value };
  const message = emailChanged ? 'Compte modifié. L\'ancienne adresse a été prévenue.' : 'Compte modifié.';

  await runAction(list, () => apiFetch(`/api/admin/users/${form.dataset.userId}/identity`, { method: 'PUT', body: JSON.stringify(body) }), message, form.closest('[data-user-card]'));
}

async function saveGroupRole(list, event) {
  const { userId, groupId, role } = event.detail;
  await runAction(list, () => apiFetch(`/api/admin/users/${userId}/groups/${groupId}`, { method: 'PUT', body: JSON.stringify({ role }) }), 'Appartenance aux groupes mise à jour.', cardOf(event));
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

  root.addEventListener('submit', (event) => {
    if (!event.target.matches('[data-user-identity-form]')) {
      return;
    }
    event.preventDefault();
    saveIdentity(list, event.target);
  });

  root.addEventListener('user-group-role', (event) => saveGroupRole(list, event));
  root.addEventListener('user-group-add', (event) => saveGroupRole(list, event));

  root.addEventListener('user-group-remove', (event) => {
    const { userId, groupId } = event.detail;
    return runAction(list, () => apiFetch(`/api/admin/users/${userId}/groups/${groupId}`, { method: 'DELETE' }), 'Groupe retiré du compte.', cardOf(event));
  });

  root.addEventListener('user-group-move', (event) => {
    const { userId, fromGroupId, toGroupId } = event.detail;
    return runAction(list, () => apiFetch(`/api/admin/users/${userId}/groups/${fromGroupId}/move`, { method: 'POST', body: JSON.stringify({ toGroupId }) }), 'Compte déplacé vers l\'autre groupe.', cardOf(event));
  });

  root.addEventListener('click', async (event) => {
    const edit = event.target.closest('[data-edit-user-button]');
    if (edit) {
      const panel = edit.closest('[data-user-card]').querySelector('[data-user-edit]');
      panel.hidden = !panel.hidden;
      return;
    }

    const roleSave = event.target.closest('[data-user-role-save]');
    if (roleSave) {
      const card = roleSave.closest('[data-user-card]');
      const select = card.querySelector('[data-user-role-select]');
      const confirmed = await confirmAction('Changer le rôle de ce compte ?');
      if (confirmed) {
        await runAction(list, () => apiFetch(`/api/admin/users/${card.dataset.userId}/role`, { method: 'PUT', body: JSON.stringify({ role: select.value }) }), 'Rôle modifié.', card);
      }
      return;
    }

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
