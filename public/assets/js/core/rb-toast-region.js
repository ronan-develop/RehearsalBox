/**
 * <rb-toast-region> (#329) — la zone des notifications non bloquantes (Light DOM). C'est une région `aria-live` : elle doit exister
 * AVANT l'arrivée d'un message pour que les lecteurs d'écran l'annoncent, d'où sa création au chargement de la page (`ensureToastRegion`).
 * Les messages s'empilent (quatre au plus), un même message n'est pas répété, le texte passe toujours par `textContent`. Chaque
 * message peut être fermé (bouton « Fermer ») ; il s'efface seul au bout de quelques secondes, plus tard pour une erreur, et le
 * minuteur est suspendu tant que la souris ou le focus est dessus. Une erreur est annoncée tout de suite (`role="alert"`).
 */
import { createToastStack, durationFor } from './toasts.js';

// HTMLElement/customElements n'existent pas sous node --test (pas de DOM) : la classe n'est déclarée que dans un navigateur.
export let RbToastRegion;

if (typeof HTMLElement !== 'undefined') {
  RbToastRegion = class extends HTMLElement {
    #stack = createToastStack();
    /** @type {Map<number, {element: HTMLElement, timer: number|null}>} */
    #toasts = new Map();

    connectedCallback() {
      this.setAttribute('role', 'status');
      this.setAttribute('aria-live', 'polite');
      this.setAttribute('aria-label', 'Notifications');
    }

    disconnectedCallback() {
      for (const { timer } of this.#toasts.values()) {
        clearTimeout(timer);
      }
      this.#toasts.clear();
    }

    /** @param {'success'|'error'|'info'} type */
    show(message, type = 'info') {
      const { id, created, evicted } = this.#stack.push(message, type);
      evicted.forEach((oldId) => this.#dismiss(oldId));

      const entry = this.#toasts.get(id);
      if (!created && entry) {
        this.#arm(id); // même message déjà affiché : on repart pour une durée pleine
        return;
      }

      const element = document.createElement('div');
      element.className = `rb-toast rb-toast--${type}`;
      if (type === 'error') {
        element.setAttribute('role', 'alert');
      }
      const text = document.createElement('span');
      text.className = 'rb-toast-text';
      text.textContent = String(message ?? '');
      const close = document.createElement('button');
      close.type = 'button';
      close.className = 'rb-toast-close';
      close.setAttribute('aria-label', 'Fermer la notification');
      close.textContent = '×';
      close.addEventListener('click', () => this.#dismiss(id));
      element.append(text, close);
      element.addEventListener('mouseenter', () => this.#pause(id));
      element.addEventListener('mouseleave', () => this.#arm(id));
      element.addEventListener('focusin', () => this.#pause(id));
      element.addEventListener('focusout', () => this.#arm(id));

      this.#toasts.set(id, { element, timer: null });
      this.append(element);
      this.#arm(id);
    }

    #pause(id) {
      const entry = this.#toasts.get(id);
      if (entry) {
        clearTimeout(entry.timer);
        entry.timer = null;
      }
    }

    #arm(id) {
      const entry = this.#toasts.get(id);
      if (!entry) {
        return;
      }
      clearTimeout(entry.timer);
      const type = ['error', 'success'].find((t) => entry.element.classList.contains(`rb-toast--${t}`)) ?? 'info';
      entry.timer = setTimeout(() => this.#dismiss(id), durationFor(type));
    }

    #dismiss(id) {
      const entry = this.#toasts.get(id);
      if (entry) {
        clearTimeout(entry.timer);
        entry.element.remove();
        this.#toasts.delete(id);
      }
      this.#stack.remove(id);
    }
  };

  if (!customElements.get('rb-toast-region')) {
    customElements.define('rb-toast-region', RbToastRegion);
  }
}

/** Pose la région dans la page si elle n'y est pas (à appeler au chargement : une région live doit précéder ses messages). */
export function ensureToastRegion() {
  let region = document.querySelector('rb-toast-region');
  if (!region) {
    region = document.createElement('rb-toast-region');
    document.body.appendChild(region);
  }

  return region;
}
