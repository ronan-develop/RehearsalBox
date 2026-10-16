/**
 * Sous-titres d'un texte (#490), sans DOM : bascule du préfixe « ## » sur la ligne du curseur (le titre du texte a son propre champ).
 * Même convention que le rendu serveur (AssociationTextFormatter) : le préfixe, une espace, puis le texte.
 */
const PREFIX = '## ';

/** Début et fin (exclue) de la ligne qui contient la position donnée. */
function lineBounds(value, position) {
  const at = Math.min(Math.max(position, 0), value.length);
  const start = value.lastIndexOf('\n', at - 1) + 1;
  const end = value.indexOf('\n', at);

  return { start, end: end === -1 ? value.length : end };
}

/** Vrai si la ligne du curseur est un sous-titre. */
export function isSubtitle(value, position) {
  const { start, end } = lineBounds(value, position);

  return value.slice(start, end).startsWith(PREFIX);
}

/**
 * Pose le préfixe sur la ligne du curseur, ou le retire s'il y est déjà. Le curseur reste sur la même lettre.
 *
 * @returns {{ value: string, caret: number }}
 */
export function toggleSubtitle(value, position) {
  const at = Math.min(Math.max(position, 0), value.length);
  const { start } = lineBounds(value, at);
  if (isSubtitle(value, at)) {
    return { value: value.slice(0, start) + value.slice(start + PREFIX.length), caret: Math.max(start, at - PREFIX.length) };
  }

  return { value: value.slice(0, start) + PREFIX + value.slice(start), caret: at + PREFIX.length };
}
