/**
 * Glissement vers la DROITE sur un message pour le citer (#214), sur écran tactile, comme dans Signal : la bulle suit le
 * doigt, l'icône de réponse apparaît derrière. Vaut pour tous les messages du fil (jamais pour les lignes système, qui n'ont
 * pas `data-message-id`).
 */
import { wireRowSwipe } from './swipe-row.js';

export const QUOTE_SWIPE_WIDTH = 72; // px, distance maximale de la bulle (= CSS --rb-quote-swipe)

/** @param {{ onQuote: (row: Element) => void }} options */
export function wireSwipeQuote(root, { onQuote }) {
  wireRowSwipe(root, { rows: '.rb-chat-message[data-message-id]', direction: 'right', width: QUOTE_SWIPE_WIDTH, onTrigger: onQuote });
}
