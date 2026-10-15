/**
 * Pages admin « Textes de l'association » : liste (création), affichage d'un texte, édition en place et suppression. Appelle l'API
 * (apiFetch, CSRF) et affiche le résultat. Aucun envoi de formulaire avec rechargement ; la navigation après création ou suppression
 * est un changement de page.
 */
import { apiFetch } from '../../core/api.js';
import { showToast } from '../../core/toast.js';
import { confirmAction } from '../../ui/rb-confirm-dialog.js';
import { DELETE_WARNING, createRequest, deleteRequest, failureMessage, updateRequest } from './texts-requests.js';

const FIELDS = ['title', 'body'];

/** Pose le message d'un champ (ou le vide, qui le masque) dans le bloc donné. */
function showFieldError(scope, key, message) {
  showMessage(scope, `[data-field-error="${key}"]`, message);
}

function showFormError(scope, message) {
  showMessage(scope, '[data-form-error]', message);
}

function showMessage(scope, selector, message) {
  const target = scope.querySelector(selector);
  if (target !== null) {
    target.textContent = message;
    target.hidden = message === '';
  }
}

function clearErrors(scope) {
  for (const key of FIELDS) {
    showFieldError(scope, key, '');
  }
  showFormError(scope, '');
}

/** Erreurs par champ si le serveur en a renvoyé, sinon le message général (ou le message du serveur, 409 compris). */
function reportFailure(scope, error) {
  const fields = error?.fields ?? {};
  for (const key of FIELDS) {
    showFieldError(scope, key, fields[key] ?? '');
  }
  const hasFieldError = FIELDS.some((key) => fields[key]);
  showFormError(scope, hasFieldError ? '' : failureMessage(error));
}

function fieldValue(scope, name) {
  return scope.querySelector(`[name="${name}"]`).value;
}

async function handleCreate(button) {
  const scope = button.closest('[data-text-create]');
  clearErrors(scope);
  button.disabled = true;
  try {
    const { path, options } = createRequest(fieldValue(scope, 'title'), fieldValue(scope, 'body'));
    const created = await apiFetch(path, options);
    window.location.assign(created.url);
  } catch (error) {
    button.disabled = false;
    reportFailure(scope, error);
  }
}

function toggleEdit(section, editing) {
  section.querySelector('[data-text-view]').hidden = editing;
  section.querySelector('[data-text-edit]').hidden = !editing;
}

async function handleSave(button) {
  const section = button.closest('[data-text]');
  const edit = section.querySelector('[data-text-edit]');
  clearErrors(edit);
  button.disabled = true;
  try {
    const { path, options } = updateRequest(section.dataset.id, Number(section.dataset.revision), fieldValue(edit, 'title'), fieldValue(edit, 'body'));
    await apiFetch(path, options);
    showToast('Texte enregistré.', 'success');
    window.location.reload();
  } catch (error) {
    button.disabled = false;
    reportFailure(edit, error);
  }
}

async function handleDelete(button) {
  const section = button.closest('[data-text]');
  if (!(await confirmAction(DELETE_WARNING))) {
    return;
  }
  button.disabled = true;
  try {
    const { path, options } = deleteRequest(section.dataset.id);
    await apiFetch(path, options);
    window.location.assign('/admin/texts');
  } catch (error) {
    button.disabled = false;
    showFormError(section, failureMessage(error));
  }
}

export function initAdminTexts(root = document) {
  root.addEventListener('click', (event) => {
    const create = event.target.closest('[data-text-create] [data-action="create"]');
    if (create !== null) {
      handleCreate(create);

      return;
    }
    const action = event.target.closest('[data-text] [data-action]');
    if (action === null) {
      return;
    }
    const section = action.closest('[data-text]');
    switch (action.dataset.action) {
      case 'edit':
        toggleEdit(section, true);
        break;
      case 'cancel':
        toggleEdit(section, false);
        break;
      case 'save':
        handleSave(action);
        break;
      case 'delete':
        handleDelete(action);
        break;
    }
  });
}
