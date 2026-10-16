/**
 * Page admin « Relevés de compte » (#362) : panneau replié de dépôt (aussi utilisé pour « Remplacer »). Envoi multipart par l'API (apiFetch,
 * CSRF) puis rechargement de la page ; aucun envoi de formulaire natif. Le serveur revérifie tout (type réel, taille, période, doublon).
 */
import { apiFetch } from '../../core/api.js';
import { showToast } from '../../core/toast.js';
import { clearErrors, reportFailure } from '../form-feedback.js';
import { depositStatementRequest, failureMessage } from './accounting-requests.js';

const FIELDS = ['file', 'periodStart', 'periodEnd', 'closingBalance', 'replaces'];

function openForm(section, replacesId) {
  const form = section.querySelector('[data-statement-form]');
  form.hidden = false;
  form.elements.replaces.value = replacesId;
  form.querySelector('[data-statement-title]').textContent = replacesId === '' ? 'Déposer un relevé' : 'Remplacer un relevé';
  section.querySelector('[data-action="new-statement"]').hidden = true;
  form.elements.file.focus();
}

function closeForm(section) {
  const form = section.querySelector('[data-statement-form]');
  form.reset();
  clearErrors(form, FIELDS);
  form.hidden = true;
  section.querySelector('[data-action="new-statement"]').hidden = false;
}

function onClick(event) {
  const target = event.target.closest('[data-action]');
  const section = target?.closest('[data-statements]');
  if (section === null || section === undefined) {
    return;
  }
  switch (target.dataset.action) {
    case 'new-statement': openForm(section, ''); break;
    case 'replace-statement': openForm(section, target.dataset.statementId ?? ''); section.querySelector('[data-statement-form]').scrollIntoView({ block: 'center' }); break;
    case 'cancel-statement': closeForm(section); break;
    default:
  }
}

async function onSubmit(event) {
  const form = event.target;
  if (!form.matches('[data-statement-form]')) {
    return;
  }
  event.preventDefault();
  clearErrors(form, FIELDS);
  const button = form.querySelector('[type="submit"]');
  button.disabled = true;
  try {
    const values = Object.fromEntries(['periodStart', 'periodEnd', 'closingBalance', 'replaces'].map((key) => [key, form.elements[key].value]));
    const { path, options } = depositStatementRequest(form.dataset.yearId, values, form.elements.file.files[0] ?? new Blob([]));
    const done = await apiFetch(path, options);
    showToast('Relevé enregistré.', 'success');
    window.location.assign(done.url);
  } catch (error) {
    button.disabled = false;
    reportFailure(form, FIELDS, error, failureMessage);
  }
}

export function initAdminStatements(root = document) {
  root.addEventListener('click', onClick);
  root.addEventListener('submit', onSubmit);
}
