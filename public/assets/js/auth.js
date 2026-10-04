/** Login/Register/Logout en XHR — cf. plan §5bis. */
import { initAsyncForms } from './forms.js';
import { apiFetch } from './api.js';
import { showToast } from './toast.js';

/** Remplace le formulaire de demande par le message de confirmation (identique que le compte existe ou non). */
export function revealForgotPasswordConfirmation(form) {
  form.hidden = true;
  form.parentElement?.querySelector('[data-forgot-confirmation]')?.removeAttribute('hidden');
}

/** Vrai quand la page de connexion arrive après une réinitialisation réussie (?reset=1). */
export function isPasswordResetAnnouncement(search) {
  return new URLSearchParams(search).get('reset') === '1';
}

export function initAuth() {
  initAsyncForms();

  if (isPasswordResetAnnouncement(window.location.search)) {
    showToast('Mot de passe modifié. Vous pouvez vous connecter.', 'success');
  }

  document.querySelectorAll('form[data-async][data-endpoint*="/auth/forgot-password"]').forEach((form) => {
    form.addEventListener('async-success', () => revealForgotPasswordConfirmation(form));
    form.addEventListener('async-error', (event) => showToast(event.detail.message, 'error'));
  });

  document.querySelectorAll('form[data-async][data-endpoint*="/auth/reset-password"]').forEach((form) => {
    form.addEventListener('async-success', () => {
      window.location.href = '/login?reset=1';
    });
    form.addEventListener('async-error', (event) => showToast(event.detail.message, 'error'));
  });

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
