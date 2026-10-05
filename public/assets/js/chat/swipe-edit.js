/**
 * Glissement vers la GAUCHE sur sa propre bulle pour la corriger (#200, #212), sur écran tactile. Même logique de geste que
 * la suppression dans la liste (swipe.js) : un mouvement nettement horizontal, le défilement vertical n'est jamais capté.
 * Contrairement à la liste, l'action se déclenche au relâchement si on a tiré franchement (shouldTrigger) : la bulle suit le
 * doigt, le crayon apparaît derrière, puis la bulle revient. Ne concerne que mes bulles encore modifiables (`data-editable`,
 * posé par le serveur). Aucun conflit avec la sélection de texte d'iOS (pas d'appui long).
 */
import { classifyGesture, offsetFor, shouldTrigger } from './swipe.js';

export const EDIT_SWIPE_WIDTH = 72; // px, distance maximale de la bulle (= CSS --rb-edit-swipe)

const ROW = '.rb-chat-message[data-editable]';
const DRAGGING = 'rb-chat-message--dragging';

/** @param {{ onEdit: (row: Element) => void }} options */
export function wireSwipeEdit(root, { onEdit }) {
  let gesture = null;
  let suppressClickUntil = 0;

  root.addEventListener('pointerdown', (event) => {
    const row = event.pointerType === 'touch' ? event.target.closest(ROW) : null;
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
      if (gesture.mode === 'horizontal' && dx < 0) {
        gesture.row.classList.add(DRAGGING);
        gesture.row.setPointerCapture?.(event.pointerId);
      } else if (gesture.mode !== 'undecided') {
        gesture = null; // défilement vertical ou geste vers la droite : on ne s'en mêle pas

        return;
      }
    }
    if (gesture.mode === 'horizontal') {
      gesture.offset = offsetFor(dx, false, EDIT_SWIPE_WIDTH);
      gesture.row.style.setProperty('--swipe-x', `${gesture.offset}px`);
    }
  });

  const release = (event) => {
    if (gesture === null || event.pointerId !== gesture.id) {
      return;
    }
    if (gesture.mode === 'horizontal') {
      const { row, offset } = gesture;
      row.classList.remove(DRAGGING);
      row.style.removeProperty('--swipe-x');
      suppressClickUntil = Date.now() + 350; // le relâchement ne doit déclencher aucun clic
      if (event.type !== 'pointercancel' && shouldTrigger(offset, EDIT_SWIPE_WIDTH)) {
        onEdit(row);
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
