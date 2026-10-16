/**
 * Pastille « à valider » du lien Admin du menu et de l'onglet Réservations (#263) : chargée à l'ouverture, rafraîchie à la minute tant que
 * l'onglet est visible (même patron que la pastille des messages). Absente du HTML pour les non-administrateurs.
 */
import { apiFetch } from '../../core/api.js';
import { isAbort, sleep, whenVisible } from '../../messaging/chat/async.js';
import { pendingCount } from './booking-review.js';

export function initBookingsBadge(root = document) {
  const badges = [...root.querySelectorAll('[data-bookings-badge]')];
  if (badges.length === 0) {
    return;
  }
  const show = (count) => {
    for (const badge of badges) {
      badge.textContent = String(count);
      badge.hidden = count === 0;
    }
  };

  (async () => {
    const controller = new AbortController();
    while (!controller.signal.aborted) {
      try {
        await whenVisible(document, controller.signal);
        const count = pendingCount(await apiFetch('/api/admin/bookings', { signal: controller.signal }));
        show(count);
        await sleep(60000, controller.signal);
      } catch (error) {
        if (isAbort(error)) {
          return;
        }
        show(0);
        await sleep(60000, controller.signal).catch(() => {});
      }
    }
  })();
}
