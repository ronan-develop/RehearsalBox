/**
 * Brouillons de la messagerie (#187) : le texte non envoyé est gardé dans le navigateur, par UTILISATEUR et par
 * conversation, restauré à la réouverture et jamais envoyé au serveur avant l'envoi. Sécurité et confort :
 *  - durée de vie courte (DRAFT_TTL_MS) : un vieux brouillon disparaît ;
 *  - effacé à la déconnexion (clearAll) : un message privé ne reste pas lisible sur un poste partagé ;
 *  - le stockage peut être absent, plein ou refusé (navigation privée) : tout est dans un try/catch, la page fonctionne
 *    sans ; une valeur corrompue est ignorée et supprimée.
 * Le texte n'est jamais interprété : il est remis dans le champ via .value, jamais comme HTML.
 */
export const DRAFT_PREFIX = 'rb-draft:';
export const DRAFT_TTL_MS = 7 * 24 * 60 * 60 * 1000; // 7 jours

/**
 * @param {Storage | null} storage localStorage (ou un équivalent) ; null = pas de stockage
 * @param {{ userId: number | string, now?: () => number }} options
 */
export function createDraftStore(storage, { userId, now = () => Date.now() }) {
  const keyOf = (conversationKey) => `${DRAFT_PREFIX}${userId}:${conversationKey}`;
  const safely = (action, fallback) => {
    try {
      return storage ? action() : fallback;
    } catch {
      return fallback;
    }
  };
  const isDraftKey = (key) => typeof key === 'string' && key.startsWith(DRAFT_PREFIX);
  const draftKeys = () => {
    const keys = [];
    for (let index = 0; index < storage.length; index += 1) {
      const key = storage.key(index);
      if (isDraftKey(key)) {
        keys.push(key);
      }
    }

    return keys;
  };
  const read = (key) => {
    const raw = storage.getItem(key);
    if (raw === null) {
      return null;
    }
    try {
      const value = JSON.parse(raw);
      if (typeof value?.text === 'string' && Number.isFinite(value.savedAt)) {
        return value;
      }
    } catch {
      // valeur corrompue : supprimée ci-dessous
    }
    storage.removeItem(key);

    return null;
  };
  const expired = (value) => now() - value.savedAt > DRAFT_TTL_MS;

  return {
    save(conversationKey, text) {
      safely(() => {
        const key = keyOf(conversationKey);
        if (text.trim() === '') {
          storage.removeItem(key);
        } else {
          storage.setItem(key, JSON.stringify({ text, savedAt: now() }));
        }
      }, undefined);
    },

    load(conversationKey) {
      return safely(() => {
        const key = keyOf(conversationKey);
        const value = read(key);
        if (value === null) {
          return '';
        }
        if (expired(value)) {
          storage.removeItem(key);

          return '';
        }

        return value.text;
      }, '');
    },

    /** Tous les brouillons de tous les utilisateurs de ce navigateur (déconnexion). */
    clearAll() {
      safely(() => draftKeys().forEach((key) => storage.removeItem(key)), undefined);
    },

    clearExpired() {
      safely(() => draftKeys().forEach((key) => {
        const value = read(key);
        if (value !== null && expired(value)) {
          storage.removeItem(key);
        }
      }), undefined);
    },
  };
}

/** localStorage s'il est utilisable, sinon null (l'accès lui-même peut lever une exception). */
export function browserStorage() {
  try {
    return typeof window !== 'undefined' ? window.localStorage : null;
  } catch {
    return null;
  }
}

/** Déconnexion : efface tous les brouillons de ce navigateur (poste partagé). Ne lève jamais d'exception. */
export function clearAllDrafts() {
  createDraftStore(browserStorage(), { userId: 'déconnexion' }).clearAll();
}
