/**
 * Pastille « à valider » du lien Créneaux du menu administrateur (#263) : chargée à l'ouverture, rafraîchie à la minute tant que
 * l'onglet est visible (même patron que la pastille des messages). Absente du HTML pour les non-administrateurs.
 */
import { apiFetch } from './api.js';
import { isAbort, sleep, whenVisible } from './chat/async.js';
import { pendingCount } from './booking-review.js';

export function initBookingsBadge(root = document) {
  const badge = root.querySelector('[data-bookings-badge]');
  if (badge === null) {
    return;
  }

  (async () => {
    const controller = new AbortController();
    while (!controller.signal.aborted) {
      try {
        await whenVisible(document, controller.signal);
        const count = pendingCount(await apiFetch('/api/admin/bookings', { signal: controller.signal }));
        badge.textContent = String(count);
        badge.hidden = count === 0;
        await sleep(60000, controller.signal);
      } catch (error) {
        if (isAbort(error)) {
          return;
        }
        badge.hidden = true;
        await sleep(60000, controller.signal).catch(() => {});
      }
    }
  })();
}
