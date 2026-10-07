/**
 * Logique pure de <rb-confirm-dialog> (#326), testable sans DOM : ce qu'il faut afficher pour une demande de confirmation, et si un
 * clic tombe hors de la fenêtre (le fond assombri compte comme « Annuler »).
 */

/**
 * @param {string} message texte ; un saut de ligne sépare deux paragraphes
 * @param {{title?: string, confirmLabel?: string, cancelLabel?: string}} [options] titre facultatif et libellés des boutons
 * @returns {{title: string, paragraphs: string[], confirmLabel: string, cancelLabel: string}}
 */
export function describeConfirmation(message, { title = '', confirmLabel = 'Confirmer', cancelLabel = 'Annuler' } = {}) {
  return { title, paragraphs: String(message ?? '').split('\n'), confirmLabel, cancelLabel };
}

/**
 * Le clic est-il en dehors du rectangle de la fenêtre ? (Un clic sur le fond d'un <dialog> modal est reçu par le <dialog> lui-même :
 * seules les coordonnées distinguent le fond de la fenêtre.)
 *
 * @param {{left: number, right: number, top: number, bottom: number}} rect
 */
export function isOutsideRect(rect, x, y) {
  return x < rect.left || x > rect.right || y < rect.top || y > rect.bottom;
}
