/**
 * Glissement horizontal sur une ligne du fil, sur écran tactile, qui DÉCLENCHE une action au relâchement (#200, #212, #214).
 * Même logique de geste pour la correction (vers la gauche) et la citation (vers la droite) : un mouvement nettement
 * horizontal, le défilement vertical n'est jamais capté ; la ligne suit le doigt (--swipe-x), puis revient. L'action ne part
 * que si l'on a tiré franchement (aux trois quarts de la largeur). Aucun conflit avec la sélection de texte d'iOS (pas d'appui long).
 */
import { classifyGesture, offsetFor, offsetForRight, shouldTrigger, shouldTriggerRight } from './swipe.js';

const DRAGGING = 'rb-chat-message--dragging';
const SWIPING = { left: 'rb-chat-message--swiping-left', right: 'rb-chat-message--swiping-right' }; // l'action n'est visible que pendant le geste

/**
 * @param {Element} root
 * @param {{ rows: string, direction: 'left' | 'right', width: number, onTrigger: (row: Element) => void }} options
 *        rows = sélecteur des lignes concernées ; width = distance maximale de la ligne en px (= variable CSS du même nom)
 */
export function wireRowSwipe(root, { rows, direction, width, onTrigger }) {
  const left = direction === 'left';
  const offsetOf = (dx) => (left ? offsetFor(dx, false, width) : offsetForRight(dx, width));
  const passes = (offset) => (left ? shouldTrigger(offset, width) : shouldTriggerRight(offset, width));
  let gesture = null;
  let suppressClickUntil = 0;

  root.addEventListener('pointerdown', (event) => {
    const row = event.pointerType === 'touch' ? event.target.closest(rows) : null;
    gesture = row === null ? null : { row, id: event.pointerId, x: event.clientX, y: event.clientY, mode: 'undecided', offset: 0 };
  });

  root.addEventListener('pointermove', (event) => {
    if (gesture === null || event.pointerId !== gesture.id) {
      return;
    }
    const dx = event.clientX - gesture.x;
    const dy = event.clientY - gesture.y;
    if (gesture.mode === 'undecided') {
      gesture.mode = classifyGesture(dx, dy);
      if (gesture.mode === 'horizontal' && (left ? dx < 0 : dx > 0)) {
        gesture.row.classList.add(DRAGGING, SWIPING[direction]);
        gesture.row.setPointerCapture?.(event.pointerId);
      } else if (gesture.mode !== 'undecided') {
        gesture = null; // défilement vertical ou geste dans l'autre sens : on ne s'en mêle pas

        return;
      }
    }
    if (gesture.mode === 'horizontal') {
      gesture.offset = offsetOf(dx);
      gesture.row.style.setProperty('--swipe-x', `${gesture.offset}px`);
    }
  });

  const release = (event) => {
    if (gesture === null || event.pointerId !== gesture.id) {
      return;
    }
    if (gesture.mode === 'horizontal') {
      const { row, offset } = gesture;
      row.classList.remove(DRAGGING, SWIPING[direction]);
      row.style.removeProperty('--swipe-x');
      suppressClickUntil = Date.now() + 350; // le relâchement ne doit déclencher aucun clic
      if (event.type !== 'pointercancel' && passes(offset)) {
        onTrigger(row);
      }
    }
    gesture = null;
  };
  root.addEventListener('pointerup', release);
  root.addEventListener('pointercancel', release);

  root.addEventListener('click', (event) => {
    if (Date.now() < suppressClickUntil) {
      event.preventDefault();
      event.stopPropagation();
    }
  }, true);
}
