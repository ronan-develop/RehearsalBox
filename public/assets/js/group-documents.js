/**
 * Upload/suppression de documents dans l'espace de groupe — multipart via
 * FormData (pas de sérialisation JSON, contrairement à forms.js) car le
 * contenu réel du fichier doit atteindre le serveur pour la validation MIME.
 */
import { apiFetch } from './api.js';
import { showToast } from './toast.js';

export async function handleDocumentUpload(event, fetcher = apiFetch) {
  event.preventDefault();
  const form = event.target;
  const fileInput = form.querySelector('input[type="file"]');
  const file = fileInput?.files?.[0];

  if (!file) {
    return;
  }

  const formData = new FormData();
  formData.append('document', file);

  try {
    await fetcher(form.dataset.endpoint, { method: 'POST', body: formData });
    showToast('Document ajouté.', 'success');
    form.reset();
    window.location.reload();
  } catch (error) {
    showToast(error.message, 'error');
  }
}

export async function handleDocumentDelete(documentId, fetcher = apiFetch) {
  await fetcher(`/api/documents/${documentId}`, { method: 'DELETE' });
}

export function initGroupDocuments(root = document) {
  root.querySelector('[data-document-upload-form]')?.addEventListener('submit', (event) => {
    handleDocumentUpload(event);
  });

  root.addEventListener('click', async (event) => {
    const deleteButton = event.target.closest('[data-delete-document]');
    if (!deleteButton) {
      return;
    }

    try {
      await handleDocumentDelete(deleteButton.dataset.documentId);
      deleteButton.closest('[data-document-id]')?.remove();
      showToast('Document supprimé.', 'success');
    } catch (error) {
      showToast(error.message, 'error');
    }
  });
}
