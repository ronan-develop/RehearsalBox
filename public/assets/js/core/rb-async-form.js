/**
 * <rb-async-form> (#327) — soumission asynchrone d'un formulaire rendu par le serveur (Light DOM, remplace forms.js) :
 *
 *   <rb-async-form endpoint="/api/auth/login" method="POST"><form>…champs…</form></rb-async-form>
 *
 * Le composant AMÉLIORE le HTML du serveur : il intercepte le submit, envoie le formulaire en JSON par apiFetch (jamais de
 * rechargement de page), affiche les erreurs par champ dans `[data-field-error="nom"]` (et marque le champ `aria-invalid`), pose
 * `aria-busy` et désactive les boutons pendant l'envoi, et ignore un second envoi tant que le premier est en vol.
 *
 * Contrat d'évènements (inchangé), émis sur le composant (ils remontent) :
 *   `async-success` { detail: réponse de l'API }   `async-error` { detail: { message, fields, status } }
 * Le composant expose `form` et `reset()` ; il n'y a plus rien à initialiser : une balise insérée plus tard (carte créée en JS)
 * s'active toute seule.
 */
import { apiFetch } from './api.js';
import { createSubmitGuard, fieldErrorEntries, requestFor, serializeFormEntries } from './async-form.js';

// HTMLElement/customElements n'existent pas sous node --test (pas de DOM) : la classe n'est déclarée que dans un navigateur.
export let RbAsyncForm;

if (typeof HTMLElement !== 'undefined') {
  RbAsyncForm = class extends HTMLElement {
    #form = null;
    #guard = createSubmitGuard();

    connectedCallback() {
      this.#form = this.querySelector('form');
      this.#form?.addEventListener('submit', this.#onSubmit);
    }

    disconnectedCallback() {
      this.#form?.removeEventListener('submit', this.#onSubmit);
    }

    get form() {
      return this.#form;
    }

    reset() {
      this.#form?.reset();
    }

    #onSubmit = async (event) => {
      event.preventDefault(); // jamais d'envoi natif, même si le composant est mal configuré
      const request = requestFor({ endpoint: this.getAttribute('endpoint'), method: this.getAttribute('method') });
      if (request === null || !this.#guard.tryEnter()) {
        return;
      }

      // Les champs d'un formulaire dont les boutons sont désactivés restent lus : le FormData est construit AVANT.
      const payload = serializeFormEntries(new FormData(this.#form).entries());
      this.#clearErrors();
      this.#setBusy(true);

      try {
        const result = await apiFetch(request.endpoint, { method: request.method, body: JSON.stringify(payload) });
        this.dispatchEvent(new CustomEvent('async-success', { detail: result, bubbles: true }));
      } catch (error) {
        this.#showErrors(error.fields);
        this.dispatchEvent(new CustomEvent('async-error', { detail: error, bubbles: true }));
      } finally {
        this.#setBusy(false);
        this.#guard.leave();
      }
    };

    #setBusy(busy) {
      this.#form.toggleAttribute('aria-busy', busy);
      for (const button of this.#form.querySelectorAll('button[type="submit"], button:not([type])')) {
        button.disabled = busy;
      }
    }

    #clearErrors() {
      this.#form.querySelectorAll('[data-field-error]').forEach((target) => {
        target.textContent = '';
      });
      this.#form.querySelectorAll('[aria-invalid]').forEach((field) => field.removeAttribute('aria-invalid'));
    }

    #showErrors(fields) {
      for (const [name, message] of fieldErrorEntries(fields)) {
        const target = this.#form.querySelector(`[data-field-error="${CSS.escape(name)}"]`);
        if (target) {
          target.textContent = message;
        }
        const field = this.#form.elements.namedItem(name);
        if (field instanceof Element) {
          field.setAttribute('aria-invalid', 'true');
        }
      }
    }
  };

  if (!customElements.get('rb-async-form')) {
    customElements.define('rb-async-form', RbAsyncForm);
  }
}
