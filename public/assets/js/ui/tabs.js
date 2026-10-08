/**
 * Logique pure de <rb-tabs> (#328), testable sans DOM : navigation au clavier, onglet initial, mémorisation facultative.
 */

/**
 * Onglet à activer pour une touche (motif ARIA des onglets : flèches gauche/droite en boucle, Début, Fin) ; null pour toute autre touche.
 *
 * @param {number} current index de l'onglet actif
 * @param {string} key valeur de KeyboardEvent.key
 * @param {number} count nombre d'onglets
 */
export function nextTabIndex(current, key, count) {
  if (count <= 0) {
    return null;
  }
  switch (key) {
    case 'ArrowRight':
      return (current + 1) % count;
    case 'ArrowLeft':
      return (current - 1 + count) % count;
    case 'Home':
      return 0;
    case 'End':
      return count - 1;
    default:
      return null;
  }
}

/**
 * L'onglet affiché au départ : le dernier choisi (s'il existe encore), sinon celui que le serveur a marqué sélectionné, sinon le premier.
 *
 * @param {{name: string, selected: boolean}[]} tabs
 * @param {string|null} stored nom mémorisé
 */
export function initialTabIndex(tabs, stored) {
  const remembered = stored === null ? -1 : tabs.findIndex((tab) => tab.name === stored);
  if (remembered !== -1) {
    return remembered;
  }
  const marked = tabs.findIndex((tab) => tab.selected);

  return marked === -1 ? 0 : marked;
}

/** Lecture du nom mémorisé ; null si rien, stockage absent ou refusé. */
export function readStoredTab(storage, key) {
  if (!key) {
    return null;
  }
  try {
    return storage?.getItem(key) ?? null;
  } catch {
    return null;
  }
}

/** Mémorisation facultative : sans effet si le stockage est absent, plein ou refusé. */
export function writeStoredTab(storage, key, name) {
  if (!key) {
    return;
  }
  try {
    storage?.setItem(key, name);
  } catch {
    // Mémorisation facultative.
  }
}
