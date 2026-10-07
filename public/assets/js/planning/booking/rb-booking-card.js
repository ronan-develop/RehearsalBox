/**
 * <rb-booking-card> : une réservation libre à valider, sur la page admin « Réservations » (#263). Rendue par le serveur (le texte,
 * les boutons et le champ de motif sont déjà dans le HTML) ; le composant ne parle jamais à l'API : il émet booking:decide
 * { id, decision: 'approve' | 'refuse', note }, la page (admin-bookings.js) appelle l'API puis le retire. Pendant l'appel il est
 * occupé (aria-busy) et ignore les clics. Cycle de vie : écouteur posé à la connexion, retiré à la déconnexion.
 */
import { emit } from '../../messaging/chat/events.js';

export const DECIDE_EVENT = 'booking:decide';

export class RbBookingCard extends HTMLElement {
  #onClick = (event) => {
    if (this.hasAttribute('aria-busy')) {
      return;
    }
    const button = event.target.closest('button');
    if (button === null || !this.contains(button)) {
      return;
    }
    if (button.hasAttribute('data-booking-approve')) {
      this.#decide('approve');
    } else if (button.hasAttribute('data-booking-refuse')) {
      this.#toggleRefusal();
    } else if (button.hasAttribute('data-booking-confirm-refuse')) {
      this.#decide('refuse', this.querySelector('[name="note"]')?.value ?? '');
    }
  };

  connectedCallback() {
    this.addEventListener('click', this.#onClick);
  }

  disconnectedCallback() {
    this.removeEventListener('click', this.#onClick);
  }

  /** Occupé pendant l'appel : boutons inactifs, plus d'erreur affichée. */
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

  #decide(decision, note = '') {
    emit(this, DECIDE_EVENT, { id: this.dataset.id, decision, note });
  }

  #toggleRefusal() {
    const panel = this.querySelector('[data-booking-refusal]');
    const toggle = this.querySelector('[data-booking-refuse]');
    if (panel === null || toggle === null) {
      return;
    }
    panel.hidden = !panel.hidden;
    toggle.setAttribute('aria-expanded', panel.hidden ? 'false' : 'true');
    if (!panel.hidden) {
      panel.querySelector('input')?.focus();
    }
  }
}

if (!customElements.get('rb-booking-card')) {
  customElements.define('rb-booking-card', RbBookingCard);
}
