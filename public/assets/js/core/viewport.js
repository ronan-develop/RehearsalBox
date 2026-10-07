/**
 * Seuil unique entre téléphone et bureau : le même que les media queries du CSS (`min-width: 768px`). Une largeur inconnue
 * (tests, environnement sans fenêtre) compte comme bureau.
 */
export const DESKTOP_MIN_WIDTH = 768;

export function isDesktopWidth(width) {
  return (width ?? Infinity) >= DESKTOP_MIN_WIDTH;
}
