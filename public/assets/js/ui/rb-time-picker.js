/**
 * <rb-time-picker> : un horaire choisi en deux listes, heures puis minutes (#296). Rendu par le serveur (les deux listes et un champ
 * caché portant le nom du champ) ; le composant tient à jour la valeur « HH:MM » du champ caché (vide tant que l'heure ou les minutes
 * manquent) et restreint les minutes à l'intervalle permis (data-min, data-max). Le « change » des listes remonte tel quel jusqu'au
 * formulaire, qui relit le champ caché : le composant n'appelle jamais l'API. Cycle de vie : écouteur posé à la connexion, retiré à la
 * déconnexion.
 */
import { combine, minutesFor } from './time-picker.js';

export class RbTimePicker extends HTMLElement {
  #onChange = (event) => {
    const hours = this.querySelector('[data-time-hours]');
    const minutes = this.querySelector('[data-time-minutes]');
    const field = this.querySelector('input[type="hidden"]');
    if (hours === null || minutes === null || field === null || (event.target !== hours && event.target !== minutes)) {
      return;
    }
    if (event.target === hours) {
      this.#refreshMinutes(hours, minutes);
    }
    field.value = combine(hours.value, minutes.value);
  };

  connectedCallback() {
    this.addEventListener('change', this.#onChange);
  }

  disconnectedCallback() {
    this.removeEventListener('change', this.#onChange);
  }

  /** Remet à jour les minutes proposées pour l'heure choisie, en gardant la sélection si elle reste permise. */
  #refreshMinutes(hours, minutes) {
    const kept = minutes.value;
    const allowed = minutesFor(hours.value, this.dataset.min ?? '00:00', this.dataset.max ?? '23:45');
    const placeholder = minutes.querySelector('option[value=""]');
    minutes.replaceChildren(placeholder ?? new Option('--', ''), ...allowed.map((minute) => new Option(minute, minute)));
    minutes.value = allowed.includes(kept) ? kept : '';
  }
}

if (!customElements.get('rb-time-picker')) {
  customElements.define('rb-time-picker', RbTimePicker);
}
