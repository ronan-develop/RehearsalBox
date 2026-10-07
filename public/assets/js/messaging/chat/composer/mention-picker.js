/**
 * Liste de suggestions de mentions sous la saisie (#178), extraite de <rb-composer> : taper « @ » ouvre une liste de membres
 * (listbox accessible, clavier et toucher). La recherche est fournie par l'appelant (`suggest`) ; sans elle, aucune
 * suggestion. Les noms sont insérés avec textContent, jamais en HTML. Le choix d'une personne est signalé par `onPick` : la
 * mémoire des personnes choisies et l'avertissement d'ajout restent à l'appelant.
 */
import { activeQuery, applyMention } from './mentions.js';

const SUGGEST_DELAY_MS = 200;
const MIN_QUERY = 2;

export class MentionPicker {
  #field;
  #list;
  #suggest;
  #onPick;
  #items = [];
  #active = -1;
  #timer = 0;
  #seq = 0;

  /**
   * @param {{ field: HTMLTextAreaElement, list: HTMLElement | null, suggest: () => ((query: string) => Promise<Array<{ id: number, name: string, groups: string, participant: boolean }>>) | null, onPick: (member: object) => void }} options
   *        `suggest` renvoie la fonction de recherche courante (fournie plus tard par <rb-chat>)
   */
  constructor({ field, list, suggest, onPick }) {
    this.#field = field;
    this.#list = list;
    this.#suggest = suggest;
    this.#onPick = onPick;
    // pointerdown + preventDefault : le champ garde le focus, l'option est choisie au toucher comme à la souris.
    list?.addEventListener('pointerdown', (event) => {
      const option = event.target.closest('[data-index]');
      if (option) {
        event.preventDefault();
        this.#select(Number(option.dataset.index));
      }
    });
  }

  /** À appeler à chaque saisie : (re)lance la recherche si un « @nom » est en cours de frappe. */
  onInput() {
    window.clearTimeout(this.#timer);
    const typed = activeQuery(this.#field.value, this.#field.selectionStart ?? this.#field.value.length);
    const search = this.#suggest();
    if (typed === null || typed.query.trim().length < MIN_QUERY || typeof search !== 'function') {
      this.close();
      return;
    }
    this.#timer = window.setTimeout(async () => {
      const seq = ++this.#seq;
      try {
        const members = await search(typed.query.trim());
        if (seq === this.#seq) {
          this.#open(members);
        }
      } catch {
        this.close();
      }
    }, SUGGEST_DELAY_MS);
  }

  close() {
    window.clearTimeout(this.#timer);
    this.#seq += 1;
    this.#items = [];
    this.#active = -1;
    if (this.#list) {
      this.#list.hidden = true;
      this.#list.replaceChildren();
    }
    this.#field?.removeAttribute('aria-activedescendant');
  }

  /** Clavier de la liste : flèches, Entrée ou Tab pour choisir, Échap pour fermer. Renvoie true si la touche est prise. */
  handleKey(event) {
    if (!this.#list || this.#list.hidden || this.#items.length === 0) {
      return false;
    }
    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
      const step = event.key === 'ArrowDown' ? 1 : -1;
      this.#active = (this.#active + step + this.#items.length) % this.#items.length;
      this.#paintActive();
    } else if (event.key === 'Enter' || event.key === 'Tab') {
      this.#select(this.#active);
    } else if (event.key === 'Escape') {
      this.close();
    } else {
      return false;
    }
    event.preventDefault();

    return true;
  }

  #open(members) {
    this.#items = members;
    this.#active = members.length > 0 ? 0 : -1;
    this.#list.replaceChildren(...members.map((member, index) => {
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
    this.#list.hidden = members.length === 0;
    this.#paintActive();
  }

  #paintActive() {
    [...this.#list.children].forEach((item, index) => item.setAttribute('aria-selected', String(index === this.#active)));
    if (this.#active >= 0) {
      this.#field.setAttribute('aria-activedescendant', `rb-mention-${this.#active}`);
    } else {
      this.#field.removeAttribute('aria-activedescendant');
    }
  }

  #select(index) {
    const member = this.#items[index];
    const typed = activeQuery(this.#field.value, this.#field.selectionStart ?? this.#field.value.length);
    if (!member || typed === null) {
      this.close();
      return;
    }
    const { text, caret } = applyMention(this.#field.value, typed.start, this.#field.selectionStart, member.name);
    this.#field.value = text;
    this.#field.setSelectionRange(caret, caret);
    this.close();
    this.#onPick(member);
    this.#field.focus();
  }
}
