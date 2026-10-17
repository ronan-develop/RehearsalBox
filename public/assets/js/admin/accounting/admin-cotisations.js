/**
 * Page admin « Cotisations » (#362) : pointer la cotisation d'un adhérent pour un mois (écriture de 20 € préremplie, enregistrée par l'API des
 * écritures) et poser ou retirer une dispense. Appelle l'API (apiFetch, CSRF) et recharge la page au succès ; aucun envoi de formulaire natif.
 */
import { apiFetch } from '../../core/api.js';
import { showToast } from '../../core/toast.js';
import { clearErrors, failureMessage, submitForm } from '../form-feedback.js';
import { todayIso } from './accounting-form.js';
import { exemptionRequest, recordEntryRequest } from './accounting-requests.js';

const FIELDS = ['occurredOn', 'amount', 'paymentMethod', 'memberUserId', 'periodMonth'];

function openForm(section, button) {
  const form = section.querySelector('[data-dues-form]');
  clearErrors(form, FIELDS);
  form.elements.memberUserId.value = button.dataset.memberId;
  form.elements.periodMonth.value = button.dataset.month;
  form.elements.occurredOn.value = todayIso();
  form.elements.amount.value = form.dataset.defaultAmount;
  form.querySelector('[data-dues-title]').textContent = `Cotisation de ${button.dataset.memberName} pour ${button.dataset.monthLabel}`;
  form.hidden = false;
  form.scrollIntoView({ block: 'center' });
  form.elements.amount.focus();
}

async function onClick(event) {
  const target = event.target.closest('[data-action]');
  const section = target?.closest('[data-cotisations]');
  if (section === null || section === undefined) {
    return;
  }
  if (target.dataset.action === 'pay-dues') {
    openForm(section, target);
  } else if (target.dataset.action === 'cancel-dues') {
    section.querySelector('[data-dues-form]').hidden = true;
  } else if (target.dataset.action === 'exempt-dues') {
    target.disabled = true;
    try {
      const { path, options } = exemptionRequest(target.dataset.memberId, target.dataset.month, target.dataset.exempt === '1');
      const done = await apiFetch(path, options);
      window.location.assign(done.url);
    } catch (error) {
      target.disabled = false;
      showToast(error?.fields?.periodMonth ?? failureMessage(error), 'error');
    }
  }
}

function onSubmit(event) {
  const form = event.target;
  if (!form.matches('[data-dues-form]')) {
    return;
  }
  event.preventDefault();
  const values = {
    occurredOn: form.elements.occurredOn.value,
    amount: form.elements.amount.value,
    paymentMethod: form.elements.paymentMethod.value,
    memberUserId: form.elements.memberUserId.value,
    periodMonth: form.elements.periodMonth.value,
    direction: 'recette',
    category: 'cotisations',
    label: 'Cotisation',
  };
  submitForm(form, FIELDS, () => recordEntryRequest(form.dataset.yearId, values), (done) => {
    showToast(`Écriture n° ${done.number} enregistrée.`, 'success');
    window.location.reload();
  });
}

export function initAdminCotisations(root = document) {
  root.addEventListener('click', onClick);
  root.addEventListener('submit', onSubmit);
}
