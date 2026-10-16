/**
 * Page admin « Écritures récurrentes » (#362) : confirmer ou abandonner une échéance, créer ou modifier un modèle. Appelle l'API (apiFetch,
 * CSRF) et recharge la page au succès ; aucun envoi de formulaire natif. Le serveur revérifie tout.
 */
import { apiFetch } from '../../core/api.js';
import { showToast } from '../../core/toast.js';
import { clearErrors, reportFailure } from '../form-feedback.js';
import { categoriesFor } from './accounting-form.js';
import { confirmOccurrenceRequest, failureMessage, ignoreOccurrenceRequest, templateRequest } from './accounting-requests.js';

const OCCURRENCE_FIELDS = ['amount', 'voucherRef', 'period', 'occurredOn'];
const TEMPLATE_FIELDS = ['label', 'direction', 'category', 'paymentMethod', 'amount', 'voucherRef', 'periodicity', 'dueDay', 'firstMonth'];

function syncCategories(form) {
  const select = form.elements.category;
  const all = JSON.parse(select.dataset.categories ?? '[]');
  const previous = select.value;
  select.replaceChildren(...categoriesFor(form.elements.direction.value, all).map((category) => new Option(category.label, category.value)));
  select.value = [...select.options].some((option) => option.value === previous) ? previous : (select.options[0]?.value ?? '');
}

function fill(form, template) {
  form.elements.id.value = String(template.id ?? '');
  for (const key of ['label', 'direction', 'paymentMethod', 'voucherRef', 'periodicity', 'dueDay', 'firstMonth']) {
    form.elements[key].value = String(template[key === 'paymentMethod' ? 'method' : key] ?? '');
  }
  syncCategories(form);
  form.elements.category.value = template.category ?? form.elements.category.value;
  form.elements.amount.value = template.amountInput ?? '';
  form.elements.isActive.checked = template.isActive !== false;
}

function openTemplate(section, template) {
  const form = section.querySelector('[data-template-form]');
  clearErrors(form, TEMPLATE_FIELDS);
  form.hidden = false;
  section.querySelector('[data-action="new-template"]').hidden = true;
  form.querySelector('[data-template-title]').textContent = template === null ? 'Nouveau modèle' : 'Modifier le modèle';
  if (template === null) {
    form.reset();
    form.elements.id.value = '';
    syncCategories(form);
  } else {
    fill(form, template);
  }
  form.elements.label.focus();
}

function closeTemplate(section) {
  const form = section.querySelector('[data-template-form]');
  form.hidden = true;
  section.querySelector('[data-action="new-template"]').hidden = false;
}

function occurrenceValues(form) {
  return { period: form.dataset.period, amount: form.elements.amount?.value ?? '', voucherRef: form.elements.voucherRef?.value ?? '' };
}

async function send(form, fields, build, done) {
  clearErrors(form, fields);
  const buttons = form.querySelectorAll('button');
  buttons.forEach((button) => { button.disabled = true; });
  try {
    const { path, options } = build();
    done(await apiFetch(path, options));
  } catch (error) {
    buttons.forEach((button) => { button.disabled = false; });
    reportFailure(form, fields, error, failureMessage);
  }
}

function onClick(event) {
  const target = event.target.closest('[data-action]');
  const section = target?.closest('[data-recurring]');
  if (section === null || section === undefined) {
    return;
  }
  switch (target.dataset.action) {
    case 'new-template': openTemplate(section, null); break;
    case 'cancel-template': closeTemplate(section); break;
    case 'edit-template': openTemplate(section, JSON.parse(target.closest('[data-template]').dataset.template)); break;
    case 'ignore-occurrence': {
      const form = target.closest('[data-occurrence-form]');
      send(form, OCCURRENCE_FIELDS, () => ignoreOccurrenceRequest(form.dataset.templateId, { period: form.dataset.period }), (done) => {
        showToast('Échéance abandonnée.', 'success');
        window.location.assign(done.url);
      });
      break;
    }
    default:
  }
}

function onSubmit(event) {
  const form = event.target;
  if (form.matches('[data-occurrence-form]')) {
    event.preventDefault();
    send(form, OCCURRENCE_FIELDS, () => confirmOccurrenceRequest(form.dataset.templateId, occurrenceValues(form)), (done) => {
      showToast(`Écriture n° ${done.number} enregistrée.`, 'success');
      window.location.assign(done.url);
    });
  } else if (form.matches('[data-template-form]')) {
    event.preventDefault();
    const values = Object.fromEntries(TEMPLATE_FIELDS.map((key) => [key, form.elements[key].value]));
    values.isActive = form.elements.isActive.checked;
    send(form, TEMPLATE_FIELDS, () => templateRequest(form.elements.id.value, values), () => {
      showToast('Modèle enregistré.', 'success');
      window.location.reload();
    });
  }
}

export function initAdminRecurring(root = document) {
  root.addEventListener('click', onClick);
  root.addEventListener('submit', onSubmit);
  root.addEventListener('change', (event) => {
    if (event.target.matches('[data-template-form] [name="direction"]')) {
      syncCategories(event.target.closest('[data-template-form]'));
    }
  });
}
