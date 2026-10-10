/**
 * Logique pure de la restauration de la base (#241), testable sans DOM : validation locale du formulaire, corps de la requête
 * et traduction des réponses en message affichable. Le composant <rb-restore> ne fait que relier ces fonctions au DOM.
 */

export const RESTORE_DONE_MESSAGE = "Restauration lancée. L'application peut être indisponible quelques instants : reconnectez-vous puis rechargez cette page pour voir le résultat.";
export const RESTORE_BUSY_MESSAGE = 'Une restauration est déjà en cours.';
export const RESTORE_GENERIC_ERROR = "La restauration n'a pas pu être lancée. Réessayez dans un instant.";

/** Champs que le serveur peut signaler en 422, dans l'ordre où l'on affiche le premier message trouvé. */
const FIELD_ORDER = ['password', 'currentPassword', 'confirmation', 'file'];

/**
 * Validation locale : le mot de passe ne doit pas être vide, et la confirmation doit être EXACTEMENT le mot attendu (casse et
 * espaces compris). Le serveur revérifie tout ; ceci évite un aller-retour inutile.
 *
 * @param {{password: string, confirmation: string}} values
 * @param {string} confirmWord mot à taper pour confirmer (fourni par le serveur)
 * @returns {Record<string, string>} erreurs par champ ; objet vide si le formulaire est valide
 */
export function validateRestoreForm({ password, confirmation }, confirmWord) {
  const errors = {};
  if (password === '') {
    errors.password = 'Saisissez votre mot de passe.';
  }
  if (confirmation !== confirmWord) {
    errors.confirmation = `Tapez exactement « ${confirmWord} » pour confirmer.`;
  }

  return errors;
}

/**
 * @param {{file: string, password: string, confirmation: string}} request
 * @returns {{file: string, password: string, confirmation: string}} corps JSON envoyé à l'API
 */
export function buildRestoreRequest({ file, password, confirmation }) {
  return { file, password, confirmation };
}

/**
 * Message à afficher quand l'envoi échoue. Un 422 montre le message du premier champ signalé (sinon le texte d'erreur) ; un 409
 * dit qu'une restauration tourne déjà ; tout le reste (500, réseau) donne un message générique, sans détail technique.
 *
 * @param {{status?: number, message?: string, fields?: Record<string, unknown>}|unknown} error erreur levée par apiFetch
 * @returns {string}
 */
export function restoreFailureMessage(error) {
  const status = error?.status;
  if (status === 409) {
    return RESTORE_BUSY_MESSAGE;
  }
  if (status === 422) {
    const fields = error.fields ?? {};
    const field = FIELD_ORDER.find((name) => typeof fields[name] === 'string' && fields[name] !== '');
    if (field !== undefined) {
      return fields[field];
    }
    if (typeof error.message === 'string' && error.message !== '') {
      return error.message;
    }
  }

  return RESTORE_GENERIC_ERROR;
}
