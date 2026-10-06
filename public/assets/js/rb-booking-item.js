/**
 * <rb-booking-item> : une réservation du groupe, avec son bouton Annuler (#263). Rendue par le serveur ; le composant ne parle jamais
 * à l'API : il émet booking:cancel { id }, la page (bookings.js) appelle l'API. Occupé pendant l'appel (aria-busy), il ignore les clics
 * répétés. Cycle de vie : écouteur posé à la connexion, retiré à la déconnexion.
 */
import { emit } from './chat/events.js';

export const CANCEL_EVENT = 'booking:cancel';

export class RbBookingItem extends HTMLElement {
  #onClick = (event) => {
    const button = event.target.closest('[data-booking-cancel]');
    if (button !== null && this.contains(button) && !this.hasAttribute('aria-busy')) {
      emit(this, CANCEL_EVENT, { id: this.dataset.id });
    }
  };

  connectedCallback() {
    this.addEventListener('click', this.#onClick);
  }

  disconnectedCallback() {
    this.removeEventListener('click', this.#onClick);
  }

  setBusy(busy) {
    this.toggleAttribute('aria-busy', busy);
    this.querySelectorAll('button').forEach((button) => {
      button.disabled = busy;
    });
    if (busy) {
      this.showError('');
    }
  }

  showError(message) {
    const element = this.querySelector('[data-booking-error]');
    if (element !== null) {
      element.textContent = message;
      element.hidden = message === '';
    }
  }
}

if (!customElements.get('rb-booking-item')) {
  customElements.define('rb-booking-item', RbBookingItem);
}
