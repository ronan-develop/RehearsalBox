/**
 * Filtre par nom de la liste « Nouveau message » (#269) : logique pure, sans DOM (testée à part). Sous-chaîne, sans tenir
 * compte de la casse ni des accents, espaces autour de la requête ignorés.
 */
const fold = (text) => text.normalize('NFD').replace(/\p{M}/gu, '').toLowerCase();

export function matchesName(name, query) {
  const wanted = fold(query.trim());

  return wanted === '' || fold(name).includes(wanted);
}

/** @param {string[]} names @returns {{ flags: boolean[], visible: number }} un booléen par nom, et le nombre de noms visibles */
export function visibility(names, query) {
  const flags = names.map((name) => matchesName(name, query));

  return { flags, visible: flags.filter(Boolean).length };
}
