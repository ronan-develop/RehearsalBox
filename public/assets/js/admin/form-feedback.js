import { apiFetch } from '../core/api.js';

/** Message général d'une erreur : celui du serveur s'il en a donné un, sinon un texte neutre. */
export function failureMessage(error) {
  return typeof error?.message === 'string' && error.message !== '' ? error.message : 'Une erreur est survenue.';
}

/**
 * Messages d'erreur d'un formulaire d'administration, communs aux pages (#362) : un message par champ (`[data-field-error="nom"]`) et un
 * message général (`[data-form-error]`), posés dans le bloc donné ; un message vide masque l'emplacement.
 */
export function setMessage(scope, selector, message) {
  const target = scope.querySelector(selector);
  if (target !== null) {
    target.textContent = message;
    target.hidden = message === '';
  }
}

export function clearErrors(scope, fields) {
  for (const key of fields) {
    setMessage(scope, `[data-field-error="${key}"]`, '');
  }
  setMessage(scope, '[data-form-error]', '');
}

/** Erreurs par champ si le serveur en a renvoyé, sinon le message général (`fallback` en reçoit l'erreur). */
export function reportFailure(scope, fields, error, fallback) {
  const reported = error?.fields ?? {};
  for (const key of fields) {
    setMessage(scope, `[data-field-error="${key}"]`, reported[key] ?? '');
  }
  setMessage(scope, '[data-form-error]', fields.some((key) => reported[key]) ? '' : fallback(error));
}

/**
 * Envoi d'un formulaire d'administration par l'API : efface les erreurs, désactive les boutons le temps de l'envoi, appelle `onSuccess` avec la
 * réponse ; en cas d'échec, remet les boutons et affiche les erreurs par champ (ou le message général). `build()` rend `{ path, options }` ;
 * s'il lève (identifiant ou mois invalide), l'erreur est affichée comme un échec. `call` est l'appel réseau, remplaçable dans les tests.
 */
export async function submitForm(form, fields, build, onSuccess, call = apiFetch) {
  clearErrors(form, fields);
  const buttons = form.querySelectorAll('button');
  buttons.forEach((button) => { button.disabled = true; });
  try {
    const { path, options } = build();
    onSuccess(await call(path, options));
  } catch (error) {
    buttons.forEach((button) => { button.disabled = false; });
    reportFailure(form, fields, error, failureMessage);
  }
}
