/**
 * Logique pure de <rb-planning-card> (#330), testable sans DOM : où mène une carte du planning, et quelles touches l'activent.
 */

/**
 * Un groupe dont on est membre ouvre son espace ; un autre groupe ouvre la page « nouvelle conversation » (/messages/new/{id}), où
 * l'on écrit le premier message. Le serveur revérifie tout à l'envoi, l'adresse e-mail de contact n'est jamais exposée au client.
 *
 * @param {{groupId: string, slug: string, isMember: boolean}} card
 */
export function destinationFor({ groupId, slug, isMember }) {
  return isMember ? `/groups/${slug}/space` : `/messages/new/${groupId}`;
}

/** Entrée et Espace activent la carte, comme un bouton. */
export function isActivationKey(key) {
  return key === 'Enter' || key === ' ';
}
