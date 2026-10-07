/**
 * <rb-message-list> : le fil en bulles, dessiné par le serveur. Le composant n'ajoute que le défilement et l'ajout des
 * nouveaux messages : le HTML reçu vient du même gabarit PHP que la page (tout contenu d'utilisateur y est échappé par
 * e()) ; le navigateur ne construit jamais une bulle à partir de texte.
 */
import { EVT, emit } from '../events.js';
import { isNearBottom } from '../thread/model.js';
import { quoteFromRow } from '../composer/quote.js';
import './rb-message-actions.js';
import { tappedRow } from '../thread/message-actions.js';

const FLASH_CLASS = 'rb-chat-message--flash';
const FLASH_MS = 1600;

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
    this.#wireQuoting();
    this.#wireTapActions();
  }

  /**
   * Écran tactile (#253, #257) : un tap sur une bulle INSÈRE le composant <rb-message-actions> dans sa ligne (un nouveau tap sur la
   * même bulle le retire, un tap sur une autre le déplace). Le composant gère seul sa fermeture. Ordinateur : survol, rien à câbler.
   */
  #wireTapActions() {
    if (!window.matchMedia?.('(hover: none)').matches) {
      return;
    }
    this.addEventListener('pointerup', (event) => {
      const row = tappedRow(event);
      if (row === null) {
        return;
      }
      const open = this.querySelector('rb-message-actions');
      open?.remove();
      if (open?.parentElement !== row) {
        row.append(document.createElement('rb-message-actions'));
      }
    });
  }

  /**
   * Corriger son message (#200, #253) : un bouton, au survol ou au clavier sur ordinateur, et au tap sur la bulle sur écran
   * tactile (<rb-message-actions>) pour ses propres bulles récentes (`data-editable`, posé par le serveur). Le composant ne fait que
   * demander (message:edit-request) : la saisie et l'appel à l'API sont ailleurs.
   */
  #wireEditing() {
    this.addEventListener('click', (event) => {
      const button = event.target.closest('[data-edit-message]');
      if (button !== null) {
        this.#requestEdit(button.closest('.rb-chat-message'));
      }
    });
  }

  #requestEdit(row) {
    const text = row?.querySelector('.rb-chat-text')?.textContent;
    if (row?.dataset.messageId && typeof text === 'string') {
      emit(this, EVT.EDIT_REQUEST, { id: row.dataset.messageId, text });
    }
  }

  /**
   * Citer un message (#214, #253) : un bouton « Répondre », au survol ou au clavier sur ordinateur, et au tap sur la bulle sur
   * écran tactile (<rb-message-actions>). Le composant ne fait que demander (message:quote-request). Un clic sur la citation d'une
   * bulle amène le message cité à l'écran ; sans JavaScript, le lien (ancre) fait le même trajet.
   */
  #wireQuoting() {
    this.addEventListener('click', (event) => {
      const button = event.target.closest('[data-quote-message]');
      if (button !== null) {
        this.#requestQuote(button.closest('.rb-chat-message'));

        return;
      }
      const link = event.target.closest('.rb-chat-quote');
      if (link !== null) {
        const target = this.querySelector(`#${CSS.escape(link.getAttribute('href').slice(1))}`);
        if (target !== null) {
          event.preventDefault();
          this.#reveal(target);
        }
      }
    });
  }

  #requestQuote(row) {
    const quote = quoteFromRow(row);
    if (quote !== null) {
      emit(this, EVT.QUOTE_REQUEST, quote);
    }
  }

  #reveal(row) {
    const calm = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
    row.scrollIntoView({ block: 'center', behavior: calm ? 'auto' : 'smooth' });
    row.classList.remove(FLASH_CLASS);
    void row.offsetWidth; // relance l'animation si on cite deux fois de suite le même message
    row.classList.add(FLASH_CLASS);
    window.setTimeout(() => row.classList.remove(FLASH_CLASS), FLASH_MS);
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
