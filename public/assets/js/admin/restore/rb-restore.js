/**
 * <rb-restore> (#241) — page « Restauration de la base » (propriétaire). Le HTML vient du serveur (templates/admin/restore/index.php) :
 * le composant enveloppe le tableau des sauvegardes et la fenêtre <dialog data-restore-dialog>. Il ouvre la fenêtre sur le bouton
 * « Restaurer » d'une ligne, valide localement, envoie le JSON par apiFetch (jamais de submit natif) et affiche le résultat.
 * Aucune logique métier ici : validation et traduction sont dans restore-form.js.
 *
 *   <rb-restore endpoint="/api/admin/restore" confirm-word="RESTAURER">…tableau…<dialog data-restore-dialog>…</dialog></rb-restore>
 */
import { apiFetch } from '../../core/api.js';
import { buildRestoreRequest, RESTORE_DONE_MESSAGE, restoreFailureMessage, validateRestoreForm } from './restore-form.js';

// HTMLElement/customElements n'existent pas sous node --test (pas de DOM) : la classe n'est déclarée que dans un navigateur.
export let RbRestore;

if (typeof HTMLElement !== 'undefined') {
  RbRestore = class extends HTMLElement {
    #dialog = null;
    #form = null;
    #submit = null;
    #target = null;
    #error = null;
    #done = null;
    #file = '';
    #pending = false;
    #locked = false;

    connectedCallback() {
      this.#dialog = this.querySelector('[data-restore-dialog]');
      this.#form = this.#dialog.querySelector('form');
      this.#submit = this.#form.querySelector('button[type="submit"]');
      this.#target = this.#dialog.querySelector('[data-restore-target]');
      this.#error = this.#dialog.querySelector('[data-restore-error]');
      this.#done = this.querySelector('[data-restore-done]');
      this.addEventListener('click', this.#onClick);
      this.#dialog.addEventListener('close', this.#onClose);
      this.#form.addEventListener('submit', this.#onSubmit);
    }

    disconnectedCallback() {
      this.removeEventListener('click', this.#onClick);
      this.#dialog?.removeEventListener('close', this.#onClose);
      this.#form?.removeEventListener('submit', this.#onSubmit);
    }

    // Délégation : un seul écouteur pour les boutons « Restaurer » des lignes et « Annuler » de la fenêtre.
    #onClick = (event) => {
      const restoreButton = event.target.closest?.('[data-restore-file]');
      if (restoreButton && this.contains(restoreButton)) {
        this.#open(restoreButton);
        return;
      }
      if (event.target.closest?.('[data-restore-cancel]')) {
        this.#dialog.close();
      }
    };

    #open(button) {
      this.#file = button.dataset.restoreFile;
      this.#target.textContent = `Restaurer la sauvegarde du ${button.dataset.restoreLabel} ? Toutes les données actuelles seront remplacées.`;
      this.#showError('');
      this.#dialog.showModal();
    }

    // Fermeture (Annuler, Échap ou fond) : le mot de passe ne reste jamais dans le champ.
    #onClose = () => {
      this.#clearPassword();
      this.#showError('');
    };

    #onSubmit = async (event) => {
      event.preventDefault(); // jamais d'envoi natif
      if (this.#pending || this.#locked) {
        return;
      }

      const data = new FormData(this.#form);
      const values = { password: String(data.get('password') ?? ''), confirmation: String(data.get('confirmation') ?? '') };
      const firstError = Object.values(validateRestoreForm(values, this.getAttribute('confirm-word') ?? ''))[0];
      if (firstError !== undefined) {
        this.#showError(firstError);
        return;
      }

      this.#pending = true;
      this.#submit.disabled = true;
      try {
        await apiFetch(this.getAttribute('endpoint'), {
          method: 'POST',
          body: JSON.stringify(buildRestoreRequest({ file: this.#file, ...values })),
        });
        this.#lock();
        this.#dialog.close();
      } catch (error) {
        this.#showError(restoreFailureMessage(error));
      } finally {
        this.#clearPassword();
        this.#pending = false;
        this.#submit.disabled = this.#locked;
      }
    };

    // Après un lancement réussi : plus aucune restauration possible tant que la page n'est pas rechargée.
    #lock() {
      this.#locked = true;
      this.querySelectorAll('[data-restore-file]').forEach((button) => {
        button.disabled = true;
      });
      this.#done.textContent = RESTORE_DONE_MESSAGE;
      this.#done.hidden = false;
    }

    #showError(message) {
      this.#error.textContent = message;
      this.#error.hidden = message === '';
    }

    #clearPassword() {
      this.#form.elements.namedItem('password').value = '';
    }
  };

  if (!customElements.get('rb-restore')) {
    customElements.define('rb-restore', RbRestore);
  }
}
