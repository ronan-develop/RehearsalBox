/**
 * Corbeille de la messagerie (#190) : un seul écouteur délégué pour les boutons `data-trash-action` (supprimer une
 * conversation, la restaurer, la supprimer pour de bon, fermer un avis). Le serveur rend les pages ; ici seulement l'appel
 * XHR, la confirmation (<rb-confirm-modal>) et la mise à jour minimale de la page.
 */
import { confirmAction } from '../ui/rb-confirm-modal.js';
import { dismissAlert, purgeConversation, restoreConversation, trashConversation } from './chat/api.js';

// Un seul texte pour tous les points d'entrée (bouton de l'en-tête, colonne de la liste, glissement).
export const CONFIRM_DELETE = {
  message: "Elle disparaît tout de suite chez l'autre groupe.\nVous pouvez la restaurer pendant 30 jours depuis la corbeille, puis elle est supprimée définitivement.",
  options: { title: 'Supprimer cette conversation ?', confirmLabel: 'Mettre à la corbeille', cancelLabel: 'Garder' },
};
export const CONFIRM_PURGE = {
  message: 'Cette action est irréversible : la conversation et tous ses messages sont effacés pour tout le monde.',
  options: { title: 'Supprimer définitivement ?', confirmLabel: 'Supprimer définitivement', cancelLabel: 'Annuler' },
};

export function initMessagesTrash(root = document, {
  confirm = confirmAction,
  navigate = (url) => window.location.assign(url),
  reload = () => window.location.reload(),
  notify = (message) => {
    const slot = root.querySelector('[data-trash-error]');
    if (slot) {
      slot.textContent = message;
      slot.hidden = false;
    } else {
      window.alert(message);
    }
  },
} = {}) {
  const removeRow = (button) => {
    button.closest('[data-trash-item], [data-trash-alert]')?.remove();
    const empty = root.querySelector('[data-trash-empty]');
    if (empty) {
      empty.hidden = root.querySelectorAll('[data-trash-item]').length > 0;
    }
  };

  const actions = {
    delete: { ask: CONFIRM_DELETE, run: trashConversation, done: () => navigate('/messages') },
    restore: { run: restoreConversation, done: () => reload() },
    purge: { ask: CONFIRM_PURGE, run: purgeConversation, done: removeRow },
    dismiss: { run: dismissAlert, done: removeRow },
  };

  root.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-trash-action]');
    const action = button && actions[button.dataset.trashAction];
    if (!action) {
      return;
    }
    if (action.ask && !(await confirm(action.ask.message, action.ask.options))) {
      return;
    }
    try {
      await action.run(button.dataset.id);
      action.done(button);
    } catch (error) {
      notify(error?.message ?? 'Une erreur est survenue.');
    }
  });
}
