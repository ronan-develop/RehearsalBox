/**
 * <rb-planning-card> (#330) — une carte du planning fixe (Light DOM) qui mène au groupe qu'elle montre : clic, ou Entrée / Espace
 * au clavier. Le serveur rend le contenu et les attributs ; le composant ne construit rien.
 *
 *   <rb-planning-card class="rb-planning-card" role="button" tabindex="0" group-id="5" group-name="…" group-slug="…" weekday="2" start-time="18:30" end-time="22:45" [member] [can-book]>
 *
 * `member` est posé par le serveur quand l'utilisateur appartient au groupe (la carte ouvre alors son espace) ; `can-book` quand il a
 * un groupe pour réserver (la carte d'un autre groupe ouvre alors « Réserver le local » rempli avec ce créneau). L'activation émet
 * `planning-card:open` { url } (annulable, remonte) puis navigue, sauf si l'évènement est annulé : c'est le point d'extension et
 * ce qui rend le comportement testable sans quitter la page. Plus d'écouteur global sur le document : une carte insérée plus tard
 * (fragment du serveur) s'active toute seule.
 */
import { destinationFor, isActivationKey } from './planning-card.js';

// HTMLElement/customElements n'existent pas sous node --test (pas de DOM) : la classe n'est déclarée que dans un navigateur.
export let RbPlanningCard;

if (typeof HTMLElement !== 'undefined') {
  RbPlanningCard = class extends HTMLElement {
    connectedCallback() {
      this.addEventListener('click', this.#activate);
      this.addEventListener('keydown', this.#onKeydown);
    }

    disconnectedCallback() {
      this.removeEventListener('click', this.#activate);
      this.removeEventListener('keydown', this.#onKeydown);
    }

    #activate = () => {
      const url = destinationFor({
        groupId: this.getAttribute('group-id') ?? '',
        slug: this.getAttribute('group-slug') ?? '',
        isMember: this.hasAttribute('member'),
        canBook: this.hasAttribute('can-book'),
        weekday: this.getAttribute('weekday') ?? '',
        startTime: this.getAttribute('start-time') ?? '',
        endTime: this.getAttribute('end-time') ?? '',
      });
      const proceed = this.dispatchEvent(new CustomEvent('planning-card:open', { detail: { url }, bubbles: true, cancelable: true }));
      if (proceed) {
        window.location.href = url;
      }
    };

    #onKeydown = (event) => {
      if (event.target === this && isActivationKey(event.key)) {
        event.preventDefault(); // Espace ne fait pas défiler la page
        this.#activate();
      }
    };
  };

  if (!customElements.get('rb-planning-card')) {
    customElements.define('rb-planning-card', RbPlanningCard);
  }
}
