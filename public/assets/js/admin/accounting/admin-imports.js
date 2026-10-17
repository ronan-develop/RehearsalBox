/**
 * Pages « Importer la banque » et « Règles des libellés » (#362) : aperçu du fichier, mise en attente, relecture, validation en lot, règles.
 * Appelle l'API (apiFetch, CSRF) ; aucun envoi de formulaire natif. Tout texte venu du fichier de la banque s'écrit avec `textContent`, jamais
 * `innerHTML`. Le serveur revérifie tout et décide.
 */
import { apiFetch } from '../../core/api.js';
import { showToast } from '../../core/toast.js';
import { clearErrors, failureMessage, setMessage, submitForm } from '../form-feedback.js';
import { PROFILE_FIELDS, deleteRuleRequest, discardLineRequest, formatCents, previewRequest, previewSummary, ruleRequest, splitErrorKey, stageRequest, validateRequest } from './import-requests.js';

const FORM_FIELDS = ['file', ...PROFILE_FIELDS];

function profileValues(form) {
  return Object.fromEntries(PROFILE_FIELDS.map((key) => [key, form.elements[key]?.value ?? '']));
}

function syncAmountMode(form) {
  const split = form.elements.amountMode.value === 'split';
  form.querySelectorAll('[data-amount-signed]').forEach((el) => { el.hidden = split; });
  form.querySelectorAll('[data-amount-split]').forEach((el) => { el.hidden = !split; });
}

function renderPreview(form, preview) {
  const box = form.querySelector('[data-import-preview]');
  box.hidden = false;
  box.querySelector('[data-preview-summary]').textContent = previewSummary(preview);
  box.querySelector('[data-preview-lines]').replaceChildren(...preview.lines.map((line) => {
    const item = document.createElement('li');
    item.textContent = `${line.date}  ${line.label}  ${formatCents(line.cents, line.direction)}`;

    return item;
  }));
  box.querySelector('[data-preview-errors]').replaceChildren(...Object.entries(preview.errors ?? {}).map(([record, reason]) => {
    const item = document.createElement('li');
    item.textContent = record === '0' ? reason : `Ligne ${record} : ${reason}`;

    return item;
  }));
}

async function preview(form) {
  clearErrors(form, FORM_FIELDS);
  try {
    const { path, options } = previewRequest(profileValues(form), form.elements.file.files[0] ?? null);
    renderPreview(form, await apiFetch(path, options));
  } catch (error) {
    form.querySelector('[data-import-preview]').hidden = true;
    const reported = error?.fields ?? {};
    for (const key of FORM_FIELDS) {
      setMessage(form, `[data-field-error="${key}"]`, reported[key] ?? '');
    }
    setMessage(form, '[data-form-error]', Object.keys(reported).length > 0 ? '' : failureMessage(error));
  }
}

function syncDues(card) {
  const dues = card.querySelector('[data-dues]');
  const select = card.querySelector('[data-field="memberUserId"]');
  const show = card.querySelector('[data-field="category"]').value === 'cotisations';
  dues.hidden = !show;
  if (show && select.options.length === 0) {
    select.append(...document.querySelector('[data-members-template]').content.cloneNode(true).children);
    select.value = dues.dataset.member ?? '';
  }
}

function cardData(card) {
  const field = (name) => card.querySelector(`[data-field="${name}"]`);
  const dues = card.querySelector('[data-dues]').hidden === false;

  return {
    line: card.dataset.lineId,
    include: field('include').checked,
    label: field('label').value,
    category: field('category').value,
    paymentMethod: field('paymentMethod').value,
    voucherRef: field('voucherRef').value,
    memberUserId: dues ? field('memberUserId').value : '',
    periodMonth: dues ? field('periodMonth').value : '',
    occurrence: card.dataset.occurrence ?? '',
    remember: field('remember').checked,
  };
}

function showRowErrors(section, fields) {
  section.querySelectorAll('[data-import-line] [data-field-error]').forEach((el) => { el.hidden = true; el.textContent = ''; });
  let general = '';
  for (const [key, message] of Object.entries(fields)) {
    const target = splitErrorKey(key);
    const element = target === null ? null : section.querySelector(`[data-line-id="${target.line}"] [data-field-error="${target.field}"]`);
    if (element === null) {
      general = general === '' ? message : `${general} ${message}`;
    } else {
      element.textContent = message;
      element.hidden = false;
    }
  }
  setMessage(section, '[data-validate-error]', general);
}

async function validate(section, button) {
  button.disabled = true;
  try {
    const cards = [...section.querySelectorAll('[data-import-line]')].map(cardData);
    const { path, options } = validateRequest(cards);
    const done = await apiFetch(path, options);
    showToast(`${done.entries} écriture${done.entries > 1 ? 's' : ''} enregistrée${done.entries > 1 ? 's' : ''}.`, 'success');
    window.location.assign(done.url);
  } catch (error) {
    button.disabled = false;
    showRowErrors(section, error?.fields ?? {});
    if (Object.keys(error?.fields ?? {}).length === 0) {
      setMessage(section, '[data-validate-error]', failureMessage(error));
    }
  }
}

async function run(button, build, success) {
  button.disabled = true;
  try {
    const { path, options } = build();
    const done = await apiFetch(path, options);
    showToast(success, 'success');
    window.location.assign(done.url);
  } catch (error) {
    button.disabled = false;
    showToast(error?.fields?.category ?? failureMessage(error), 'error');
  }
}

function onClick(event) {
  const target = event.target.closest('[data-action]');
  if (target === null) {
    return;
  }
  const imports = target.closest('[data-imports]');
  const rules = target.closest('[data-rules]');
  switch (target.dataset.action) {
    case 'preview-import': preview(target.closest('[data-import-form]')); break;
    case 'validate-import': validate(imports, target); break;
    case 'discard-line': run(target, () => discardLineRequest(target.closest('[data-import-line]').dataset.lineId), 'Ligne écartée.'); break;
    case 'save-rule': {
      const rule = target.closest('[data-rule]');
      run(target, () => ruleRequest(rule.dataset.ruleId, { category: rule.querySelector('[data-field="category"]').value, paymentMethod: rule.querySelector('[data-field="paymentMethod"]').value }), 'Règle enregistrée.');
      break;
    }
    case 'delete-rule': if (rules !== null) { run(target, () => deleteRuleRequest(target.closest('[data-rule]').dataset.ruleId), 'Règle supprimée.'); } break;
    default:
  }
}

function onSubmit(event) {
  const form = event.target;
  if (!form.matches('[data-import-form]')) {
    return;
  }
  event.preventDefault();
  submitForm(form, FORM_FIELDS, () => stageRequest(profileValues(form), form.elements.file.files[0] ?? null), (done) => {
    showToast(`${done.staged} ligne${done.staged > 1 ? 's' : ''} en attente${done.duplicates > 0 ? `, ${done.duplicates} déjà connue${done.duplicates > 1 ? 's' : ''}` : ''}.`, 'success');
    window.location.assign(done.url);
  });
}

export function initAdminImports(root = document) {
  root.addEventListener('click', onClick);
  root.addEventListener('submit', onSubmit);
  root.addEventListener('change', (event) => {
    if (event.target.matches('[data-import-form] [name="amountMode"]')) {
      syncAmountMode(event.target.form);
    } else if (event.target.matches('[data-import-line] [data-field="category"]')) {
      const card = event.target.closest('[data-import-line]');
      syncDues(card);
      card.querySelector('[data-field="include"]').checked = event.target.value !== '';
    }
  });
  root.querySelectorAll('[data-import-form]').forEach(syncAmountMode);
  root.querySelectorAll('[data-import-line]').forEach(syncDues);
}
