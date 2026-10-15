/**
 * Pages admin « Assemblées » (#361) : liste (création), formulaire d'un brouillon et correction d'un compte rendu validé. Écoute les
 * événements de <rb-assembly-form>, appelle l'API (apiFetch, CSRF), affiche le résultat. Aucun envoi de formulaire avec rechargement ;
 * la navigation après création ou correction est un changement de page. Le bouton d'impression passe ici (la CSP interdit onclick).
 */
import { apiFetch } from '../../core/api.js';
import { showToast } from '../../core/toast.js';
import { confirmAction } from '../../ui/rb-confirm-dialog.js';
import { DELETE_EVENT, SAVE_EVENT, VALIDATE_EVENT } from './rb-assembly-form.js';
import { needsSaveBeforeValidate } from './assembly-form.js';
import { DELETE_WARNING, VALIDATE_WARNING, correctRequest, createRequest, deleteRequest, failureMessage, saveRequest, validateRequest } from './assembly-requests.js';

function showFieldError(root, key, message) {
  const target = root.querySelector(`[data-field-error="${key}"]`);
  if (target !== null) {
    target.textContent = message;
    target.hidden = message === '';
  }
}

async function handleCreate(root, button) {
  const box = button.closest('[data-assembly-create]');
  showFieldError(root, 'create', '');
  button.disabled = true;
  try {
    const { path, options } = createRequest(box.querySelector('[name="kind"]').value, box.querySelector('[name="heldOn"]').value);
    const created = await apiFetch(path, options);
    window.location.assign(created.url);
  } catch (error) {
    button.disabled = false;
    showFieldError(root, 'create', Object.values(error?.fields ?? {}).join(' ') || failureMessage(error));
  }
}

async function handleCorrect(root, button) {
  const box = button.closest('[data-assembly-correct]');
  showFieldError(root, 'reason', '');
  button.disabled = true;
  try {
    const { path, options } = correctRequest(box.dataset.id, box.querySelector('[name="reason"]').value);
    const created = await apiFetch(path, options);
    window.location.assign(created.url);
  } catch (error) {
    button.disabled = false;
    showFieldError(root, 'reason', error?.fields?.reason ?? failureMessage(error));
  }
}

/** Enregistre le brouillon ; renvoie vrai si c'est fait (la validation en dépend). */
async function save(form, payload, silent = false) {
  form.setBusy(true);
  form.showError('');
  try {
    const { path, options } = saveRequest(form.dataset.id, payload);
    const { revision } = await apiFetch(path, options);
    form.setRevision(revision);
    form.showErrors({});
    if (!silent) {
      showToast('Brouillon enregistré.', 'success');
    }

    return true;
  } catch (error) {
    reportFailure(form, error);

    return false;
  } finally {
    form.setBusy(false);
  }
}

function reportFailure(form, error) {
  if (error?.fields && Object.keys(error.fields).length > 0) {
    form.showErrors(error.fields);
    form.showError('Corrigez les champs signalés.');

    return;
  }
  form.showError(failureMessage(error)); // 409 : le message du serveur invite à recharger la page
}

async function handleValidate(form, detail) {
  if (!(await confirmAction(VALIDATE_WARNING))) {
    return;
  }
  if (needsSaveBeforeValidate(detail.dirty) && !(await save(form, detail.payload, true))) {
    return;
  }
  form.setBusy(true);
  try {
    const { path, options } = validateRequest(form.dataset.id, Number(form.dataset.revision));
    await apiFetch(path, options);
    showToast('Compte rendu validé.', 'success');
    window.location.reload();
  } catch (error) {
    form.setBusy(false);
    reportFailure(form, error);
  }
}

async function handleDelete(form) {
  if (!(await confirmAction(DELETE_WARNING))) {
    return;
  }
  form.setBusy(true);
  try {
    const { path, options } = deleteRequest(form.dataset.id);
    await apiFetch(path, options);
    window.location.assign('/admin/assemblies');
  } catch (error) {
    form.setBusy(false);
    reportFailure(form, error);
  }
}

export function initAdminAssemblies(root = document) {
  root.addEventListener('click', (event) => {
    const create = event.target.closest('[data-assembly-create] [data-action="create"]');
    if (create !== null) {
      handleCreate(root, create);

      return;
    }
    const open = event.target.closest('[data-action="open-correct"]');
    if (open !== null) {
      const panel = open.closest('[data-assembly-correct]')?.querySelector('[data-correct-panel]');
      if (panel) {
        panel.hidden = false;
      }

      return;
    }
    const correct = event.target.closest('[data-assembly-correct] [data-action="correct"]');
    if (correct !== null) {
      handleCorrect(root, correct);

      return;
    }
    if (event.target.closest('[data-print]') !== null) {
      window.print();
    }
  });

  const form = root.querySelector('rb-assembly-form');
  if (form === null) {
    return;
  }
  form.addEventListener(SAVE_EVENT, (event) => save(form, event.detail.payload));
  form.addEventListener(VALIDATE_EVENT, (event) => handleValidate(form, event.detail));
  form.addEventListener(DELETE_EVENT, () => handleDelete(form));
}
