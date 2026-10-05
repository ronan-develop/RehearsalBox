/**
 * Glissement vers la gauche sur mobile pour révéler « Supprimer » sur une conversation de la liste (#202). Un seul
 * écouteur délégué sur la liste (qui reste en place quand le serveur rafraîchit ses lignes). Réservé au toucher :
 * sur ordinateur, le bouton de la colonne de droite suffit. Seules les lignes `data-can-delete` (conversations ouvertes
 * par la personne) bougent. Le bouton déclenche la suppression par le gestionnaire commun (messages-trash.js) : même
 * modale de confirmation partout.
 */
import { ACTION_WIDTH, classifyGesture, offsetFor, shouldOpen } from './swipe.js';

const ROW = '.rb-chat-item[data-can-delete]';
const OPEN = 'rb-chat-item--open';
const DRAGGING = 'rb-chat-item--dragging';

export function initSwipeDelete(list = document.querySelector('[data-chat-list]')) {
  if (!list) {
    return;
  }
  let gesture = null;
  let suppressClickUntil = 0;

  const closeAll = (except = null) => {
    list.querySelectorAll(`.${OPEN}`).forEach((row) => row !== except && row.classList.remove(OPEN));
  };

  list.addEventListener('pointerdown', (event) => {
    const row = event.pointerType === 'touch' ? event.target.closest(ROW) : null;
    closeAll(row);
    gesture = row === null ? null : { row, id: event.pointerId, x: event.clientX, y: event.clientY, startOpen: row.classList.contains(OPEN), mode: 'undecided', offset: 0 };
  });

  list.addEventListener('pointermove', (event) => {
    if (gesture === null || event.pointerId !== gesture.id) {
      return;
    }
    const dx = event.clientX - gesture.x;
    const dy = event.clientY - gesture.y;
    if (gesture.mode === 'undecided') {
      gesture.mode = classifyGesture(dx, dy);
      if (gesture.mode === 'horizontal') {
        gesture.row.classList.add(DRAGGING);
        gesture.row.setPointerCapture?.(event.pointerId);
      }
    }
    if (gesture.mode === 'vertical') {
      gesture = null;
      return;
    }
    if (gesture.mode === 'horizontal') {
      gesture.offset = offsetFor(dx, gesture.startOpen);
      gesture.row.style.setProperty('--swipe-x', `${gesture.offset}px`);
    }
  });

  const release = (event) => {
    if (gesture === null || event.pointerId !== gesture.id) {
      return;
    }
    if (gesture.mode === 'horizontal') {
      const { row } = gesture;
      row.classList.remove(DRAGGING);
      row.style.removeProperty('--swipe-x');
      row.classList.toggle(OPEN, event.type !== 'pointercancel' && shouldOpen(gesture.offset));
      suppressClickUntil = Date.now() + 350; // le relâchement ne doit pas ouvrir la conversation
    }
    gesture = null;
  };
  list.addEventListener('pointerup', release);
  list.addEventListener('pointercancel', release);

  // Un tap sur une ligne ouverte la referme au lieu d'ouvrir la conversation ; après un glissement, aucun clic parasite.
  list.addEventListener('click', (event) => {
    const link = event.target.closest('.rb-chat-item-link');
    if (link === null) {
      return;
    }
    if (Date.now() < suppressClickUntil) {
      event.preventDefault();
    } else if (link.closest(`.${OPEN}`)) {
      event.preventDefault();
      closeAll();
    }
  }, true);

  document.addEventListener('pointerdown', (event) => {
    if (!list.contains(event.target)) {
      closeAll();
    }
  });
}

export { ACTION_WIDTH };
