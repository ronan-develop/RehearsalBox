/**
 * <rb-member-filter> : filtre par nom la liste « Nouveau message » (#269), rendue par le serveur (vrais liens). Améliore le
 * HTML existant en Light DOM : masque les lignes qui ne correspondent pas et affiche « Aucun membre » si plus aucune ne reste.
 * La logique de correspondance est pure et testée à part (../directory/filter.js).
 */
import { visibility } from '../directory/filter.js';

if (typeof HTMLElement !== 'undefined') {
  class RbMemberFilter extends HTMLElement {
    #lifetime = new AbortController();

    connectedCallback() {
      this.input = this.querySelector('[data-member-filter-input]');
      this.rows = [...this.querySelectorAll('[data-member-filter-list] [data-member-name]')];
      this.empty = this.querySelector('[data-member-filter-empty]');
      this.input?.addEventListener('input', () => this.#apply(), { signal: this.#lifetime.signal });
    }

    disconnectedCallback() {
      this.#lifetime.abort();
      this.#lifetime = new AbortController();
    }

    #apply() {
      const { flags, visible } = visibility(this.rows.map((row) => row.dataset.memberName ?? ''), this.input.value);
      this.rows.forEach((row, index) => {
        row.hidden = !flags[index];
      });
      if (this.empty) {
        this.empty.hidden = visible > 0;
      }
    }
  }

  if (!customElements.get('rb-member-filter')) {
    customElements.define('rb-member-filter', RbMemberFilter);
  }
}
