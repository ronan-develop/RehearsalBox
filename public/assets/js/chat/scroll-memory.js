/**
 * Position de défilement de la liste des conversations conservée à travers la navigation (#187), par onglet
 * (sessionStorage) : en revenant d'une conversation, la liste n'est pas repartie en haut. Aucune donnée sensible : un
 * nombre de pixels. Le stockage peut être absent ou refusé : tout est protégé, la page fonctionne sans.
 */
const PREFIX = 'rb-list-scroll:';

/** @param {Storage | null} storage sessionStorage (ou équivalent) ; null = pas de stockage */
export function createScrollMemory(storage) {
  return {
    save(listName, pixels) {
      if (!Number.isFinite(pixels) || pixels < 0) {
        return;
      }
      try {
        storage?.setItem(`${PREFIX}${listName}`, String(Math.round(pixels)));
      } catch {
        // stockage plein ou refusé : on perd seulement le confort
      }
    },

    /** @returns {number | null} la position enregistrée, ou null s'il n'y en a pas (ou si elle est invalide) */
    load(listName) {
      try {
        const raw = storage?.getItem(`${PREFIX}${listName}`) ?? null;
        if (raw === null || !/^\d{1,7}$/.test(raw)) {
          return null;
        }

        return Number(raw);
      } catch {
        return null;
      }
    },
  };
}

/** sessionStorage s'il est utilisable, sinon null (l'accès lui-même peut lever une exception). */
export function sessionStorageOrNull() {
  try {
    return typeof window !== 'undefined' ? window.sessionStorage : null;
  } catch {
    return null;
  }
}
