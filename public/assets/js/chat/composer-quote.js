/**
 * Aperçu de la citation au-dessus de la saisie (#214) : auteur et début du texte cité, avec une croix pour la retirer. Le
 * balisage vient du gabarit PHP (masqué par défaut) ; ici seulement textContent, jamais de HTML construit à partir du texte.
 * Seul l'identifiant du message cité part avec l'envoi.
 */
import { quoteExcerpt } from './quote.js';

export class ComposerQuote {
  #banner;
  #author;
  #text;
  #current = null;

  /** @param {Element | null} banner élément `[data-composer-quote]` du gabarit (null : citation indisponible, tout est ignoré) */
  constructor(banner, onCancel) {
    this.#banner = banner;
    this.#author = banner?.querySelector('[data-composer-quote-author]') ?? null;
    this.#text = banner?.querySelector('[data-composer-quote-text]') ?? null;
    banner?.querySelector('[data-composer-quote-cancel]')?.addEventListener('click', onCancel);
  }

  /** @param {{ id: string, author: string, text: string }} quote */
  show(quote) {
    if (this.#banner === null) {
      return;
    }
    this.#current = quote;
    this.#author.textContent = quote.author;
    this.#text.textContent = quoteExcerpt(quote.text);
    this.#banner.hidden = false;
  }

  clear() {
    this.#current = null;
    if (this.#banner !== null) {
      this.#banner.hidden = true;
      this.#author.textContent = '';
      this.#text.textContent = '';
    }
  }

  get active() {
    return this.#current !== null;
  }

  /** Identifiant du message cité (nombre), null s'il n'y en a pas. */
  get id() {
    return this.#current === null ? null : Number(this.#current.id);
  }

  /** La citation courante, pour la remettre si l'envoi échoue. */
  get snapshot() {
    return this.#current;
  }
}
