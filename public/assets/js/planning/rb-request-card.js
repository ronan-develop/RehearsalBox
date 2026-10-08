/**
 * <rb-request-card> (#330) — une carte « demande d'échange de créneau » du bloc « Demandes de créneau » (Light DOM). Le serveur rend la
 * carte et ses boutons ; le composant traduit les gestes en évènements et ne parle jamais à l'API (c'est `availability.js`) :
 *
 *   <rb-request-card class="rb-exception-card …" exception-id="7" occurrence-date="2026-10-20">
 *     <button data-action="respond" data-accepted="true">Accepter</button> · <button data-action="cancel">Annuler</button>
 *     <form data-update>…date, raison…</form>
 *
 * Évènements (remontent) : `request:respond` { id, accepted, occurrenceDate }, `request:cancel` { id }, `request:update` { id,
 * occurrenceDate, reason }. `busy` désactive les boutons et pose `aria-busy` pendant un appel : un double clic n'envoie pas deux fois.
 * La pile de cartes (glisser, profondeur) reste l'affaire du paquet, pas de la carte.
 */

// HTMLElement/customElements n'existent pas sous node --test (pas de DOM) : la classe n'est déclarée que dans un navigateur.
export let RbRequestCard;

if (typeof HTMLElement !== 'undefined') {
  RbRequestCard = class extends HTMLElement {
    connectedCallback() {
      this.addEventListener('click', this.#onClick);
      this.addEventListener('submit', this.#onSubmit);
    }

    disconnectedCallback() {
      this.removeEventListener('click', this.#onClick);
      this.removeEventListener('submit', this.#onSubmit);
    }

    get busy() {
      return this.hasAttribute('aria-busy');
    }

    set busy(value) {
      this.toggleAttribute('aria-busy', Boolean(value));
      this.querySelectorAll('button').forEach((button) => {
        button.disabled = Boolean(value);
      });
    }

    #emit(name, detail) {
      this.dispatchEvent(new CustomEvent(name, { detail: { id: this.getAttribute('exception-id') ?? '', ...detail }, bubbles: true }));
    }

    #onClick = (event) => {
      const button = event.target.closest('[data-action]');
      if (!button || !this.contains(button) || this.busy) {
        return;
      }
      if (button.dataset.action === 'respond') {
        this.#emit('request:respond', { accepted: button.dataset.accepted === 'true', occurrenceDate: this.getAttribute('occurrence-date') ?? '' });
      } else if (button.dataset.action === 'cancel') {
        this.#emit('request:cancel', {});
      }
    };

    #onSubmit = (event) => {
      event.preventDefault(); // jamais d'envoi natif
      const form = event.target.closest('form[data-update]');
      if (!form || this.busy) {
        return;
      }
      const data = new FormData(form);
      this.#emit('request:update', { occurrenceDate: String(data.get('occurrenceDate') ?? ''), reason: String(data.get('reason') ?? '') });
    };
  };

  if (!customElements.get('rb-request-card')) {
    customElements.define('rb-request-card', RbRequestCard);
  }
}
