/**
 * Modale « nouvelle conversation » avec un groupe — rendue statiquement (masquée
 * par défaut), pilotée en JS pour rester une SPA sans rechargement de page.
 * L'envoi passe par POST /api/conversations (#153) : jamais de mailto:, l'email
 * de contact n'est jamais exposé au client. Le serveur revérifie que la personne
 * appartient bien au groupe choisi pour écrire.
 */
import { apiFetch } from './api.js';
import { showToast } from './toast.js';

/** Un groupe ne peut pas s'écrire à lui-même : le groupe visé n'est jamais proposé comme émetteur. */
export function eligibleSenderGroups(groups, targetGroupId) {
  return groups.filter((group) => String(group.id) !== String(targetGroupId));
}

/**
 * Remplit la liste « Écrire en tant que » avec les groupes de la personne (hors groupe visé).
 * La liste n'est visible que s'il y a un vrai choix (plusieurs groupes) ; avec un seul groupe il est
 * présélectionné. Renvoie le nombre de groupes possibles (0 = impossible d'écrire).
 */
export function configureSenderChoice(field, select, targetGroupId) {
  const groups = JSON.parse(field.dataset.groups || '[]');
  const eligible = eligibleSenderGroups(groups, targetGroupId);

  select.replaceChildren(...eligible.map((group) => {
    const option = document.createElement('option');
    option.value = String(group.id);
    option.textContent = group.name;
    return option;
  }));
  select.value = eligible.length > 0 ? String(eligible[0].id) : '';
  field.hidden = eligible.length <= 1;

  return eligible.length;
}

export function openContactModal(button, root = document) {
  const overlay = root.querySelector('[data-contact-modal-overlay]');
  if (!overlay) {
    return;
  }

  const groupId = button.dataset.contactGroupId;
  const groupName = button.dataset.contactGroupName;

  const senderField = overlay.querySelector('[data-contact-from-field]');
  const senderSelect = overlay.querySelector('[data-contact-from-select]');
  if (senderField && senderSelect && configureSenderChoice(senderField, senderSelect, groupId) === 0) {
    showToast('Vous devez appartenir à un autre groupe pour écrire à celui-ci.', 'error');
    return;
  }

  overlay.querySelector('[data-contact-group-id-input]').value = groupId;
  overlay.querySelector('[data-contact-modal-title]').textContent = `Écrire à ${groupName}`;
  overlay.hidden = false;
}

export function handlePlanningCardActivation(card, root = document, navigate = (url) => { window.location.href = url; }) {
  const groupRole = card.dataset.currentUserGroupRole;
  if (groupRole) {
    navigate(`/groups/${card.dataset.contactGroupSlug}/space`);
    return;
  }

  openContactModal(card, root);
}

export function closeContactModal(root = document) {
  const overlay = root.querySelector('[data-contact-modal-overlay]');
  if (!overlay) {
    return;
  }

  overlay.hidden = true;
  overlay.querySelector('[data-contact-form]').reset();
}

export async function handleContactSubmit(event, root = document) {
  event.preventDefault();
  const form = event.target;
  const formData = new FormData(form);

  try {
    await apiFetch('/api/conversations', {
      method: 'POST',
      body: JSON.stringify({
        groupId: formData.get('fromGroupId'),
        targetGroupId: formData.get('targetGroupId'),
        subject: formData.get('subject'),
        message: formData.get('message'),
      }),
    });

    showToast('Conversation démarrée : retrouvez-la dans vos messages.', 'success');
    closeContactModal(root);
  } catch (error) {
    showToast(error.message, 'error');
  }
}

export function initContact(root = document) {
  root.addEventListener('click', (event) => {
    const contactButton = event.target.closest('[data-contact-group-id]');
    if (contactButton) {
      handlePlanningCardActivation(contactButton, root);
    }

    if (event.target.closest('[data-contact-modal-cancel]')) {
      closeContactModal(root);
    }

    if (event.target.matches('[data-contact-modal-overlay]')) {
      closeContactModal(root);
    }
  });

  root.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      const overlay = root.querySelector('[data-contact-modal-overlay]');
      if (overlay && !overlay.hidden) {
        closeContactModal(root);
      }
      return;
    }

    if (event.key === 'Enter' || event.key === ' ') {
      const contactCard = event.target.closest('[data-contact-group-id]');
      if (contactCard) {
        event.preventDefault();
        handlePlanningCardActivation(contactCard, root);
      }
    }
  });

  root.querySelector('[data-contact-form]')?.addEventListener('submit', (event) => {
    handleContactSubmit(event, root);
  });
}
