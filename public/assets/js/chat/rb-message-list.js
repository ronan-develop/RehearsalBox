/**
 * <rb-message-list> : le fil en bulles, dessiné par le serveur. Le composant n'ajoute que le défilement et l'ajout des
 * nouveaux messages : le HTML reçu vient du même gabarit PHP que la page (tout contenu d'utilisateur y est échappé par
 * e()) ; le navigateur ne construit jamais une bulle à partir de texte.
 */
export class RbMessageList extends HTMLElement {
  connectedCallback() {
    this.list = this.querySelector('.rb-chat-messages-list');
  }

  /** Ajoute à la fin des lignes déjà dessinées par le serveur (fragment HTML). */
  append(html) {
    if (html.trim() === '') {
      return;
    }
    this.list.insertAdjacentHTML('beforeend', html);
  }

  nearBottom() {
    return this.scrollHeight - this.scrollTop - this.clientHeight < 80;
  }

  scrollToBottom() {
    this.scrollTop = this.scrollHeight;
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
