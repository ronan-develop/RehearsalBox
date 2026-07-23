/**
 * Dashboard disponibilités — rendu initial server-side (cf. plan §5bis),
 * seule l'action de revendication passe en XHR : le DOM est patché en place
 * (retrait de la carte), jamais de rechargement de page. Cas 409 (déjà
 * pris) : toast d'erreur + retrait de la carte pour resynchroniser sans
 * reload complet.
 */
import { apiFetch } from './api.js';
import { showToast } from './toast.js';

export function getCurrentGroupId(root = document) {
  const select = root.querySelector('[data-current-group-select]');
  return select ? select.value : root.querySelector('[data-current-group-id]')?.dataset.currentGroupId;
}

export async function handleClaim(button, root = document) {
  const exceptionId = button.dataset.exceptionId;
  const groupId = getCurrentGroupId(root);

  try {
    await apiFetch(`/api/availability/${exceptionId}/claim`, {
      method: 'POST',
      body: JSON.stringify({ groupId: Number(groupId) }),
    });

    root.querySelector(`[data-exception-id="${exceptionId}"]`)?.remove();
    showToast('Créneau revendiqué avec succès.', 'success');
  } catch (error) {
    showToast(error.message, 'error');

    if (error.status === 409) {
      root.querySelector(`[data-exception-id="${exceptionId}"]`)?.remove();
    }
  }
}

export function initAvailability(root = document) {
  root.querySelector('[data-current-group-select]')?.addEventListener('change', (event) => {
    event.target.dataset.currentGroupId = event.target.value;
  });

  root.addEventListener('click', (event) => {
    const button = event.target.closest('[data-claim-button]');
    if (button) {
      handleClaim(button, root);
    }
  });
}
