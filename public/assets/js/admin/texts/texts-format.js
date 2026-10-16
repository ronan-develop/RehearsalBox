/**
 * Mise en forme d'un texte (#490, #492), sans DOM : bascule d'un préfixe de ligne, « ## » (sous-titre) ou « - » (élément de liste),
 * sur la ligne du curseur. Même convention que le rendu serveur (AssociationTextFormatter) : le préfixe, une espace, puis le texte.
 */
export const PREFIXES = { subtitle: '## ', list: '- ' };

const KNOWN = Object.values(PREFIXES);

/** Début et fin (exclue) de la ligne qui contient la position donnée. */
function lineBounds(value, position) {
  const at = Math.min(Math.max(position, 0), value.length);
  const start = value.lastIndexOf('\n', at - 1) + 1;
  const end = value.indexOf('\n', at);

  return { start, end: end === -1 ? value.length : end };
}

/** Le préfixe que porte la ligne du curseur, ou une chaîne vide. */
export function linePrefix(value, position) {
  const { start, end } = lineBounds(value, position);
  const line = value.slice(start, end);

  return KNOWN.find((prefix) => line.startsWith(prefix)) ?? '';
}

/**
 * Pose le préfixe demandé sur la ligne du curseur, le retire s'il y est déjà, ou remplace l'autre. Le curseur reste sur la même lettre.
 *
 * @returns {{ value: string, caret: number }}
 */
export function toggleLinePrefix(value, position, prefix) {
  const at = Math.min(Math.max(position, 0), value.length);
  if (!KNOWN.includes(prefix)) {
    return { value, caret: at };
  }
  const { start } = lineBounds(value, at);
  const current = linePrefix(value, at);
  const added = current === prefix ? '' : prefix;

  return { value: value.slice(0, start) + added + value.slice(start + current.length), caret: Math.max(start, at - current.length + added.length) };
}
