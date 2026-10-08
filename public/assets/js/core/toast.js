/** Notifications non bloquantes — jamais d'alert()/confirm() natifs (cf. plan §6/§10.2). Façade de <rb-toast-region> (#329). */
import { ensureToastRegion } from './rb-toast-region.js';

export function showToast(message, type = 'info') {
  // Sans navigateur (tests sous node --test, pas de DOM), il n'y a pas de région où afficher : on ne fait rien.
  if (typeof HTMLElement === 'undefined') {
    return;
  }
  ensureToastRegion().show(message, type);
}
