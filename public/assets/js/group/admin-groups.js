/**
 * CRUD groupes + gestion des membres (admin) — création et ajout/retrait de
 * membre en XHR, jamais de rechargement de page (cf. plan §5bis).
 */
import { apiFetch } from '../core/api.js';
import { showToast } from '../core/toast.js';
import { confirmAction } from '../ui/rb-confirm-dialog.js';
import { escapeHtml } from '../core/html.js';

export function renderGroupCard(group) {
  return `
    <article class="rb-group-card rb-card" data-group-id="${group.id}">
      <header class="rb-group-card-head">
        <div class="rb-group-card-identity">
          <h3>${escapeHtml(group.name)}</h3>
          ${group.genre ? `<p class="rb-group-genre">${escapeHtml(group.genre)}</p>` : ''}
        </div>
        <div class="rb-group-actions">
          <button type="button" class="rb-btn rb-btn-icon" data-edit-group-button data-group-id="${group.id}" aria-label="Modifier">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path d="M12 20h9"/>
              <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4Z"/>
            </svg>
          </button>
          <button type="button" class="rb-btn rb-btn-danger rb-btn-icon" data-delete-group-button data-group-id="${group.id}" data-members-count="0" data-conversations-count="0" data-documents-count="0" data-requests-count="0" aria-label="Supprimer">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
              <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/>
            </svg>
          </button>
        </div>
      </header>
      <rb-async-form endpoint="/api/admin/groups/${group.id}" method="PATCH"><form class="rb-edit-group-form" hidden>
        <div class="rb-field rb-group-field-name">
          <label for="group-${group.id}-name">Nom du groupe</label>
          <input type="text" id="group-${group.id}-name" name="name" class="rb-input" value="${escapeHtml(group.name)}" required maxlength="120">
        </div>
        <div class="rb-field rb-group-field-genre">
          <label for="group-${group.id}-genre">Genre</label>
          <input type="text" id="group-${group.id}-genre" name="genre" class="rb-input" value="${escapeHtml(group.genre)}" maxlength="60">
        </div>
        <div class="rb-field rb-group-field-color">
          <label for="group-${group.id}-colorHex">Couleur</label>
          <rb-color-picker><input type="text" id="group-${group.id}-colorHex" name="colorHex" class="rb-input" value="${escapeHtml(group.colorHex || '#b5654a')}" maxlength="7" pattern="#[0-9a-fA-F]{6}" autocomplete="off" spellcheck="false"></rb-color-picker>
        </div>
        <div class="rb-field rb-group-field-contact">
          <label for="group-${group.id}-contactEmail">Email de contact</label>
          <input type="email" id="group-${group.id}-contactEmail" name="contactEmail" class="rb-input" value="${escapeHtml(group.contactEmail ?? '')}" required maxlength="190">
        </div>
        <div class="rb-group-form-actions">
          <button type="submit" class="rb-btn-primary">Enregistrer</button>
        </div>
      </form></rb-async-form>
      <section class="rb-group-members">
        <rb-async-form endpoint="/api/admin/groups/${group.id}/members" method="POST"><form class="rb-add-member-form">
          <label for="group-${group.id}-member-email">Ajouter un membre</label>
          <div class="rb-add-member-row">
            <input type="email" id="group-${group.id}-member-email" name="email" class="rb-input" placeholder="Email du musicien" required>
            <button type="submit" class="rb-btn rb-btn-icon" aria-label="Ajouter">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path d="M12 5v14M5 12h14"/>
              </svg>
            </button>
          </div>
        </form></rb-async-form>
      </section>
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
  card.querySelector('rb-async-form[method="POST"]')
    ?.addEventListener('async-success', () => showToast('Membre ajouté.', 'success'));
  card.querySelector('rb-async-form[method="PATCH"]')
    ?.addEventListener('async-success', (event) => {
      applyGroupUpdate(card, event.detail);
      showToast('Groupe modifié.', 'success');
    });
}

const plural = (count, one, many) => `${count} ${count === 1 ? one : many}`;
const countOf = (value) => (Number.isInteger(Number(value)) && Number(value) > 0 ? Number(value) : 0);

/**
 * Texte de la confirmation avant de supprimer un groupe (#224) : dit exactement ce qui disparaît avec lui, d'après les comptes
 * calculés par le serveur (jamais une promesse générique). Sans rien de lié, une simple question.
 */
export function groupDeletionWarning({ members, conversations, documents, requests }) {
  const [m, c, d, r] = [countOf(members), countOf(conversations), countOf(documents), countOf(requests)];
  if (m + c + d + r === 0) {
    return 'Supprimer ce groupe ?';
  }

  const lines = ['Supprimer ce groupe ?'];
  if (c > 0) {
    lines.push(`• ${plural(c, 'conversation, avec tous ses messages', 'conversations, avec tous leurs messages')}, pour les deux groupes concernés ;`);
  }
  if (d > 0) {
    lines.push(`• ${plural(d, 'document', 'documents')} (les fichiers sont effacés) ;`);
  }
  if (r > 0) {
    lines.push(`• ${plural(r, 'demande de créneau', 'demandes de créneau')} ;`);
  }
  if (m > 0) {
    lines.push(`• ${m === 1 ? '1 membre sera retiré du groupe' : `${m} membres seront retirés du groupe`} (leurs comptes restent).`);
  }
  lines.push('Cette action ne peut pas être annulée.');

  return lines.join('\n');
}

async function handleDeleteGroup(button, root) {
  const confirmed = await confirmAction(groupDeletionWarning({
    members: button.dataset.membersCount,
    conversations: button.dataset.conversationsCount,
    documents: button.dataset.documentsCount,
    requests: button.dataset.requestsCount,
  }));
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
  root.querySelector('rb-async-form[endpoint="/api/admin/groups"]')
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
