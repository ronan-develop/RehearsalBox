/**
 * Page admin « Bureau de l'association » : enregistre ou vide un poste. Appelle l'API (apiFetch, CSRF) et affiche le résultat.
 * Aucun envoi de formulaire avec rechargement ; seul le rafraîchissement après succès recharge la page.
 */
import { apiFetch } from '../../core/api.js';
import { showToast } from '../../core/toast.js';
import { confirmAction } from '../../ui/rb-confirm-dialog.js';
import { VACATE_WARNING, assignRequest, failureMessage, parseUserId, vacateRequest } from './board-requests.js';

const FIELDS = ['userId', 'fullName'];

function showMessage(scope, selector, message) {
  const target = scope.querySelector(selector);
  if (target !== null) {
    target.textContent = message;
    target.hidden = message === '';
  }
}

function clearErrors(scope) {
  for (const key of FIELDS) {
    showMessage(scope, `[data-field-error="${key}"]`, '');
  }
  showMessage(scope, '[data-form-error]', '');
}

/** Erreurs par champ si le serveur en a renvoyé, sinon le message général. */
function reportFailure(scope, error) {
  const fields = error?.fields ?? {};
  for (const key of FIELDS) {
    showMessage(scope, `[data-field-error="${key}"]`, fields[key] ?? '');
  }
  showMessage(scope, '[data-form-error]', FIELDS.some((key) => fields[key]) ? '' : failureMessage(error));
}

async function handleSave(button, scope) {
  clearErrors(scope);
  button.disabled = true;
  try {
    const { path, options } = assignRequest(scope.dataset.boardPost, parseUserId(scope.querySelector('[name="userId"]').value), scope.querySelector('[name="fullName"]').value);
    await apiFetch(path, options);
    showToast('Poste enregistré.', 'success');
    window.location.reload();
  } catch (error) {
    button.disabled = false;
    reportFailure(scope, error);
  }
}

async function handleVacate(button, scope) {
  if (!(await confirmAction(VACATE_WARNING))) {
    return;
  }
  clearErrors(scope);
  button.disabled = true;
  try {
    const { path, options } = vacateRequest(scope.dataset.boardPost);
    await apiFetch(path, options);
    window.location.reload();
  } catch (error) {
    button.disabled = false;
    reportFailure(scope, error);
  }
}

export function initAdminBoard(root = document) {
  root.addEventListener('click', (event) => {
    const button = event.target.closest('[data-board-post] [data-action]');
    if (button === null) {
      return;
    }
    const scope = button.closest('[data-board-post]');
    if (button.dataset.action === 'save') {
      handleSave(button, scope);
    } else if (button.dataset.action === 'vacate') {
      handleVacate(button, scope);
    }
  });
}
