/**
 * <rb-confirm-modal> — Web Component natif en Light DOM (pas de Shadow DOM :
 * hérite directement des classes .rb-modal et .rb-card déjà globales, sans
 * rien dupliquer). Remplace confirm() natif, mauvaise UX mobile
 * (cf. plan §6/§10.2).
 *
 * Balise statique attendue une fois par page : <rb-confirm-modal></rb-confirm-modal>.
 */
import { escapeHtml } from './html.js';

/**
 * @param {string} message texte ; un saut de ligne sépare deux paragraphes
 * @param {{title?: string, confirmLabel?: string, cancelLabel?: string}} [options] titre facultatif et libellés des boutons
 */
export function buildConfirmModalMarkup(message, { title = '', confirmLabel = 'Confirmer', cancelLabel = 'Annuler' } = {}) {
  const paragraphs = String(message ?? '').split('\n').map((line) => `<p class="rb-modal-text">${escapeHtml(line)}</p>`).join('');
  const heading = title === '' ? '' : `<h2 class="rb-modal-title" id="rb-modal-title">${escapeHtml(title)}</h2>`;
  const labelledBy = title === '' ? '' : ' aria-labelledby="rb-modal-title"';

  return `
    <div class="rb-modal-backdrop" data-confirm-modal>
      <div class="rb-modal rb-card" role="alertdialog" aria-modal="true"${labelledBy} aria-describedby="rb-modal-body">
        ${heading}
        <div id="rb-modal-body">${paragraphs}</div>
        <div class="rb-modal-actions">
          <button type="button" class="rb-btn rb-modal-cancel" data-confirm-modal-cancel>${escapeHtml(cancelLabel)}</button>
          <button type="button" class="rb-btn rb-modal-confirm" data-confirm-modal-confirm>${escapeHtml(confirmLabel)}</button>
        </div>
      </div>
    </div>
  `;
}

// HTMLElement/customElements n'existent pas en environnement de test Node
// (node --test, pas de DOM) — la classe n'est déclarée/enregistrée que si un
// vrai DOM est présent, sinon le simple import du module ferait planter tous
// les tests JS du projet.
export let RbConfirmModal;

if (typeof HTMLElement !== 'undefined') {
  RbConfirmModal = class extends HTMLElement {
    connectedCallback() {
      this.hidden = true;
    }

    confirm(message, options = {}) {
      return new Promise((resolve) => {
        const opener = document.activeElement;
        this.innerHTML = buildConfirmModalMarkup(message, options);
        this.hidden = false;

        const onKey = (event) => {
          if (event.key === 'Escape') {
            cleanup(false);
          }
        };
        const cleanup = (result) => {
          document.removeEventListener('keydown', onKey);
          this.innerHTML = '';
          this.hidden = true;
          opener?.focus?.();
          resolve(result);
        };
        document.addEventListener('keydown', onKey);
        // Le focus va sur « Annuler » : Entrée ne détruit rien par réflexe.
        this.querySelector('[data-confirm-modal-cancel]').focus();

        this.querySelector('[data-confirm-modal-confirm]').addEventListener('click', () => cleanup(true));
        this.querySelector('[data-confirm-modal-cancel]').addEventListener('click', () => cleanup(false));
        this.querySelector('[data-confirm-modal]').addEventListener('click', (event) => {
          if (event.target.hasAttribute('data-confirm-modal')) {
            cleanup(false);
          }
        });
      });
    }
  };

  if (!customElements.get('rb-confirm-modal')) {
    customElements.define('rb-confirm-modal', RbConfirmModal);
  }
}

export function confirmAction(message, options = {}) {
  const modal = document.querySelector('rb-confirm-modal');
  if (!modal) {
    throw new Error('<rb-confirm-modal> introuvable dans le DOM.');
  }

  return modal.confirm(message, options);
}
