/**
 * <rb-sidebar> : la liste des conversations est rendue par le serveur (de vrais liens) ; ce composant ne fait que la
 * rafraîchir en direct : un fragment HTML toutes les 30 s tant que l'onglet est visible, issu du même gabarit PHP. Sans
 * JS la liste reste lisible et les liens fonctionnent.
 */
import { fetchListFragment } from './api.js';
import { isAbort, sleep, whenVisible } from './async.js';
import { createScrollMemory, sessionStorageOrNull } from './scroll-memory.js';

const REFRESH_MS = 30000;

export class RbSidebar extends HTMLElement {
  #lifetime = new AbortController();

  connectedCallback() {
    this.listEl = this.querySelector('[data-chat-list]');
    this.emptyEl = this.querySelector('[data-chat-empty]');
    this.badge = this.querySelector('[data-chat-archives-unread]');
    this.#keepScrollPosition();
    this.#loop(this.#lifetime.signal);
  }

  disconnectedCallback() {
    this.#lifetime.abort();
  }

  /** Rafraîchit la liste maintenant (après un envoi, par exemple). */
  async refresh(signal) {
    // Une ligne est en cours de glissement ou ouverte (#202) : on ne la remplace pas sous le doigt.
    if (this.listEl.querySelector('.rb-chat-item--dragging, .rb-chat-item--open')) {
      return;
    }
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

  /**
   * Position de la liste conservée à travers la navigation (#187) : restaurée à l'affichage, enregistrée quand on défile
   * et quand on quitte la page. Sur mobile la liste est masquée pendant qu'un fil est ouvert : on n'enregistre alors rien.
   */
  #keepScrollPosition() {
    const memory = createScrollMemory(sessionStorageOrNull());
    const name = this.dataset.box ?? 'active';
    const saved = memory.load(name);
    if (saved !== null) {
      this.listEl.scrollTop = saved;
    }
    const save = () => this.listEl.clientHeight > 0 && memory.save(name, this.listEl.scrollTop);
    let timer = 0;
    this.listEl.addEventListener('scroll', () => {
      window.clearTimeout(timer);
      timer = window.setTimeout(save, 150);
    }, { passive: true });
    window.addEventListener('pagehide', save);
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
