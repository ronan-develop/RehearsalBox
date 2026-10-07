/**
 * Logique pure de <rb-async-form> (#327), testable sans DOM : sérialisation du formulaire, requête à envoyer, erreurs par champ,
 * et la garde contre le double envoi.
 */

/** Transforme les paires d'un FormData en objet simple (dernière valeur d'un champ répété). */
export function serializeFormEntries(entries) {
  const result = {};
  for (const [key, value] of entries) {
    result[key] = value;
  }
  return result;
}

/**
 * La requête d'un formulaire : l'adresse de l'API et la méthode (POST par défaut). Sans adresse, rien n'est envoyé (on ne retombe
 * jamais sur l'action du formulaire : un envoi natif rechargerait la page).
 *
 * @returns {{endpoint: string, method: string}|null}
 */
export function requestFor({ endpoint, method }) {
  if (typeof endpoint !== 'string' || endpoint === '') {
    return null;
  }

  return { endpoint, method: (method || 'POST').toUpperCase() };
}

/** Les erreurs par champ renvoyées par l'API, réduites à des paires [champ, message texte]. */
export function fieldErrorEntries(fields) {
  return Object.entries(fields && typeof fields === 'object' ? fields : {}).filter(([, message]) => typeof message === 'string' && message !== '');
}

/**
 * Garde contre le double envoi (double clic, Entrée + clic) : deux requêtes concurrentes porteraient le même jeton CSRF ; comme la
 * connexion régénère l'identifiant de session côté serveur, la seconde arriverait sur une session déjà remplacée et échouerait en
 * CSRF invalide malgré des identifiants corrects. Les envois sont ignorés tant qu'un autre est en vol.
 */
export function createSubmitGuard() {
  let inFlight = false;

  return {
    /** Vrai (et verrouille) si aucun envoi n'est en cours. */
    tryEnter() {
      if (inFlight) {
        return false;
      }
      inFlight = true;

      return true;
    },
    leave() {
      inFlight = false;
    },
  };
}
