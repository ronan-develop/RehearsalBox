/**
 * Dashboard disponibilités — rendu initial server-side (cf. plan §5bis),
 * seules les actions (répondre à une demande, en initier une) passent en
 * XHR : le DOM est patché en place, jamais de rechargement de page. Cas
 * 409 (déjà répondue) : toast d'erreur + retrait de la carte pour
 * resynchroniser sans reload complet.
 */
import { apiFetch } from './api.js';
import { showToast } from './toast.js';

export function getCurrentGroupId(root = document) {
  const select = root.querySelector('[data-current-group-select]');
  return select ? select.value : root.querySelector('[data-current-group-id]')?.dataset.currentGroupId;
}

export async function handleRespond(button, root = document) {
  const exceptionId = button.dataset.exceptionId;
  const accepted = button.dataset.accepted === 'true';

  try {
    await apiFetch(`/api/availability/${exceptionId}/respond`, {
      method: 'POST',
      body: JSON.stringify({ accepted }),
    });

    root.querySelector(`[data-exception-id="${exceptionId}"]`)?.remove();
    showToast(accepted ? 'Demande acceptée.' : 'Demande refusée.', 'success');
  } catch (error) {
    showToast(error.message, 'error');

    if (error.status === 409) {
      root.querySelector(`[data-exception-id="${exceptionId}"]`)?.remove();
    }
  }
}

export async function handleRequestSubmit(event, root = document) {
  event.preventDefault();
  const form = event.target;
  const formData = new FormData(form);

  try {
    await apiFetch('/api/availability/request', {
      method: 'POST',
      body: JSON.stringify({
        recurringSlotId: Number(formData.get('recurringSlotId')),
        occurrenceDate: formData.get('occurrenceDate'),
        requestingGroupId: Number(formData.get('requestingGroupId')),
        reason: formData.get('reason') || null,
      }),
    });

    showToast('Demande envoyée.', 'success');
    form.reset();
  } catch (error) {
    showToast(error.message, 'error');
  }
}

export async function handleCancel(button, root = document) {
  const exceptionId = button.dataset.exceptionId;

  try {
    await apiFetch(`/api/availability/${exceptionId}`, {
      method: 'DELETE',
    });

    root.querySelector(`[data-exception-id="${exceptionId}"]`)?.remove();
    showToast('Demande annulée.', 'success');
  } catch (error) {
    showToast(error.message, 'error');

    if (error.status === 409) {
      root.querySelector(`[data-exception-id="${exceptionId}"]`)?.remove();
    }
  }
}

export async function handleUpdateSubmit(event, root = document) {
  event.preventDefault();
  const form = event.target;
  const exceptionId = form.dataset.exceptionId;
  const formData = new FormData(form);

  try {
    await apiFetch(`/api/availability/${exceptionId}`, {
      method: 'PATCH',
      body: JSON.stringify({
        occurrenceDate: formData.get('occurrenceDate'),
        reason: formData.get('reason') || null,
      }),
    });

    showToast('Demande modifiée.', 'success');
  } catch (error) {
    showToast(error.message, 'error');
  }
}

export function initAvailability(root = document) {
  root.querySelector('[data-current-group-select]')?.addEventListener('change', (event) => {
    event.target.dataset.currentGroupId = event.target.value;
  });

  root.addEventListener('click', (event) => {
    const respondButton = event.target.closest('[data-respond-button]');
    if (respondButton) {
      handleRespond(respondButton, root);
    }

    const cancelButton = event.target.closest('[data-cancel-button]');
    if (cancelButton) {
      handleCancel(cancelButton, root);
    }
  });

  root.querySelector('[data-request-form]')?.addEventListener('submit', (event) => {
    handleRequestSubmit(event, root);
  });

  root.querySelectorAll('[data-update-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      handleUpdateSubmit(event, root);
    });
  });
}
