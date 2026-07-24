/**
 * Intercepte le submit de tout <form data-async>, sérialise en JSON,
 * appelle apiFetch — jamais de rechargement de page (cf. plan §5bis).
 */
import { apiFetch } from './api.js';

export function serializeFormEntries(entries) {
  const result = {};
  for (const [key, value] of entries) {
    result[key] = value;
  }
  return result;
}

function showFieldErrors(form, fields) {
  form.querySelectorAll('[data-field-error]').forEach((el) => {
    el.textContent = '';
  });
  for (const [field, message] of Object.entries(fields || {})) {
    const target = form.querySelector(`[data-field-error="${field}"]`);
    if (target) {
      target.textContent = message;
    }
  }
}

export function initAsyncForms(root = document) {
  root.querySelectorAll('form[data-async]').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();

      // Un double submit (double-clic, Entrée + clic) enverrait deux requêtes
      // concurrentes avec le même jeton CSRF ; comme la connexion régénère
      // l'ID de session côté serveur, la seconde arriverait sur une session
      // déjà remplacée et échouerait en CSRF invalide malgré des identifiants
      // corrects. On ignore les soumissions tant qu'une est déjà en vol.
      if (form.dataset.submitting === 'true') {
        return;
      }
      form.dataset.submitting = 'true';

      const endpoint = form.dataset.endpoint || form.action;
      const method = (form.dataset.method || form.method || 'POST').toUpperCase();
      const payload = serializeFormEntries(new FormData(form).entries());

      try {
        const result = await apiFetch(endpoint, { method, body: JSON.stringify(payload) });
        form.dispatchEvent(new CustomEvent('async-success', { detail: result }));
      } catch (error) {
        showFieldErrors(form, error.fields);
        form.dispatchEvent(new CustomEvent('async-error', { detail: error }));
      } finally {
        delete form.dataset.submitting;
      }
    });
  });
}
