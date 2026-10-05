/**
 * <rb-message-list> : le fil en bulles, dessiné par le serveur. Le composant n'ajoute que le défilement et l'ajout des
 * nouveaux messages : le HTML reçu vient du même gabarit PHP que la page (tout contenu d'utilisateur y est échappé par
 * e()) ; le navigateur ne construit jamais une bulle à partir de texte.
 */
import { isNearBottom } from './model.js';

export class RbMessageList extends HTMLElement {
  connectedCallback() {
    this.list = this.querySelector('.rb-chat-messages-list');
    // Le bouton « ↓ Nouveaux messages » (#187) est posé par le gabarit à côté du fil, pas dedans.
    this.hint = this.closest('[data-chat-thread]')?.querySelector('[data-chat-new-messages]') ?? null;
    this.hint?.addEventListener('click', () => {
      this.scrollToBottom();
      this.#hideHint();
    });
    this.addEventListener('scroll', () => this.nearBottom() && this.#hideHint(), { passive: true });
  }

  /** Des messages sont arrivés pendant qu'on relit l'historique : on les signale au lieu de forcer le défilement. */
  showNewMessagesHint() {
    if (this.hint) {
      this.hint.hidden = false;
    }
  }

  #hideHint() {
    if (this.hint) {
      this.hint.hidden = true;
    }
  }

  /** Ajoute à la fin des lignes déjà dessinées par le serveur (fragment HTML). */
  append(html) {
    if (html.trim() === '') {
      return;
    }
    this.list.insertAdjacentHTML('beforeend', html);
  }

  /** Le fil est ancré en bas par le CSS (column-reverse) : le bas est l'origine du défilement. */
  nearBottom() {
    return isNearBottom(this.scrollTop);
  }

  scrollToBottom() {
    this.scrollTop = 0;
    this.#hideHint();
  }

  /** Amène le séparateur « Messages non lus » en haut ; false s'il n'y en a pas. */
  scrollToUnread() {
    const marker = this.querySelector('.rb-chat-unread');
    marker?.scrollIntoView({ block: 'start' });

    return marker !== null;
  }
}

if (!customElements.get('rb-message-list')) {
  customElements.define('rb-message-list', RbMessageList);
}
