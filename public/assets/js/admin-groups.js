/**
 * CRUD groupes + gestion des membres (admin) — création et ajout/retrait de
 * membre en XHR, jamais de rechargement de page (cf. plan §5bis).
 */
import { initAsyncForms } from './forms.js';
import { apiFetch } from './api.js';
import { showToast } from './toast.js';
import { confirmAction } from './rb-confirm-modal.js';

const HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => HTML_ESCAPES[char]);
}

export function renderGroupCard(group) {
  return `
    <article class="rb-group-card rb-card" data-group-id="${group.id}">
      <h3>${escapeHtml(group.name)}</h3>
      ${group.genre ? `<p class="rb-group-genre">${escapeHtml(group.genre)}</p>` : ''}
      <form data-async data-endpoint="/api/admin/groups/${group.id}" data-method="PATCH" class="rb-edit-group-form" hidden>
        <input type="text" name="name" class="rb-input" value="${escapeHtml(group.name)}" required maxlength="120">
        <input type="text" name="genre" class="rb-input" value="${escapeHtml(group.genre)}" maxlength="60">
        <input type="color" name="colorHex" value="${escapeHtml(group.colorHex || '#b5654a')}">
        <input type="email" name="contactEmail" class="rb-input" value="${escapeHtml(group.contactEmail ?? '')}" required maxlength="190">
        <button type="submit" class="rb-btn">Enregistrer</button>
      </form>
      <div class="rb-group-actions">
        <button type="button" class="rb-btn" data-edit-group-button data-group-id="${group.id}">Modifier</button>
        <button type="button" class="rb-btn rb-btn-danger" data-delete-group-button data-group-id="${group.id}">Supprimer</button>
      </div>
      <form data-async data-endpoint="/api/admin/groups/${group.id}/members" data-method="POST" class="rb-add-member-form">
        <input type="email" name="email" class="rb-input" placeholder="Email du musicien" required>
        <button type="submit" class="rb-btn">Ajouter</button>
      </form>
    </article>
  `;
}

function handleEditToggle(button, root) {
  const card = root.querySelector(`[data-group-id="${button.dataset.groupId}"]`);
  card?.querySelector('.rb-edit-group-form')?.toggleAttribute('hidden');
}

function applyGroupUpdate(card, group) {
  card.querySelector('h3').textContent = group.name;

  let genreEl = card.querySelector('.rb-group-genre');
  if (group.genre) {
    if (!genreEl) {
      genreEl = document.createElement('p');
      genreEl.className = 'rb-group-genre';
      card.querySelector('h3').insertAdjacentElement('afterend', genreEl);
    }
    genreEl.textContent = group.genre;
  } else {
    genreEl?.remove();
  }

  card.querySelector('.rb-edit-group-form').setAttribute('hidden', '');
}

function initGroupCard(card) {
  initAsyncForms(card);
  card.querySelector('.rb-add-member-form')
    ?.addEventListener('async-success', () => showToast('Membre ajouté.', 'success'));
  card.querySelector('.rb-edit-group-form')
    ?.addEventListener('async-success', (event) => {
      applyGroupUpdate(card, event.detail);
      showToast('Groupe modifié.', 'success');
    });
}

async function handleDeleteGroup(button, root) {
  const confirmed = await confirmAction('Supprimer ce groupe ? Cette action retire aussi ses membres et créneaux.');
  if (!confirmed) {
    return;
  }

  const groupId = button.dataset.groupId;

  try {
    await apiFetch(`/api/admin/groups/${groupId}`, { method: 'DELETE' });
    root.querySelector(`[data-group-id="${groupId}"]`)?.remove();
    showToast('Groupe supprimé.', 'success');
  } catch (error) {
    showToast(error.message, 'error');
  }
}

async function handleRemoveMember(button, root) {
  const confirmed = await confirmAction('Retirer ce membre du groupe ?');
  if (!confirmed) {
    return;
  }

  const { groupId, userId } = button.dataset;

  try {
    await apiFetch(`/api/admin/groups/${groupId}/members/${userId}`, { method: 'DELETE' });
    root.querySelector(`[data-member-row][data-user-id="${userId}"]`)?.remove();
    showToast('Membre retiré.', 'success');
  } catch (error) {
    showToast(error.message, 'error');
  }
}

export function initAdminGroups(root = document) {
  initAsyncForms(root);

  root.querySelector('form[data-async][data-endpoint="/api/admin/groups"]')
    ?.addEventListener('async-success', (event) => {
      const list = root.querySelector('[data-group-list]');
      if (list) {
        list.insertAdjacentHTML('beforeend', renderGroupCard(event.detail));
        initGroupCard(list.lastElementChild);
      }
      event.target.reset();
      showToast('Groupe créé.', 'success');
    });

  root.querySelectorAll('[data-group-id]').forEach(initGroupCard);

  root.addEventListener('click', (event) => {
    const editButton = event.target.closest('[data-edit-group-button]');
    if (editButton) {
      handleEditToggle(editButton, root);
      return;
    }

    const deleteButton = event.target.closest('[data-delete-group-button]');
    if (deleteButton) {
      handleDeleteGroup(deleteButton, root);
      return;
    }

    const button = event.target.closest('[data-remove-member-button]');
    if (button) {
      handleRemoveMember(button, root);
    }
  });
}
