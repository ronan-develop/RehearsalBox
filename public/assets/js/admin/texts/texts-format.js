/**
 * Titres et sous-titres d'un texte (#490), sans DOM : bascule du préfixe « # » (titre) ou « ## » (sous-titre) sur la ligne du curseur.
 * Même convention que le rendu serveur (AssociationTextFormatter) : un préfixe, une espace, puis le texte.
 */
const PREFIXES = { 1: '# ', 2: '## ' };

/** Début et fin (exclue) de la ligne qui contient la position donnée. */
function lineBounds(value, position) {
  const at = Math.min(Math.max(position, 0), value.length);
  const start = value.lastIndexOf('\n', at - 1) + 1;
  const end = value.indexOf('\n', at);

  return { start, end: end === -1 ? value.length : end };
}

/** Préfixe de titre que porte déjà la ligne : 2 pour « ## », 1 pour « # », 0 sinon. */
export function headingLevel(value, position) {
  const { start, end } = lineBounds(value, position);
  const line = value.slice(start, end);
  if (line.startsWith(PREFIXES[2])) {
    return 2;
  }

  return line.startsWith(PREFIXES[1]) ? 1 : 0;
}

/**
 * Bascule le niveau demandé sur la ligne du curseur : le pose, le retire s'il y est déjà, ou remplace l'autre niveau.
 * Le curseur reste sur la même lettre.
 *
 * @returns {{ value: string, caret: number }}
 */
export function toggleHeading(value, position, level) {
  const at = Math.min(Math.max(position, 0), value.length);
  if (PREFIXES[level] === undefined) {
    return { value, caret: at };
  }
  const { start } = lineBounds(value, at);
  const current = headingLevel(value, at);
  const removed = current === 0 ? 0 : PREFIXES[current].length;
  const added = current === level ? '' : PREFIXES[level];
  const next = value.slice(0, start) + added + value.slice(start + removed);

  return { value: next, caret: Math.max(start, at - removed + added.length) };
}
