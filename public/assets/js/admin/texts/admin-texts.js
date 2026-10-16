/**
 * Pages admin « Textes de l'association » : affichage d'un texte, édition en place et suppression. Appelle l'API
 * (apiFetch, CSRF) et affiche le résultat. Aucun envoi de formulaire avec rechargement ; la navigation après suppression
 * est un changement de page.
 */
import { apiFetch } from '../../core/api.js';
import { showToast } from '../../core/toast.js';
import { confirmAction } from '../../ui/rb-confirm-dialog.js';
import { headingLevel, toggleHeading } from './texts-format.js';
import { DELETE_WARNING, createRequest, deleteRequest, printStamp, failureMessage, updateRequest } from './texts-requests.js';

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

/** Déplie ou replie le panneau de création ; l'ouverture met le curseur dans le titre, la fermeture efface la saisie et les erreurs. */
function toggleCreate(section, open) {
  const panel = section.querySelector('[data-text-create]');
  panel.hidden = !open;
  section.querySelector('[data-action="new"]').hidden = open;
  if (open) {
    panel.querySelector('[name="title"]').focus();

    return;
  }
  clearErrors(panel);
  for (const name of FIELDS) {
    panel.querySelector(`[name="${name}"]`).value = '';
  }
}

/** Allume l'interrupteur dont le niveau est celui de la ligne du curseur (aucun si la ligne est du texte). */
function syncSwitches(textarea) {
  const level = headingLevel(textarea.value, textarea.selectionStart);
  const field = textarea.closest('.rb-field');
  for (const button of field.querySelectorAll('[data-action="heading"]')) {
    button.setAttribute('aria-pressed', String(Number(button.dataset.level) === level));
  }
}

/** Bascule titre ou sous-titre sur la ligne du curseur de la zone de texte du panneau, puis rend la main au curseur. */
function handleHeading(button) {
  const textarea = button.closest('.rb-field').querySelector('textarea');
  const { value, caret } = toggleHeading(textarea.value, textarea.selectionStart, Number(button.dataset.level));
  textarea.value = value;
  textarea.focus();
  textarea.setSelectionRange(caret, caret);
  syncSwitches(textarea);
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

/** Pose la date du jour dans le cachet juste avant l'impression, que le bouton ou Ctrl+P la déclenche. */
function stampPrintouts() {
  for (const stamp of document.querySelectorAll('[data-print-stamp]')) {
    stamp.textContent = printStamp(new Date());
  }
}

export function initAdminTexts(root = document) {
  window.addEventListener('beforeprint', stampPrintouts);
  // L'interrupteur suit le curseur : clavier, souris ou saisie, la ligne courante décide de ce qui est allumé.
  for (const type of ['keyup', 'click', 'input', 'focusin']) {
    root.addEventListener(type, (event) => {
      if (event.target.matches?.('.rb-text-create textarea, .rb-text-edit textarea')) {
        syncSwitches(event.target);
      }
    });
  }
  root.addEventListener('click', (event) => {
    const createAction = event.target.closest('[data-text-section] [data-action]');
    if (createAction !== null) {
      const createSection = createAction.closest('[data-text-section]');
      switch (createAction.dataset.action) {
        case 'new':
          toggleCreate(createSection, true);
          break;
        case 'cancel-create':
          toggleCreate(createSection, false);
          break;
        case 'heading':
          handleHeading(createAction);
          break;
        case 'create':
          handleCreate(createAction);
          break;
      }

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
      case 'print':
        window.print();
        break;
      case 'heading':
        handleHeading(action);
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
