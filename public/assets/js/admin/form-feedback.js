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
