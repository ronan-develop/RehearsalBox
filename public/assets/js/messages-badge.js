/**
 * Pastille « non lu » du lien Messages (dashboard) : chargée à l'ouverture, rafraîchie à la minute tant que l'onglet est
 * visible. Une boucle async/await comme celles de la messagerie.
 */
import { fetchList } from './chat/api.js';
import { isAbort, sleep, whenVisible } from './chat/async.js';

export function initMessagesBadge(root = document) {
  const badge = root.querySelector('[data-messages-link-badge]');
  if (!badge) {
    return;
  }

  (async () => {
    const controller = new AbortController();
    while (!controller.signal.aborted) {
      try {
        await whenVisible(document, controller.signal);
        const { unread } = await fetchList('active', { signal: controller.signal });
        badge.textContent = String(unread.total);
        badge.hidden = unread.total === 0;
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
