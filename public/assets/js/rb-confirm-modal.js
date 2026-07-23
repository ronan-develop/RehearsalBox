/**
 * <rb-confirm-modal> — Web Component natif en Light DOM (pas de Shadow DOM :
 * hérite directement des classes .rb-modal* déjà globales dans admin.css,
 * sans rien dupliquer). Remplace confirm() natif, mauvaise UX mobile
 * (cf. plan §6/§10.2).
 *
 * Balise statique attendue une fois par page : <rb-confirm-modal></rb-confirm-modal>.
 */
const HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => HTML_ESCAPES[char]);
}

export function buildConfirmModalMarkup(message) {
  return `
    <div class="rb-modal-backdrop" data-confirm-modal>
      <div class="rb-modal" role="alertdialog" aria-modal="true">
        <p>${escapeHtml(message)}</p>
        <div class="rb-modal-actions">
          <button type="button" class="rb-btn rb-modal-cancel" data-confirm-modal-cancel>Annuler</button>
          <button type="button" class="rb-btn rb-modal-confirm" data-confirm-modal-confirm>Confirmer</button>
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

    confirm(message) {
      return new Promise((resolve) => {
        this.innerHTML = buildConfirmModalMarkup(message);
        this.hidden = false;

        const cleanup = (result) => {
          this.innerHTML = '';
          this.hidden = true;
          resolve(result);
        };

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

export function confirmAction(message) {
  const modal = document.querySelector('rb-confirm-modal');
  if (!modal) {
    throw new Error('<rb-confirm-modal> introuvable dans le DOM.');
  }

  return modal.confirm(message);
}
