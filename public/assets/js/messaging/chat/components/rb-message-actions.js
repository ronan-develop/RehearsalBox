/**
 * <rb-message-actions> (#253, #257) : l'action d'un message (citer ou corriger) sur écran tactile. Le composant est INSÉRÉ dans la
 * ligne au tap sur la bulle par <rb-message-list> et se RETIRE de lui-même : il ne reste jamais dans la page en attente (pas de bouton
 * caché, pas de survol collant d'iOS). Son bouton est cloné depuis le <template data-message-actions> rendu par le serveur (une seule
 * définition des icônes et libellés) ; le clic est traité comme celui du bouton de survol de la ligne, par <rb-message-list>, qui
 * émet message:quote-request / message:edit-request. Cycle de vie : écouteurs posés à la connexion, retirés à la déconnexion.
 */
import { actionsFor } from '../thread/message-actions.js';

const TEMPLATE = 'template[data-message-actions]';
const BUTTON = { quote: '[data-quote-message]', edit: '[data-edit-message]' };

export class RbMessageActions extends HTMLElement {
  #row = null;
  #list = null;

  // Un toucher hors de la ligne, un défilement ou Échap referment ; propriétés fléchées : même référence pour retirer l'écouteur.
  #onOutsideTap = (event) => {
    if ((event.pointerType === 'touch' || event.pointerType === 'pen') && !this.#row.contains(event.target)) {
      this.remove();
    }
  };

  #onKey = (event) => {
    if (event.key === 'Escape') {
      this.remove();
    }
  };

  #onClose = () => this.remove();

  // Le clic agit d'abord (écouteur de <rb-message-list> plus haut sur le chemin du clic) : on se retire une fois l'évènement
  // terminé, ligne encore en place (un microtask passerait entre deux écouteurs et retirerait le bouton avant la liste).
  #onAction = () => setTimeout(() => this.remove(), 0);

  connectedCallback() {
    this.#row = this.closest('.rb-chat-message[data-message-id]');
    const template = document.querySelector(TEMPLATE);
    if (this.#row === null || template === null) {
      this.remove();

      return;
    }

    const { side, keep } = actionsFor({
      mine: this.#row.classList.contains('rb-chat-message--mine'),
      editable: this.#row.hasAttribute('data-editable'),
    });
    const button = template.content.querySelector(BUTTON[keep])?.cloneNode(true);
    if (button === undefined) {
      this.remove();

      return;
    }
    this.dataset.side = side;
    this.replaceChildren(button);

    this.addEventListener('click', this.#onAction);
    document.addEventListener('pointerup', this.#onOutsideTap);
    document.addEventListener('keydown', this.#onKey);
    this.#list = this.#row.closest('rb-message-list');
    this.#list?.addEventListener('scroll', this.#onClose, { passive: true });
  }

  disconnectedCallback() {
    this.removeEventListener('click', this.#onAction);
    document.removeEventListener('pointerup', this.#onOutsideTap);
    document.removeEventListener('keydown', this.#onKey);
    this.#list?.removeEventListener('scroll', this.#onClose);
    this.#list = null;
  }
}

if (!customElements.get('rb-message-actions')) {
  customElements.define('rb-message-actions', RbMessageActions);
}
