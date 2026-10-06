/**
 * <rb-mute-toggle> : la cloche de sourdine d'une ligne de la liste des conversations (#210). Rendue par le serveur (le
 * bouton, son état aria-pressed et ses libellés sont déjà dans le HTML) ; le composant ne parle jamais à l'API : il émet
 * conversation:mute-request, <rb-chat> appelle l'API puis rafraîchit la liste. Pendant l'appel il est occupé (aria-busy) et ignore
 * les taps répétés. Cycle de vie : écouteur posé à la connexion, retiré à la déconnexion (la liste est remplacée à chaque rafraîchissement).
 */
import { emit, EVT } from './events.js';
import { labelFor, nextMuted } from './mute.js';

export class RbMuteToggle extends HTMLElement {
  #button = null;

  #onClick = () => {
    if (this.hasAttribute('aria-busy')) {
      return;
    }
    this.setAttribute('aria-busy', 'true');
    emit(this, EVT.MUTE_REQUEST, { id: this.dataset.id, muted: nextMuted(this.#button.getAttribute('aria-pressed')) });
  };

  connectedCallback() {
    this.#button = this.querySelector('button');
    this.#button?.addEventListener('click', this.#onClick);
  }

  disconnectedCallback() {
    this.#button?.removeEventListener('click', this.#onClick);
    this.#button = null;
  }

  /** Affiche l'état confirmé par le serveur et lève l'occupation. */
  set muted(value) {
    if (this.#button === null) {
      return;
    }
    this.#button.setAttribute('aria-pressed', value ? 'true' : 'false');
    this.#button.setAttribute('aria-label', labelFor(this.dataset, value));
    this.closest('.rb-chat-item')?.classList.toggle('rb-chat-item--muted', value);
    this.removeAttribute('aria-busy');
  }

  /** Échec : l'état affiché ne change pas, la cloche redevient utilisable. */
  release() {
    this.removeAttribute('aria-busy');
  }
}

if (!customElements.get('rb-mute-toggle')) {
  customElements.define('rb-mute-toggle', RbMuteToggle);
}
