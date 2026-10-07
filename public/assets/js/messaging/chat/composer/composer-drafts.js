/**
 * Brouillon de la saisie (#187) : restauré à l'ouverture, réécrit à chaque saisie après un court délai, écrit tout de suite
 * quand on quitte la page. Extrait de <rb-composer> (une responsabilité : synchroniser le champ avec le magasin de brouillons,
 * cf. drafts.js). Pendant la correction d'un message déjà envoyé (`isSuspended`), rien n'est jamais enregistré.
 */
export const DRAFT_SAVE_DELAY_MS = 300;

export class ComposerDrafts {
  #field;
  #isSuspended;
  #delayMs;
  #timers;
  #store = null;
  #key = '';
  #timer = null;

  /** @param {{ field: { value: string }, isSuspended: () => boolean, delayMs?: number, timers?: { setTimeout: Function, clearTimeout: Function } }} options */
  constructor({ field, isSuspended, delayMs = DRAFT_SAVE_DELAY_MS, timers = globalThis }) {
    this.#field = field;
    this.#isSuspended = isSuspended;
    this.#delayMs = delayMs;
    this.#timers = timers;
  }

  /**
   * `store` : createDraftStore(...) ; `key` : une clé par conversation (ou par brouillon de page de démarrage) ;
   * `onRestore` : appelé quand le champ a reçu un brouillon (ex. pour réajuster sa hauteur).
   */
  configure(store, key, onRestore = () => {}) {
    this.#store = store;
    this.#key = key;
    store.clearExpired();
    const saved = store.load(key);
    if (saved !== '' && this.#field.value.trim() === '') {
      this.#field.value = saved;
      onRestore();
    }
  }

  schedule() {
    if (this.#isSuspended()) {
      return;
    }
    this.#cancel();
    this.#timer = this.#timers.setTimeout(() => this.flush(), this.#delayMs);
  }

  flush() {
    if (this.#isSuspended()) {
      return; // le texte du champ est celui d'un message déjà envoyé : jamais enregistré comme brouillon
    }
    this.#cancel();
    this.#store?.save(this.#key, this.#field.value ?? '');
  }

  #cancel() {
    if (this.#timer !== null) {
      this.#timers.clearTimeout(this.#timer);
      this.#timer = null;
    }
  }
}
