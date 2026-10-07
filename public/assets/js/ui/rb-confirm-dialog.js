/**
 * <rb-confirm-dialog> (#326) — composant sur l'élément natif <dialog> (Light DOM). Le HTML de la fenêtre vient du serveur
 * (templates/partials/confirm-dialog.php) : le composant AMÉLIORE ce HTML, il ne construit pas la page. Il met le texte par
 * textContent, ouvre la fenêtre en modal (focus piégé, Échap, restitution du focus : gérés par le navigateur) et répond par une
 * promesse : vrai si l'on confirme, faux pour « Annuler », Échap ou un clic sur le fond.
 *
 * Posé une fois par page : <?php require 'partials/confirm-dialog.php' ?>. Usage : `await confirmAction('Supprimer ?')`.
 */
import { describeConfirmation, isOutsideRect } from './confirm-dialog.js';

// HTMLElement/customElements n'existent pas sous node --test (pas de DOM) : la classe n'est déclarée que dans un navigateur.
export let RbConfirmDialog;

if (typeof HTMLElement !== 'undefined') {
  RbConfirmDialog = class extends HTMLElement {
    #dialog = null;
    #resolve = null;

    connectedCallback() {
      this.#dialog = this.querySelector('dialog');
      this.#dialog.addEventListener('close', this.#onClose);
      this.#dialog.addEventListener('click', this.#onClick);
    }

    disconnectedCallback() {
      this.#dialog?.removeEventListener('close', this.#onClose);
      this.#dialog?.removeEventListener('click', this.#onClick);
      this.#settle(false);
    }

    /**
     * @param {string} message
     * @param {{title?: string, confirmLabel?: string, cancelLabel?: string}} [options]
     * @returns {Promise<boolean>}
     */
    confirm(message, options = {}) {
      this.#settle(false); // une seule demande à la fois : l'ancienne est abandonnée
      const described = describeConfirmation(message, options);

      const title = this.#dialog.querySelector('[data-confirm-title]');
      title.textContent = described.title;
      title.hidden = described.title === '';
      // Sans titre, rien ne nomme la fenêtre : on retire la référence plutôt que de pointer vers un élément vide.
      if (described.title === '') {
        this.#dialog.removeAttribute('aria-labelledby');
      } else {
        this.#dialog.setAttribute('aria-labelledby', title.id);
      }
      this.#dialog.querySelector('[data-confirm-body]').replaceChildren(...described.paragraphs.map((line) => {
        const paragraph = document.createElement('p');
        paragraph.className = 'rb-modal-text';
        paragraph.textContent = line;

        return paragraph;
      }));
      this.#dialog.querySelector('[data-confirm-cancel]').textContent = described.cancelLabel;
      this.#dialog.querySelector('[data-confirm-accept]').textContent = described.confirmLabel;

      this.#dialog.returnValue = '';

      return new Promise((resolve) => {
        this.#resolve = resolve;
        this.#dialog.showModal();
      });
    }

    #settle(result) {
      const resolve = this.#resolve;
      this.#resolve = null;
      if (this.#dialog?.open) {
        this.#dialog.close(result ? 'confirm' : 'cancel');
      }
      resolve?.(result);
    }

    // Fermeture (bouton, Échap ou fond) : « confirm » est la seule valeur qui confirme.
    #onClose = () => {
      const confirmed = this.#dialog.returnValue === 'confirm';
      const resolve = this.#resolve;
      this.#resolve = null;
      resolve?.(confirmed);
    };

    // Un clic sur le fond assombri est reçu par le <dialog> lui-même : hors de son rectangle, c'est « Annuler ».
    #onClick = (event) => {
      if (event.target === this.#dialog && isOutsideRect(this.#dialog.getBoundingClientRect(), event.clientX, event.clientY)) {
        this.#dialog.close('cancel');
      }
    };
  };

  if (!customElements.get('rb-confirm-dialog')) {
    customElements.define('rb-confirm-dialog', RbConfirmDialog);
  }
}

export function confirmAction(message, options = {}) {
  const dialog = document.querySelector('rb-confirm-dialog');
  if (!dialog) {
    throw new Error('<rb-confirm-dialog> introuvable dans le DOM.');
  }

  return dialog.confirm(message, options);
}
