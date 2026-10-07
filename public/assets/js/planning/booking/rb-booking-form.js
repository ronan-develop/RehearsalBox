/**
 * <rb-booking-form> : le formulaire « Réserver le local » (#263). Rendu par le serveur (champs, libellés, zone du plan et bouton
 * sont déjà dans le HTML) ; le composant ne parle jamais à l'API : il émet booking:plan-request { groupId, date, start, end } à
 * chaque saisie (après une courte pause) et booking:submit { groupId, date, reason } au clic. La page (bookings.js) calcule le plan
 * et lui rend le résultat par showPlan / clearPlan / showResult / showError. Tout texte est posé avec textContent, jamais en HTML.
 * Cycle de vie : écouteurs posés à la connexion, minuterie et écouteurs retirés à la déconnexion.
 */
import { emit } from '../../messaging/chat/events.js';

export const PLAN_REQUEST = 'booking:plan-request';
export const SUBMIT = 'booking:submit';
const DEBOUNCE_MS = 250;

export class RbBookingForm extends HTMLElement {
  #timer = 0;

  #onInput = () => {
    window.clearTimeout(this.#timer);
    this.#timer = window.setTimeout(() => emit(this, PLAN_REQUEST, this.#plan()), DEBOUNCE_MS);
  };

  #onClick = (event) => {
    const button = event.target.closest('[data-booking-submit]');
    if (button !== null && this.contains(button) && !button.disabled && !this.hasAttribute('aria-busy')) {
      emit(this, SUBMIT, { groupId: this.#value('groupId'), date: this.#value('date'), reason: this.#value('reason') });
    }
  };

  connectedCallback() {
    this.addEventListener('input', this.#onInput);
    this.addEventListener('change', this.#onInput);
    this.addEventListener('click', this.#onClick);
  }

  disconnectedCallback() {
    window.clearTimeout(this.#timer);
    this.removeEventListener('input', this.#onInput);
    this.removeEventListener('change', this.#onInput);
    this.removeEventListener('click', this.#onClick);
  }

  /** @param {{ lines: { tone: string, text: string }[], action: { label: string } | null }} view */
  showPlan({ lines, action }) {
    this.showError('');
    this.#fill('[data-booking-plan]', lines.map((line) => ({ text: line.text, className: `rb-booking-plan-line rb-booking-plan-line--${line.tone}` })));
    const submit = this.querySelector('[data-booking-submit]');
    if (submit !== null) {
      submit.textContent = action === null ? 'Réserver' : action.label;
      submit.disabled = action === null;
    }
  }

  clearPlan() {
    this.#fill('[data-booking-plan]', []);
    const submit = this.querySelector('[data-booking-submit]');
    if (submit !== null) {
      submit.textContent = 'Réserver';
      submit.disabled = true;
    }
  }

  /** Bilan d'un envoi : une ligne par étape. */
  showResult(lines, ok) {
    this.#fill('[data-booking-result]', lines.map((text) => ({ text, className: `rb-booking-result-line rb-booking-result-line--${ok ? 'ok' : 'warn'}` })));
  }

  showError(message) {
    const element = this.querySelector('[data-booking-form-error]');
    if (element !== null) {
      element.textContent = message;
      element.hidden = message === '';
    }
  }

  setBusy(busy) {
    this.toggleAttribute('aria-busy', busy);
    this.querySelectorAll('input, select, button').forEach((control) => {
      control.disabled = busy;
    });
    if (!busy) {
      this.querySelector('[data-booking-submit]')?.toggleAttribute('disabled', this.querySelector('[data-booking-plan]')?.hidden ?? true);
    }
  }

  #plan() {
    return { groupId: this.#value('groupId'), date: this.#value('date'), start: this.#value('start'), end: this.#value('end') };
  }

  #value(name) {
    return this.querySelector(`[name="${name}"]`)?.value ?? '';
  }

  #fill(selector, items) {
    const list = this.querySelector(selector);
    if (list === null) {
      return;
    }
    list.replaceChildren(...items.map(({ text, className }) => {
      const item = document.createElement('li');
      item.className = className;
      item.textContent = text;

      return item;
    }));
    list.hidden = items.length === 0;
  }
}

if (!customElements.get('rb-booking-form')) {
  customElements.define('rb-booking-form', RbBookingForm);
}
