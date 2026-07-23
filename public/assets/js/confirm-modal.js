/**
 * Modale de confirmation maison — jamais de confirm() natif bloquant
 * (mauvaise UX mobile, cf. plan §6/§10.2). Utilisée avant tout DELETE
 * destructif (créneau, membre de groupe).
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

export function confirmAction(message) {
  return new Promise((resolve) => {
    const container = document.createElement('div');
    container.innerHTML = buildConfirmModalMarkup(message);
    const modal = container.firstElementChild;
    document.body.appendChild(modal);

    const cleanup = (result) => {
      modal.remove();
      resolve(result);
    };

    modal.querySelector('[data-confirm-modal-confirm]').addEventListener('click', () => cleanup(true));
    modal.querySelector('[data-confirm-modal-cancel]').addEventListener('click', () => cleanup(false));
    modal.addEventListener('click', (event) => {
      if (event.target === modal) {
        cleanup(false);
      }
    });
  });
}
