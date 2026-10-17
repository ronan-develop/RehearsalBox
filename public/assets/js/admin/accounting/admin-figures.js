/**
 * Bouton « Reprendre les chiffres de l'exercice » du compte rendu d'AG (#362) : lit les chiffres de l'exercice le plus récent (lecture seule)
 * et les ajoute au rapport financier, sans rien enregistrer : la personne relit puis enregistre le brouillon comme d'habitude.
 */
import { apiFetch } from '../../core/api.js';
import { showToast } from '../../core/toast.js';
import { mergeFigures } from './figures.js';

async function onClick(event) {
  const button = event.target.closest('[data-import-figures]');
  if (button === null) {
    return;
  }
  const field = document.getElementById('assembly-financialReport');
  if (field === null) {
    return;
  }
  button.disabled = true;
  try {
    const figures = await apiFetch('/api/admin/accounting/figures');
    field.value = mergeFigures(field.value, figures.text);
    field.dispatchEvent(new Event('input', { bubbles: true }));
    showToast(`Chiffres de l'exercice ${figures.label} ajoutés : relisez puis enregistrez.`, 'success');
  } catch (error) {
    showToast(error?.status === 404 ? 'Aucun exercice dans la comptabilité.' : 'Impossible de reprendre les chiffres.', 'error');
  } finally {
    button.disabled = false;
  }
}

export function initAdminFigures(root = document) {
  root.addEventListener('click', onClick);
}
