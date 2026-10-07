/**
 * CRUD créneaux récurrents (admin) — création/modification/suppression en
 * XHR, jamais de rechargement de page ni de reload de liste complète
 * (cf. plan §5bis). Confirmation avant suppression via la modale maison.
 */
import { initAsyncForms } from '../core/forms.js';
import { apiFetch } from '../core/api.js';
import { showToast } from '../core/toast.js';
import { confirmAction } from '../ui/rb-confirm-dialog.js';
import { escapeHtml } from '../core/html.js';
import { WEEKDAY_LABELS } from '../core/weekdays.js';

export { WEEKDAY_LABELS };

/** Affichage HH:MM d'une heure API en HH:MM:SS. */
export function formatTime(time) {
  return time.slice(0, 5);
}

export function buildSlotPayload(entries) {
  return {
    groupId: Number(entries.groupId),
    weekday: Number(entries.weekday),
    startTime: entries.startTime,
    endTime: entries.endTime,
  };
}

export function renderSlotRow(slot) {
  return `
    <tr data-slot-row data-slot-id="${escapeHtml(slot.id)}">
      <td>${WEEKDAY_LABELS[slot.weekday]}</td>
      <td>${formatTime(slot.startTime)} – ${formatTime(slot.endTime)}</td>
      <td>
        <button type="button" class="rb-btn rb-btn-danger rb-btn-icon" data-delete-slot-button data-slot-id="${escapeHtml(slot.id)}" aria-label="Supprimer">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
            <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
          </svg>
        </button>
      </td>
    </tr>
  `;
}

export function renderSlotCard(slot) {
  return `
    <article class="rb-slot-card rb-card" data-slot-row data-slot-id="${escapeHtml(slot.id)}">
      <div class="rb-slot-card-body">
        <strong>${WEEKDAY_LABELS[slot.weekday]}</strong>
        <span>${formatTime(slot.startTime)} – ${formatTime(slot.endTime)}</span>
      </div>
      <button type="button" class="rb-btn rb-btn-danger rb-btn-icon" data-delete-slot-button data-slot-id="${escapeHtml(slot.id)}" aria-label="Supprimer">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>
          <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
        </svg>
      </button>
    </article>
  `;
}

async function handleDelete(button, root) {
  const confirmed = await confirmAction('Supprimer ce créneau ?');
  if (!confirmed) {
    return;
  }

  const slotId = button.dataset.slotId;

  try {
    await apiFetch(`/api/admin/slots/${slotId}`, { method: 'DELETE' });
    root.querySelectorAll(`[data-slot-row][data-slot-id="${slotId}"]`).forEach((el) => el.remove());
    showToast('Créneau supprimé.', 'success');
  } catch (error) {
    showToast(error.message, 'error');
  }
}

export function initAdminSlots(root = document) {
  initAsyncForms(root);

  const slotForm = root.querySelector('form[data-async][data-endpoint="/api/admin/slots"]');
  slotForm?.addEventListener('async-success', (event) => {
    root.querySelector('[data-slot-list-body]')?.insertAdjacentHTML('beforeend', renderSlotRow(event.detail));
    root.querySelector('[data-slot-list-cards]')?.insertAdjacentHTML('beforeend', renderSlotCard(event.detail));
    event.target.reset();
    showToast('Créneau créé.', 'success');
  });
  slotForm?.addEventListener('async-error', (event) => {
    showToast(event.detail.message, 'error');
  });

  root.addEventListener('click', (event) => {
    const button = event.target.closest('[data-delete-slot-button]');
    if (button) {
      handleDelete(button, root);
    }
  });
}
