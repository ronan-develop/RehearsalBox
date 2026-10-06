/**
 * Dashboard disponibilités — rendu initial server-side, seules les actions
 * (répondre, modifier, annuler une demande) passent en XHR : le DOM est
 * patché en place, jamais de rechargement de page. Cas 409 (déjà répondue) :
 * toast d'erreur + retrait de la carte pour resynchroniser sans reload complet.
 */
import { apiFetch } from './api.js';
import { showToast } from './toast.js';
import { refreshExceptionalPlanning } from './planning-slider.js';
import { renumberDeck } from './exception-deck.js';

function removeExceptionCard(root, exceptionId) {
  const card = root.querySelector(`[data-exception-id="${exceptionId}"]`);
  const deck = card?.closest('[data-exception-deck]');
  card?.remove();
  if (deck) {
    renumberDeck(deck);
  }
}

export function getCurrentGroupId(root = document) {
  const select = root.querySelector('[data-current-group-select]');
  return select ? select.value : root.querySelector('[data-current-group-id]')?.dataset.currentGroupId;
}

export async function handleRespond(button, root = document) {
  const exceptionId = button.dataset.exceptionId;
  const accepted = button.dataset.accepted === 'true';
  // La date que le titulaire a sous les yeux : si le demandeur l'a changée entre-temps, le serveur refuse (409) plutôt que
  // d'accepter une autre date que celle affichée (#221).
  const occurrenceDate = button.dataset.occurrenceDate;

  try {
    await apiFetch(`/api/availability/${exceptionId}/respond`, {
      method: 'POST',
      body: JSON.stringify({ accepted, occurrenceDate }),
    });

    removeExceptionCard(root, exceptionId);
    showToast(accepted ? 'Demande acceptée.' : 'Demande refusée.', 'success');

    if (accepted) {
      await refreshExceptionalPlanning(root);
    }
  } catch (error) {
    showToast(error.message, 'error');

    if (error.status === 409) {
      removeExceptionCard(root, exceptionId);
    }
  }
}

export async function handleCancel(button, root = document) {
  const exceptionId = button.dataset.exceptionId;

  try {
    await apiFetch(`/api/availability/${exceptionId}`, {
      method: 'DELETE',
    });

    removeExceptionCard(root, exceptionId);
    showToast('Demande annulée.', 'success');
  } catch (error) {
    showToast(error.message, 'error');

    if (error.status === 409) {
      removeExceptionCard(root, exceptionId);
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

  root.querySelectorAll('[data-update-form]').forEach((form) => {
    form.addEventListener('submit', (event) => {
      handleUpdateSubmit(event, root);
    });
  });
}
