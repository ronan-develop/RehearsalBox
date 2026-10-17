/**
 * Page admin « Comptabilité » (#362) : panneaux repliés de saisie, d'annulation et de création d'exercice. Appelle l'API (apiFetch, CSRF) et
 * recharge la page au succès. Aucun envoi de formulaire avec rechargement natif : chaque envoi est intercepté. Le serveur revérifie tout.
 */
import { apiFetch } from '../../core/api.js';
import { showToast } from '../../core/toast.js';
import { clearErrors, submitForm } from '../form-feedback.js';
import { categoriesFor, isVoucherRequired, presetValues, todayIso } from './accounting-form.js';
import { approveYearRequest, closeYearRequest, createYearRequest, emptyMonthRequest, failureMessage, recordEntryRequest, reverseRequest } from './accounting-requests.js';

const ENTRY_FIELDS = ['occurredOn', 'label', 'amount', 'direction', 'category', 'paymentMethod', 'voucherRef', 'note', 'memberUserId', 'periodMonth'];
const YEAR_FIELDS = ['label', 'startsOn', 'endsOn', 'openingBalance'];
const REVERSE_FIELDS = ['reason', 'occurredOn'];
const APPROVE_FIELDS = ['approvedOn'];

function values(form) {
  return Object.fromEntries([...new FormData(form).entries()].map(([key, value]) => [key, String(value)]));
}

/** Reconstruit la liste des catégories du sens choisi et garde la sélection si elle est encore permise. */
function syncCategories(form) {
  const direction = form.elements.direction.value;
  const select = form.elements.category;
  const all = JSON.parse(select.dataset.categories ?? '[]');
  const previous = select.value;
  select.replaceChildren(...categoriesFor(direction, all).map((category) => new Option(category.label, category.value)));
  select.value = [...select.options].some((option) => option.value === previous) ? previous : (select.options[0]?.value ?? '');
  syncDues(form);
  const required = isVoucherRequired(direction);
  form.elements.voucherRef.required = required;
  form.querySelector('[data-voucher-hint]').textContent = required ? '(obligatoire pour une dépense)' : '(facultatif)';
}

/** Adhérent et mois n'ont de sens que pour une cotisation : ils s'affichent (et partent) seulement dans ce cas. */
function syncDues(form) {
  const dues = form.querySelector('[data-dues-fields]');
  const show = form.elements.category.value === 'cotisations';
  dues.hidden = !show;
  if (!show) {
    form.elements.memberUserId.value = '';
  }
}

function openEntryForm(section) {
  const form = section.querySelector('[data-entry-form]');
  form.hidden = false;
  section.querySelector('[data-action="new-entry"]').hidden = true;
  syncCategories(form);
  if (form.elements.occurredOn.value === '') {
    form.elements.occurredOn.value = todayIso();
  }
  form.elements.label.focus();
}

function closeEntryForm(section) {
  const form = section.querySelector('[data-entry-form]');
  form.reset();
  clearErrors(form, ENTRY_FIELDS);
  form.hidden = true;
  section.querySelector('[data-action="new-entry"]').hidden = false;
}

function applyPreset(button) {
  const form = button.closest('[data-entry-form]');
  const preset = presetValues(button.dataset.preset);
  if (preset === null) {
    return;
  }
  form.elements.direction.value = preset.direction;
  syncCategories(form);
  form.elements.category.value = preset.category;
  syncDues(form);
  form.elements.paymentMethod.value = preset.paymentMethod;
  form.elements.label.value = preset.label;
  if (preset.amount !== '') {
    form.elements.amount.value = preset.amount;
  }
  (form.elements.amount.value === '' ? form.elements.amount : form.elements.voucherRef).focus();
}

function toggleReverse(card, open) {
  const form = card.querySelector('[data-reverse-form]');
  form.hidden = !open;
  card.querySelector('[data-action="reverse"]').hidden = open;
  if (open) {
    form.elements.occurredOn.value = form.elements.occurredOn.value || todayIso();
    form.elements.reason.focus();
  } else {
    form.reset();
    clearErrors(form, REVERSE_FIELDS);
  }
}

function toggleYear(section, open) {
  section.querySelector('[data-year-form]').hidden = !open;
  section.querySelector('[data-action="new-year"]').hidden = open;
}

/** Panneau de confirmation d'une action sur l'exercice : le bouton d'appel se masque tant que le formulaire est ouvert. */
function toggleYearPanel(box, name, open) {
  box.querySelector(`[data-${name}-form]`).hidden = !open;
  box.querySelector(`[data-action="${name}-year"]`).hidden = open;
  if (open) {
    box.querySelector(`[data-${name}-form] input`)?.focus();
  }
}

/** « Rien à saisir ce mois-ci » : un clic, puis la page se recharge sans cette tâche. */
async function markEmptyMonth(button) {
  button.disabled = true;
  try {
    const { path, options } = emptyMonthRequest(button.dataset.yearId, button.dataset.month);
    const done = await apiFetch(path, options);
    showToast('Mois noté sans mouvement.', 'success');
    window.location.assign(done.url);
  } catch (error) {
    button.disabled = false;
    showToast(error?.fields?.month ?? failureMessage(error), 'error');
  }
}

function onClick(event) {
  const target = event.target.closest('[data-action], [data-preset]');
  if (target === null) {
    return;
  }
  const section = target.closest('[data-accounting]');
  if (target.dataset.preset !== undefined) {
    applyPreset(target);
    return;
  }
  switch (target.dataset.action) {
    case 'new-entry': openEntryForm(section); break;
    case 'cancel-entry': closeEntryForm(section); break;
    case 'reverse': toggleReverse(target.closest('[data-entry]'), true); break;
    case 'cancel-reverse': toggleReverse(target.closest('[data-entry]'), false); break;
    case 'empty-month': markEmptyMonth(target); break;
    case 'new-year': toggleYear(section, true); break;
    case 'cancel-year': toggleYear(section, false); break;
    case 'close-year': toggleYearPanel(target.closest('[data-year-actions]'), 'close', true); break;
    case 'cancel-close': toggleYearPanel(target.closest('[data-year-actions]'), 'close', false); break;
    case 'approve-year': toggleYearPanel(target.closest('[data-year-actions]'), 'approve', true); break;
    case 'cancel-approve': toggleYearPanel(target.closest('[data-year-actions]'), 'approve', false); break;
    default:
  }
}

function onSubmit(event) {
  const form = event.target;
  if (form.matches('[data-entry-form]')) {
    event.preventDefault();
    submitForm(form, ENTRY_FIELDS, () => recordEntryRequest(form.dataset.yearId, values(form)), (done) => {
      showToast(`Écriture n° ${done.number} enregistrée.`, 'success');
      window.location.assign(done.url);
    });
  } else if (form.matches('[data-reverse-form]')) {
    event.preventDefault();
    submitForm(form, REVERSE_FIELDS, () => reverseRequest(form.dataset.entryId, values(form)), (done) => {
      showToast(`Écriture annulée (n° ${done.number}).`, 'success');
      window.location.assign(done.url);
    });
  } else if (form.matches('[data-close-form]')) {
    event.preventDefault();
    const box = form.closest('[data-year-actions]');
    submitForm(form, [], () => closeYearRequest(box.dataset.yearId, { revision: box.dataset.revision }), (done) => {
      showToast('Exercice clôturé.', 'success');
      window.location.assign(done.url);
    });
  } else if (form.matches('[data-approve-form]')) {
    event.preventDefault();
    const box = form.closest('[data-year-actions]');
    submitForm(form, APPROVE_FIELDS, () => approveYearRequest(box.dataset.yearId, values(form)), (done) => {
      showToast('Approbation enregistrée.', 'success');
      window.location.assign(done.url);
    });
  } else if (form.matches('[data-year-form]')) {
    event.preventDefault();
    submitForm(form, YEAR_FIELDS, () => createYearRequest(values(form)), (done) => window.location.assign(done.url));
  }
}

export function initAdminAccounting(root = document) {
  root.addEventListener('click', onClick);
  root.addEventListener('submit', onSubmit);
  root.addEventListener('change', (event) => {
    if (event.target.matches('[data-entry-form] [name="direction"]')) {
      syncCategories(event.target.closest('[data-entry-form]'));
    } else if (event.target.matches('[data-entry-form] [name="category"]')) {
      syncDues(event.target.closest('[data-entry-form]'));
    }
  });
}
