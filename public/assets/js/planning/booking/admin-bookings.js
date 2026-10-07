/**
 * Page admin « Réservations » (#263) : écoute la décision émise par chaque <rb-booking-card>, appelle l'API (apiFetch, CSRF) puis
 * retire la carte. Le composant ne parle jamais à l'API ; la logique pure (requête, messages) est dans booking-review.js.
 */
import { apiFetch } from '../../core/api.js';
import { showToast } from '../../core/toast.js';
import { decisionRequest, failureMessage, isAlreadyDecided, outcomeMessage } from './booking-review.js';
import { DECIDE_EVENT } from './rb-booking-card.js';

export function initAdminBookings(root = document) {
  const list = root.querySelector('[data-booking-list]');
  if (list === null) {
    return;
  }
  const empty = root.querySelector('[data-booking-empty]');
  const syncEmpty = () => {
    if (empty !== null) {
      empty.hidden = list.querySelector('rb-booking-card') !== null;
    }
  };

  list.addEventListener(DECIDE_EVENT, async (event) => {
    const card = event.target.closest('rb-booking-card');
    const { id, decision, note } = event.detail;
    if (card === null) {
      return;
    }
    card.setBusy(true);
    try {
      const { path, options } = decisionRequest(id, decision, note);
      await apiFetch(path, options);
      showToast(outcomeMessage(decision), 'success');
      card.remove();
      syncEmpty();
    } catch (error) {
      card.setBusy(false);
      if (isAlreadyDecided(error)) {
        showToast(failureMessage(error), 'error');
        card.remove();
        syncEmpty();

        return;
      }
      card.showError(failureMessage(error));
    }
  });
}
