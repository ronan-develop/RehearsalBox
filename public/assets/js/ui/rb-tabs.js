/**
 * <rb-tabs> (#328) — onglets autonomes (Light DOM) qui AMÉLIORENT un balisage ARIA rendu par le serveur :
 *
 *   <rb-tabs persist="clé-facultative" hide-inactive>
 *     <div role="tablist"><button role="tab" aria-controls="p1" aria-selected="true" data-tab="nom">…</button>…</div>
 *     <section role="tabpanel" id="p1">…</section> …
 *   </rb-tabs>
 *
 * Aucun sélecteur global : le composant ne regarde que ses propres onglets et ses propres panneaux (reliés par `aria-controls`),
 * deux jeux d'onglets sur une même page ne se voient pas. Il pose `aria-selected` et un tabindex « itinérant », gère les flèches,
 * Début et Fin, marque le panneau actif `data-active` (le CSS décide de ce qu'il masque) ou, avec `hide-inactive`, le masque par
 * `hidden`. `data-ready` n'est posé que par le composant : sans JavaScript, le CSS laisse tout visible. Avec `persist`, le dernier
 * onglet est mémorisé sur l'appareil (facultatif). Émet `tabs:change` { name, index } (remonte).
 */
import { initialTabIndex, nextTabIndex, readStoredTab, writeStoredTab } from './tabs.js';

// HTMLElement/customElements n'existent pas sous node --test (pas de DOM) : la classe n'est déclarée que dans un navigateur.
export let RbTabs;

if (typeof HTMLElement !== 'undefined') {
  RbTabs = class extends HTMLElement {
    #tabs = [];

    connectedCallback() {
      this.#tabs = Array.from(this.querySelectorAll('[role="tab"]'));
      if (this.#tabs.length === 0) {
        return;
      }
      for (const tab of this.#tabs) {
        tab.addEventListener('click', this.#onClick);
        tab.addEventListener('keydown', this.#onKeydown);
      }

      const stored = readStoredTab(globalThis.localStorage, this.getAttribute('persist'));
      this.#select(initialTabIndex(this.#tabs.map((tab) => ({ name: tab.dataset.tab ?? '', selected: tab.getAttribute('aria-selected') === 'true' })), stored));
      this.setAttribute('data-ready', '');
    }

    disconnectedCallback() {
      for (const tab of this.#tabs) {
        tab.removeEventListener('click', this.#onClick);
        tab.removeEventListener('keydown', this.#onKeydown);
      }
    }

    /** Active un onglet par son nom (`data-tab`) ; sans effet s'il n'existe pas. */
    show(name) {
      const index = this.#tabs.findIndex((tab) => tab.dataset.tab === name);
      if (index !== -1) {
        this.#select(index);
      }
    }

    #panelOf(tab) {
      const id = tab.getAttribute('aria-controls');

      return id ? this.querySelector(`#${CSS.escape(id)}`) : null;
    }

    #select(index, { focus = false } = {}) {
      const hideInactive = this.hasAttribute('hide-inactive');
      this.#tabs.forEach((tab, position) => {
        const active = position === index;
        tab.setAttribute('aria-selected', active ? 'true' : 'false');
        tab.tabIndex = active ? 0 : -1;
        const panel = this.#panelOf(tab);
        if (panel) {
          panel.toggleAttribute('data-active', active);
          if (hideInactive) {
            panel.hidden = !active;
          }
        }
      });
      if (focus) {
        this.#tabs[index].focus();
      }
      this.dispatchEvent(new CustomEvent('tabs:change', { detail: { name: this.#tabs[index].dataset.tab ?? '', index }, bubbles: true }));
    }

    #onClick = (event) => {
      const index = this.#tabs.indexOf(event.currentTarget);
      this.#select(index);
      writeStoredTab(globalThis.localStorage, this.getAttribute('persist'), this.#tabs[index].dataset.tab ?? '');
    };

    #onKeydown = (event) => {
      const next = nextTabIndex(this.#tabs.indexOf(event.currentTarget), event.key, this.#tabs.length);
      if (next === null) {
        return;
      }
      event.preventDefault();
      this.#select(next, { focus: true });
      writeStoredTab(globalThis.localStorage, this.getAttribute('persist'), this.#tabs[next].dataset.tab ?? '');
    };
  };

  if (!customElements.get('rb-tabs')) {
    customElements.define('rb-tabs', RbTabs);
  }
}
