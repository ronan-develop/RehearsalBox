/**
 * Logique pure de <rb-color-picker> (#120), testable sans DOM.
 */

/** Couleurs proposées (hexadécimal en minuscules). */
export const PALETTE = ['#b5654a', '#c0392b', '#d98c3a', '#e0b84c', '#6b9a5b', '#3f8f8a', '#5b7fb5', '#8e6bb0'];

/** Couleur par défaut : la première de la palette. */
export const DEFAULT_COLOR = PALETTE[0];

/**
 * Couleur normalisée en minuscules si la valeur est exactement « # » suivi de 6 chiffres hexadécimaux ; null sinon.
 *
 * @param {unknown} value
 */
export function normalizeHex(value) {
  if (typeof value !== 'string' || !/^#[0-9a-fA-F]{6}$/.test(value)) {
    return null;
  }

  return value.toLowerCase();
}

/**
 * Index de la couleur dans la palette ; -1 si la valeur est invalide ou hors palette.
 *
 * @param {unknown} value
 */
export function paletteIndex(value) {
  const color = normalizeHex(value);

  return color === null ? -1 : PALETTE.indexOf(color);
}

/**
 * Couleur à sélectionner pour une touche (flèches en boucle, Début, Fin) ; null pour toute autre touche.
 *
 * @param {number} current index de la couleur active
 * @param {string} key valeur de KeyboardEvent.key
 * @param {number} count nombre de couleurs
 */
export function nextColorIndex(current, key, count) {
  if (count <= 0) {
    return null;
  }
  switch (key) {
    case 'ArrowRight':
    case 'ArrowDown':
      return (current + 1) % count;
    case 'ArrowLeft':
    case 'ArrowUp':
      return (current - 1 + count) % count;
    case 'Home':
      return 0;
    case 'End':
      return count - 1;
    default:
      return null;
  }
}
