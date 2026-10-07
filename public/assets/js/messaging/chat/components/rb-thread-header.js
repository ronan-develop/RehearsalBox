/**
 * <rb-thread-header> : en-tête d'une conversation, rendu par le serveur. Mode brouillon (page « nouvelle conversation » :
 * intitulé fixe + choix du groupe émetteur) ou mode fil (titre que l'on peut modifier au toucher, label des deux groupes).
 * Émet header:rename { title } ; le retour est un vrai lien ; ne connaît pas l'API.
 */
import { EVT, emit } from '../events.js';

export class RbThreadHeader extends HTMLElement {
  connectedCallback() {
    const $ = (selector) => this.querySelector(selector);
    this.draftParts = [...this.querySelectorAll('[data-chat-draft-part]')];
    this.threadParts = [...this.querySelectorAll('[data-chat-thread-part]')];
    this.titleBtn = $('[data-chat-title]');
    this.renameForm = $('[data-chat-rename-form]');
    this.labelEl = $('[data-chat-label]');
    this.senderSelect = $('[data-chat-sender]');
    this.currentTitle = this.titleBtn.dataset.title ?? '';

    this.titleBtn.addEventListener('click', () => this.#openRename());
    this.renameForm.addEventListener('submit', (event) => {
      event.preventDefault();
      emit(this, EVT.RENAME, { title: this.renameForm.title.value });
    });
    this.renameForm.title.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') {
        this.closeRename();
      }
    });
  }

  /** Brouillon : intitulé fixe et choix de l'émetteur ; sinon : titre modifiable. */
  setDraft(isDraft) {
    this.draftParts.forEach((part) => { part.hidden = !isDraft || (part.dataset.chatDraftPart === 'sender' && this.senderSelect.options.length < 2); });
    this.threadParts.forEach((part) => { part.hidden = isDraft; });
    if (!isDraft) {
      this.closeRename();
    }
  }

  setThread({ title, displayTitle, label }) {
    this.currentTitle = title ?? '';
    this.titleBtn.textContent = displayTitle;
    this.labelEl.textContent = title ? label : '';
    this.labelEl.hidden = !title;
  }

  /** Identifiant du groupe au nom duquel on écrit (brouillon). */
  get senderId() {
    return this.senderSelect?.value ?? '';
  }

  closeRename() {
    this.renameForm.hidden = true;
    this.titleBtn.hidden = false;
  }

  #openRename() {
    this.renameForm.title.value = this.currentTitle;
    this.renameForm.hidden = false;
    this.titleBtn.hidden = true;
    this.renameForm.title.focus();
  }
}

if (!customElements.get('rb-thread-header')) {
  customElements.define('rb-thread-header', RbThreadHeader);
}
