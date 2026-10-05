/**
 * <rb-message-list> : le fil en bulles, dessiné par le serveur. Le composant n'ajoute que le défilement et l'ajout des
 * nouveaux messages : le HTML reçu vient du même gabarit PHP que la page (tout contenu d'utilisateur y est échappé par
 * e()) ; le navigateur ne construit jamais une bulle à partir de texte.
 */
import { EVT, emit } from './events.js';
import { createLongPress } from './longpress.js';
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
    this.#wireEditing();
  }

  /**
   * Corriger son message (#200) : un bouton (survol ou clavier) et, sur mobile, un appui long sur ses propres bulles
   * récentes (`data-editable`, posé par le serveur). Le composant ne fait que demander (message:edit-request) : la saisie et
   * l'appel à l'API sont ailleurs.
   */
  #wireEditing() {
    const longPress = createLongPress({ onLongPress: ({ target }) => this.#requestEdit(target) });
    const editable = (event) => (event.pointerType === 'touch' ? event.target.closest('.rb-chat-message[data-editable]') : null);

    this.addEventListener('pointerdown', (event) => {
      const row = editable(event);
      if (row !== null) {
        longPress.start(event.clientX, event.clientY, row);
      }
    });
    this.addEventListener('pointermove', (event) => longPress.move(event.clientX, event.clientY), { passive: true });
    this.addEventListener('pointerup', () => longPress.end());
    this.addEventListener('pointercancel', () => longPress.cancel());
    // Après un appui long, ni menu du système ni clic parasite au relâchement.
    this.addEventListener('contextmenu', (event) => longPress.consumed() && event.preventDefault());
    this.addEventListener('click', (event) => {
      if (longPress.consumed()) {
        event.preventDefault();
        event.stopPropagation();

        return;
      }
      const button = event.target.closest('[data-edit-message]');
      if (button !== null) {
        this.#requestEdit(button.closest('.rb-chat-message'));
      }
    }, true);
  }

  #requestEdit(row) {
    const text = row?.querySelector('.rb-chat-text')?.textContent;
    if (row?.dataset.messageId && typeof text === 'string') {
      emit(this, EVT.EDIT_REQUEST, { id: row.dataset.messageId, text });
    }
  }

  /** Remplace le corps d'une bulle par le fragment corrigé (dessiné par le même gabarit PHP que la page). */
  replaceBody(messageId, html) {
    const body = this.querySelector(`.rb-chat-message[data-message-id="${CSS.escape(String(messageId))}"] [data-message-body]`);
    if (body !== null) {
      body.innerHTML = html;
    }
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
