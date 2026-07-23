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
    <article class="rb-group-card" data-group-id="${group.id}">
      <h3>${escapeHtml(group.name)}</h3>
      ${group.genre ? `<p class="rb-group-genre">${escapeHtml(group.genre)}</p>` : ''}
      <form data-async data-endpoint="/api/admin/groups/${group.id}/members" data-method="POST" class="rb-add-member-form">
        <input type="email" name="email" placeholder="Email du musicien" required>
        <button type="submit" class="rb-btn">Ajouter</button>
      </form>
    </article>
  `;
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
        initAsyncForms(list.lastElementChild);
        list.lastElementChild
          .querySelector('.rb-add-member-form')
          ?.addEventListener('async-success', () => showToast('Membre ajouté.', 'success'));
      }
      event.target.reset();
      showToast('Groupe créé.', 'success');
    });

  root.querySelectorAll('.rb-add-member-form').forEach((form) => {
    form.addEventListener('async-success', () => showToast('Membre ajouté.', 'success'));
  });

  root.addEventListener('click', (event) => {
    const button = event.target.closest('[data-remove-member-button]');
    if (button) {
      handleRemoveMember(button, root);
    }
  });
}
