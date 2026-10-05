/**
 * <rb-composer> : zone de saisie (champ + envoi). Entrée envoie, Maj+Entrée = retour à la ligne, le champ grandit avec le
 * texte. Émet composer:submit { text, mentions } et composer:typing (limité à un signal toutes les 3 s). Ne connaît ni
 * l'API ni la conversation : le même composant sert à un fil existant et à un brouillon.
 *
 * Mentions (#178) : taper « @ » ouvre une liste de membres (listbox accessible, clavier et toucher). La fonction de
 * recherche est fournie par <rb-chat> (`suggest`) ; sans elle, aucune suggestion. Les noms sont insérés avec textContent,
 * jamais en HTML.
 */
import { EVT, emit } from './events.js';
import { shouldSendTyping } from './model.js';
import { activeQuery, applyMention, mentionedIds, pendingGuests } from './mentions.js';

const SUGGEST_DELAY_MS = 200;
const MIN_QUERY = 2;

export class RbComposer extends HTMLElement {
  #lastTypingSent = null;
  #picks = new Map();
  #items = [];
  #active = -1;
  #timer = 0;
  #seq = 0;

  /** Fournie par <rb-chat> : (requête) => Promise<[{ id, name, groups, participant }]>. */
  suggest = null;

  connectedCallback() {
    this.form = this.querySelector('form');
    this.field = this.form.elements.message;
    this.list = this.querySelector('[data-mention-list]');
    this.notice = this.querySelector('[data-mention-notice]');

    this.form.addEventListener('submit', (event) => {
      event.preventDefault();
      this.#send();
    });
    this.field.addEventListener('keydown', (event) => {
      if (this.#handleListKey(event)) {
        return;
      }
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
      this.#refreshNotice();
      this.#onMentionInput();
    });
    this.field.addEventListener('blur', () => this.#closeList());
    // pointerdown + preventDefault : le champ garde le focus, l'option est choisie au toucher comme à la souris.
    this.list?.addEventListener('pointerdown', (event) => {
      const option = event.target.closest('[data-index]');
      if (option) {
        event.preventDefault();
        this.#select(Number(option.dataset.index));
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
    const mentions = mentionedIds(text, this.#picks);
    this.field.value = '';
    this.#autosize();
    this.#closeList();
    this.#refreshNotice();
    emit(this, EVT.SUBMIT, { text, mentions });
  }

  #onMentionInput() {
    window.clearTimeout(this.#timer);
    const typed = activeQuery(this.field.value, this.field.selectionStart ?? this.field.value.length);
    if (typed === null || typed.query.trim().length < MIN_QUERY || typeof this.suggest !== 'function') {
      this.#closeList();
      return;
    }
    this.#timer = window.setTimeout(async () => {
      const seq = ++this.#seq;
      try {
        const members = await this.suggest(typed.query.trim());
        if (seq === this.#seq) {
          this.#openList(members);
        }
      } catch {
        this.#closeList();
      }
    }, SUGGEST_DELAY_MS);
  }

  #openList(members) {
    this.#items = members;
    this.#active = members.length > 0 ? 0 : -1;
    this.list.replaceChildren(...members.map((member, index) => {
      const item = document.createElement('li');
      item.role = 'option';
      item.id = `rb-mention-${index}`;
      item.dataset.index = String(index);
      item.className = 'rb-chat-mention-option';
      const name = document.createElement('span');
      name.className = 'rb-chat-mention-name';
      name.textContent = member.name;
      const meta = document.createElement('span');
      meta.className = 'rb-chat-mention-meta';
      meta.textContent = [member.groups, member.participant ? '' : 'sera ajouté'].filter(Boolean).join(' · ');
      item.append(name, meta);
      return item;
    }));
    this.list.hidden = members.length === 0;
    this.#paintActive();
  }

  #closeList() {
    window.clearTimeout(this.#timer);
    this.#seq += 1;
    this.#items = [];
    this.#active = -1;
    if (this.list) {
      this.list.hidden = true;
      this.list.replaceChildren();
    }
    this.field?.removeAttribute('aria-activedescendant');
  }

  #paintActive() {
    [...this.list.children].forEach((item, index) => item.setAttribute('aria-selected', String(index === this.#active)));
    if (this.#active >= 0) {
      this.field.setAttribute('aria-activedescendant', `rb-mention-${this.#active}`);
    } else {
      this.field.removeAttribute('aria-activedescendant');
    }
  }

  /** Clavier de la liste : flèches, Entrée ou Tab pour choisir, Échap pour fermer. Renvoie true si la touche est prise. */
  #handleListKey(event) {
    if (!this.list || this.list.hidden || this.#items.length === 0) {
      return false;
    }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      const step = event.key === 'ArrowDown' ? 1 : -1;
      this.#active = (this.#active + step + this.#items.length) % this.#items.length;
      this.#paintActive();
    } else if (event.key === 'Enter' || event.key === 'Tab') {
      this.#select(this.#active);
    } else if (event.key === 'Escape') {
      this.#closeList();
    } else {
      return false;
    }
    event.preventDefault();

    return true;
  }

  #select(index) {
    const member = this.#items[index];
    const typed = activeQuery(this.field.value, this.field.selectionStart ?? this.field.value.length);
    if (!member || typed === null) {
      this.#closeList();
      return;
    }
    const { text, caret } = applyMention(this.field.value, typed.start, this.field.selectionStart, member.name);
    this.field.value = text;
    this.field.setSelectionRange(caret, caret);
    this.#picks.set(member.id, { name: member.name, participant: member.participant });
    this.#closeList();
    this.#autosize();
    this.#refreshNotice();
    this.field.focus();
  }

  /** Prévient avant l'envoi : une personne extérieure à la conversation y sera ajoutée. */
  #refreshNotice() {
    if (!this.notice) {
      return;
    }
    const names = pendingGuests(this.field.value, this.#picks);
    this.notice.hidden = names.length === 0;
    this.notice.textContent = names.length === 0
      ? ''
      : `${names.join(', ')} ${names.length === 1 ? "n'est pas dans cette conversation : elle y aura accès." : "ne sont pas dans cette conversation : elles y auront accès."}`;
  }

  #autosize() {
    this.field.style.height = 'auto';
    this.field.style.height = `${Math.min(this.field.scrollHeight, 140)}px`;
  }
}

if (!customElements.get('rb-composer')) {
  customElements.define('rb-composer', RbComposer);
}
