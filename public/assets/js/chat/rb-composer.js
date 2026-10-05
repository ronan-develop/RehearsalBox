/**
 * <rb-composer> : zone de saisie (champ + envoi). Entrée envoie, Maj+Entrée = retour à la ligne, le champ grandit avec le
 * texte. Émet composer:submit { text } et composer:typing (limité à un signal toutes les 3 s). Ne connaît ni l'API ni
 * la conversation : le même composant sert à un fil existant et à un brouillon.
 */
import { EVT, emit } from './events.js';
import { shouldSendTyping } from './model.js';

export class RbComposer extends HTMLElement {
  #lastTypingSent = null;

  connectedCallback() {
    this.form = this.querySelector('form');
    this.field = this.form.elements.message;

    this.form.addEventListener('submit', (event) => {
      event.preventDefault();
      this.#send();
    });
    this.field.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        this.#send();
      }
    });
    this.field.addEventListener('input', () => {
      this.#autosize();
      if (this.field.value.trim() !== '' && shouldSendTyping(this.#lastTypingSent, Date.now())) {
        this.#lastTypingSent = Date.now();
        emit(this, EVT.TYPING);
      }
    });
  }

  /** Remet le texte (envoi échoué) pour que rien ne soit perdu. */
  restore(text) {
    this.field.value = text;
    this.#autosize();
    this.field.focus();
  }

  focus() {
    this.field?.focus();
  }

  #send() {
    const text = this.field.value.trim();
    if (text === '') {
      return;
    }
    this.field.value = '';
    this.#autosize();
    emit(this, EVT.SUBMIT, { text });
  }

  #autosize() {
    this.field.style.height = 'auto';
    this.field.style.height = `${Math.min(this.field.scrollHeight, 140)}px`;
  }
}

if (!customElements.get('rb-composer')) {
  customElements.define('rb-composer', RbComposer);
}
