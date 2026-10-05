/**
 * <rb-sidebar> : la liste des conversations est rendue par le serveur (de vrais liens) ; ce composant ne fait que la
 * rafraîchir en direct : un fragment HTML toutes les 30 s tant que l'onglet est visible, issu du même gabarit PHP. Sans
 * JS la liste reste lisible et les liens fonctionnent.
 */
import { fetchListFragment } from './api.js';
import { isAbort, sleep, whenVisible } from './async.js';

const REFRESH_MS = 30000;

export class RbSidebar extends HTMLElement {
  #lifetime = new AbortController();

  connectedCallback() {
    this.listEl = this.querySelector('[data-chat-list]');
    this.emptyEl = this.querySelector('[data-chat-empty]');
    this.badge = this.querySelector('[data-chat-archives-unread]');
    this.#loop(this.#lifetime.signal);
  }

  disconnectedCallback() {
    this.#lifetime.abort();
  }

  /** Rafraîchit la liste maintenant (après un envoi, par exemple). */
  async refresh(signal) {
    const box = this.dataset.box ?? 'active';
    const activeId = this.closest('[data-chat]')?.dataset.activeId || null;
    const { html, empty, archivedUnread } = await fetchListFragment(box, activeId, { signal });
    this.listEl.innerHTML = html;
    this.emptyEl.hidden = !empty;
    if (this.badge) {
      this.badge.textContent = String(archivedUnread);
      this.badge.hidden = archivedUnread === 0;
    }
  }

  async #loop(signal) {
    while (!signal.aborted) {
      try {
        await sleep(REFRESH_MS, signal);
        await whenVisible(document, signal);
        await this.refresh(signal);
      } catch (error) {
        if (isAbort(error)) {
          return;
        }
      }
    }
  }
}

if (!customElements.get('rb-sidebar')) {
  customElements.define('rb-sidebar', RbSidebar);
}
