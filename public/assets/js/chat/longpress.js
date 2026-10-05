/**
 * Appui long (#200) : un doigt posé sans bouger pendant LONG_PRESS_MS sur un message à soi, sur mobile, passe en mode
 * modification. Logique pure (minuteur injectable pour les tests) : le composant branche les évènements du doigt.
 * Un mouvement de plus de MOVE_TOLERANCE_PX (défilement, glissement) l'annule ; un tremblement léger non.
 */
export const LONG_PRESS_MS = 600;
export const MOVE_TOLERANCE_PX = 10;

export function createLongPress({ onLongPress, timers = { setTimeout: (fn, ms) => window.setTimeout(fn, ms), clearTimeout: (id) => window.clearTimeout(id) } }) {
  let timer = null;
  let origin = null;
  let consumed = false;

  const clear = () => {
    if (timer !== null) {
      timers.clearTimeout(timer);
      timer = null;
    }
  };

  return {
    start(x, y, target) {
      clear();
      consumed = false;
      origin = { x, y };
      timer = timers.setTimeout(() => {
        timer = null;
        consumed = true;
        onLongPress({ x: origin.x, y: origin.y, target });
      }, LONG_PRESS_MS);
    },

    move(x, y) {
      if (origin !== null && Math.hypot(x - origin.x, y - origin.y) > MOVE_TOLERANCE_PX) {
        clear();
      }
    },

    end() {
      clear();
    },

    cancel() {
      clear();
    },

    /** Vrai si l'appui a déclenché l'action : le relâchement qui suit ne doit pas devenir un clic. */
    consumed() {
      return consumed;
    },
  };
}
