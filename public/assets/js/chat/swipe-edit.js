/**
 * Glissement vers la GAUCHE sur sa propre bulle pour la corriger (#200, #212), sur écran tactile : la bulle suit le doigt, le
 * crayon apparaît derrière. Ne concerne que mes bulles encore modifiables (`data-editable`, posé par le serveur).
 */
import { wireRowSwipe } from './swipe-row.js';

export const EDIT_SWIPE_WIDTH = 72; // px, distance maximale de la bulle (= CSS --rb-edit-swipe)

/** @param {{ onEdit: (row: Element) => void }} options */
export function wireSwipeEdit(root, { onEdit }) {
  wireRowSwipe(root, { rows: '.rb-chat-message[data-editable]', direction: 'left', width: EDIT_SWIPE_WIDTH, onTrigger: onEdit });
}
