/**
 * Logique pure du glissement vers la gauche qui révèle « Supprimer » sur une ligne de la liste (#202), sans DOM :
 * décider si le geste est un glissement ou un défilement, où se trouve la ligne, et si elle reste ouverte.
 */
export const ACTION_WIDTH = 88; // px, largeur de l'action « Supprimer » (identique au CSS)
const LOCK_DISTANCE = 10; // px avant de décider de la direction
const HORIZONTAL_RATIO = 1.5; // un geste doit être nettement horizontal : en cas de doute, le défilement gagne

/** @returns {'undecided' | 'horizontal' | 'vertical'} */
export function classifyGesture(dx, dy) {
  const horizontal = Math.abs(dx);
  const vertical = Math.abs(dy);
  if (Math.max(horizontal, vertical) < LOCK_DISTANCE) {
    return 'undecided';
  }

  return horizontal > vertical * HORIZONTAL_RATIO ? 'horizontal' : 'vertical';
}

/** Position de la ligne (px, ≤ 0) : elle suit le doigt, bornée entre fermée (0) et ouverte (-ACTION_WIDTH). */
export function offsetFor(dx, startOpen, width = ACTION_WIDTH) {
  return Math.min(0, Math.max(-width, (startOpen ? -width : 0) + dx));
}

/** Au relâchement : ouverte si le doigt a dépassé la moitié de l'action. */
export function shouldOpen(offset, width = ACTION_WIDTH) {
  return offset <= -width / 2;
}
