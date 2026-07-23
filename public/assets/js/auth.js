/** Login/Register/Logout en XHR — cf. plan §5bis. */
import { initAsyncForms } from './forms.js';
import { apiFetch } from './api.js';
import { showToast } from './toast.js';

export function initAuth() {
  initAsyncForms();

  document.querySelectorAll('form[data-async][data-endpoint*="/auth/login"], form[data-async][data-endpoint*="/auth/register"]')
    .forEach((form) => {
      form.addEventListener('async-success', () => {
        window.location.href = '/';
      });
      form.addEventListener('async-error', (event) => {
        showToast(event.detail.message, 'error');
      });
    });

  document.querySelectorAll('[data-logout]').forEach((button) => {
    button.addEventListener('click', async () => {
      try {
        await apiFetch('/api/auth/logout', { method: 'POST', body: JSON.stringify({}) });
        window.location.href = '/login';
      } catch (error) {
        showToast(error.message, 'error');
      }
    });
  });
}
