/**
 * Modale de contact groupe — rendue statiquement dans le dashboard (masquée
 * par défaut), pilotée en JS pour rester une SPA sans rechargement de page.
 * L'envoi passe par POST /api/groups/{id}/contact (Symfony Mailer côté
 * serveur) : jamais de mailto:, l'email de contact n'est jamais exposé au client.
 */
import { apiFetch } from './api.js';
import { showToast } from './toast.js';

export function openContactModal(button, root = document) {
  const overlay = root.querySelector('[data-contact-modal-overlay]');
  if (!overlay) {
    return;
  }

  const groupId = button.dataset.contactGroupId;
  const groupName = button.dataset.contactGroupName;

  overlay.querySelector('[data-contact-group-id-input]').value = groupId;
  overlay.querySelector('[data-contact-modal-title]').textContent = `Contacter ${groupName}`;
  overlay.hidden = false;
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
  const groupId = formData.get('groupId');

  try {
    await apiFetch(`/api/groups/${groupId}/contact`, {
      method: 'POST',
      body: JSON.stringify({ message: formData.get('message') }),
    });

    showToast('Message envoyé.', 'success');
    closeContactModal(root);
  } catch (error) {
    showToast(error.message, 'error');
  }
}

export function initContact(root = document) {
  root.addEventListener('click', (event) => {
    const contactButton = event.target.closest('[data-contact-group-id]');
    if (contactButton) {
      openContactModal(contactButton, root);
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
        openContactModal(contactCard, root);
      }
    }
  });

  root.querySelector('[data-contact-form]')?.addEventListener('submit', (event) => {
    handleContactSubmit(event, root);
  });
}
