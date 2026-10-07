/** Login/Logout en XHR — cf. plan §5bis. */
import { apiFetch } from '../core/api.js';
import { showToast } from '../core/toast.js';
import { clearAllDrafts } from '../messaging/chat/composer/drafts.js';

/** Remplace un formulaire par son message de confirmation ([data-confirmation] dans le même bloc). */
export function revealConfirmation(form) {
  form.hidden = true;
  form.parentElement?.querySelector('[data-confirmation]')?.removeAttribute('hidden');
}

/** Après une modification du nom : le champ reprend le nom normalisé par le serveur ; retourne le message de confirmation. */
export function applyProfileResult(form, result) {
  const input = form.querySelector('[name="displayName"]');
  if (input && typeof result?.displayName === 'string') {
    input.value = result.displayName;
  }

  return 'Nom mis à jour.';
}

/**
 * Après une demande de changement d'adresse : le mot de passe saisi est effacé et le message est générique
 * (identique que l'adresse soit libre ou déjà utilisée) ; retourne ce message.
 */
export function applyEmailChangeRequested(form) {
  form.reset();

  return "Si cette adresse est utilisable, un lien de confirmation vient de lui être envoyé (valable 1 heure).";
}

/** Vrai quand la page de connexion arrive après une réinitialisation réussie (?reset=1). */
/**
 * Page où aller après la connexion : le retour demandé (lien d'un e-mail), déjà validé par le serveur (liste blanche des
 * pages de la messagerie) ; par prudence on n'accepte ici qu'un chemin du même site, sinon le tableau de bord.
 */
export function postLoginDestination(next) {
  return typeof next === 'string' && /^\/(?![/\\])[^\s]*$/.test(next) ? next : '/';
}

export function isPasswordResetAnnouncement(search) {
  return new URLSearchParams(search).get('reset') === '1';
}

export function initAuth() {
  if (isPasswordResetAnnouncement(window.location.search)) {
    showToast('Mot de passe modifié. Vous pouvez vous connecter.', 'success');
  }

  document.querySelectorAll('rb-async-form[endpoint*="/auth/forgot-password"]').forEach((form) => {
    form.addEventListener('async-success', () => revealConfirmation(form));
    form.addEventListener('async-error', (event) => showToast(event.detail.message, 'error'));
  });

  document.querySelectorAll('rb-async-form[endpoint*="/account/profile"]').forEach((form) => {
    form.addEventListener('async-success', (event) => showToast(applyProfileResult(form, event.detail), 'success'));
    form.addEventListener('async-error', (event) => showToast(event.detail.message, 'error'));
  });

  document.querySelectorAll('rb-async-form[endpoint$="/api/account/email"]').forEach((form) => {
    form.addEventListener('async-success', () => showToast(applyEmailChangeRequested(form), 'success'));
    form.addEventListener('async-error', (event) => showToast(event.detail.message, 'error'));
  });

  document.querySelectorAll('rb-async-form[endpoint*="/account/email/confirm"]').forEach((form) => {
    form.addEventListener('async-success', () => revealConfirmation(form));
    form.addEventListener('async-error', (event) => showToast(event.detail.message, 'error'));
  });

  document.querySelectorAll('rb-async-form[endpoint*="/auth/change-password"]').forEach((form) => {
    form.addEventListener('async-success', () => {
      form.reset();
      showToast('Mot de passe modifié. Vos autres appareils ont été déconnectés.', 'success');
    });
    form.addEventListener('async-error', (event) => showToast(event.detail.message, 'error'));
  });

  document.querySelectorAll('rb-async-form[endpoint*="/auth/secure-account"]').forEach((form) => {
    form.addEventListener('async-success', () => revealConfirmation(form));
    form.addEventListener('async-error', (event) => showToast(event.detail.message, 'error'));
  });

  document.querySelectorAll('rb-async-form[endpoint*="/auth/reset-password"]').forEach((form) => {
    form.addEventListener('async-success', () => {
      window.location.href = '/login?reset=1';
    });
    form.addEventListener('async-error', (event) => showToast(event.detail.message, 'error'));
  });

  document.querySelectorAll('rb-async-form[endpoint*="/auth/login"]')
    .forEach((form) => {
      form.addEventListener('async-success', () => {
        window.location.href = postLoginDestination(form.dataset.next);
      });
      form.addEventListener('async-error', (event) => {
        showToast(event.detail.message, 'error');
      });
    });

  document.querySelectorAll('[data-logout]').forEach((button) => {
    button.addEventListener('click', async () => {
      try {
        await apiFetch('/api/auth/logout', { method: 'POST', body: JSON.stringify({}) });
        clearAllDrafts(); // un message non envoyé ne reste pas lisible sur un poste partagé
        window.location.href = '/login';
      } catch (error) {
        showToast(error.message, 'error');
      }
    });
  });
}
