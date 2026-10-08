/**
 * Dashboard disponibilités — rendu initial server-side, seules les actions
 * (répondre, modifier, annuler une demande) passent en XHR : le DOM est
 * patché en place, jamais de rechargement de page. Cas 409 (déjà répondue) :
 * toast d'erreur + retrait de la carte pour resynchroniser sans reload complet.
 *
 * Les cartes sont des <rb-request-card> (#330) : elles émettent `request:respond`, `request:cancel` et `request:update` ; ce module
 * parle à l'API. Pendant l'appel la carte est occupée (`busy`) : un double clic sur « Accepter » n'envoie plus deux requêtes.
 */
import { apiFetch } from '../core/api.js';
import { showToast } from '../core/toast.js';
import { refreshExceptionalPlanning } from './dashboard/exceptional-planning.js';
import { renumberDeck } from './dashboard/exception-deck.js';

function removeExceptionCard(root, exceptionId) {
  const card = root.querySelector(`rb-request-card[exception-id="${exceptionId}"]`);
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

/** Occupe la carte pendant un appel (les boutons sont désactivés), même si la carte n'est pas fournie (tests, appels directs). */
async function whileBusy(card, work) {
  if (card) {
    card.busy = true;
  }
  try {
    await work();
  } finally {
    if (card) {
      card.busy = false;
    }
  }
}

/** @param {{id: string, accepted: boolean, occurrenceDate: string}} request la date est celle que le titulaire a sous les yeux */
export async function handleRespond({ id, accepted, occurrenceDate }, root = document, card = null) {
  await whileBusy(card, async () => {
    try {
      // Si le demandeur a changé la date entre-temps, le serveur refuse (409) plutôt que d'accepter une autre date que celle affichée (#221).
      await apiFetch(`/api/availability/${id}/respond`, {
        method: 'POST',
        body: JSON.stringify({ accepted, occurrenceDate }),
      });

      removeExceptionCard(root, id);
      showToast(accepted ? 'Demande acceptée.' : 'Demande refusée.', 'success');

      if (accepted) {
        await refreshExceptionalPlanning(root);
      }
    } catch (error) {
      showToast(error.message, 'error');

      if (error.status === 409) {
        removeExceptionCard(root, id);
      }
    }
  });
}

export async function handleCancel({ id }, root = document, card = null) {
  await whileBusy(card, async () => {
    try {
      await apiFetch(`/api/availability/${id}`, {
        method: 'DELETE',
      });

      removeExceptionCard(root, id);
      showToast('Demande annulée.', 'success');
    } catch (error) {
      showToast(error.message, 'error');

      if (error.status === 409) {
        removeExceptionCard(root, id);
      }
    }
  });
}

export async function handleUpdate({ id, occurrenceDate, reason }, root = document, card = null) {
  await whileBusy(card, async () => {
    try {
      await apiFetch(`/api/availability/${id}`, {
        method: 'PATCH',
        body: JSON.stringify({ occurrenceDate, reason: reason || null }),
      });

      showToast('Demande modifiée.', 'success');
    } catch (error) {
      showToast(error.message, 'error');
    }
  });
}

export function initAvailability(root = document) {
  root.querySelector('[data-current-group-select]')?.addEventListener('change', (event) => {
    event.target.dataset.currentGroupId = event.target.value;
  });

  root.addEventListener('request:respond', (event) => handleRespond(event.detail, root, event.target));
  root.addEventListener('request:cancel', (event) => handleCancel(event.detail, root, event.target));
  root.addEventListener('request:update', (event) => handleUpdate(event.detail, root, event.target));
}
