/**
 * <rb-message-list> : le fil en bulles (les miennes à droite, les autres à gauche avec leur pastille). Composant de
 * présentation : il reçoit des messages et les dessine, il ne charge rien. Le texte n'est jamais inséré en HTML.
 */
import { renderMessages } from './view.js';

export class RbMessageList extends HTMLElement {
  connectedCallback() {
    this.list = this.querySelector('.rb-chat-messages-list');
    if (!this.list) {
      this.list = document.createElement('ol');
      this.list.className = 'rb-chat-messages-list';
      this.append(this.list);
    }
  }

  render(messages, { pending = [], firstUnreadId = null } = {}) {
    renderMessages(this.list, messages, { pending, firstUnreadId });
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
