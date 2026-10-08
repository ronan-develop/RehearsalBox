/**
 * Logique pure de <rb-toast-region> (#329), testable sans DOM : durée d'affichage, empilement borné, répétition d'un même message.
 */

/** Durée d'affichage en millisecondes : une erreur reste plus longtemps (il faut avoir le temps de la lire). */
export function durationFor(type) {
  return type === 'error' ? 7000 : 4000;
}

/** Nombre maximal de messages empilés à l'écran : au-delà, le plus ancien s'efface. */
export const MAX_TOASTS = 4;

/**
 * Pile de messages. `push` renvoie ce qu'il faut faire : { id, created, evicted } (`created` faux quand le même message est déjà
 * affiché : on prolonge le sien au lieu d'en empiler un second, un double clic ne fait pas deux bandeaux) ; `evicted` liste les
 * identifiants à retirer pour respecter le maximum.
 */
export function createToastStack(max = MAX_TOASTS) {
  let nextId = 1;
  /** @type {{id: number, message: string, type: string}[]} */
  let items = [];

  return {
    push(message, type = 'info') {
      const text = String(message ?? '');
      const existing = items.find((item) => item.message === text && item.type === type);
      if (existing) {
        return { id: existing.id, created: false, evicted: [] };
      }

      const id = nextId++;
      items.push({ id, message: text, type });
      const evicted = items.slice(0, Math.max(0, items.length - max)).map((item) => item.id);
      items = items.slice(items.length - Math.min(items.length, max));

      return { id, created: true, evicted };
    },
    remove(id) {
      items = items.filter((item) => item.id !== id);
    },
    ids: () => items.map((item) => item.id),
  };
}
