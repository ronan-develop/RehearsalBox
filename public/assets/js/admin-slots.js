/**
 * CRUD créneaux récurrents (admin) — création/modification/suppression en
 * XHR, jamais de rechargement de page ni de reload de liste complète
 * (cf. plan §5bis). Confirmation avant suppression via la modale maison.
 */
import { initAsyncForms } from './forms.js';
import { apiFetch } from './api.js';
import { showToast } from './toast.js';
import { confirmAction } from './rb-confirm-modal.js';

export const WEEKDAY_LABELS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

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
    <tr data-slot-row data-slot-id="${slot.id}">
      <td>${WEEKDAY_LABELS[slot.weekday]}</td>
      <td>${slot.startTime} – ${slot.endTime}</td>
      <td>
        <button type="button" class="rb-btn rb-btn-danger" data-delete-slot-button data-slot-id="${slot.id}">
          Supprimer
        </button>
      </td>
    </tr>
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
    root.querySelector(`[data-slot-row][data-slot-id="${slotId}"]`)?.remove();
    showToast('Créneau supprimé.', 'success');
  } catch (error) {
    showToast(error.message, 'error');
  }
}

export function initAdminSlots(root = document) {
  initAsyncForms(root);

  root.querySelector('form[data-async][data-endpoint="/api/admin/slots"]')
    ?.addEventListener('async-success', (event) => {
      root.querySelector('[data-slot-list-body]')?.insertAdjacentHTML('beforeend', renderSlotRow(event.detail));
      event.target.reset();
      showToast('Créneau créé.', 'success');
    });

  root.addEventListener('click', (event) => {
    const button = event.target.closest('[data-delete-slot-button]');
    if (button) {
      handleDelete(button, root);
    }
  });
}
