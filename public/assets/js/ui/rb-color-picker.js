/**
 * <rb-color-picker> (#120) — palette de pastilles (Light DOM) qui AMÉLIORE un champ texte `#rrggbb` rendu par le serveur :
 *
 *   <rb-color-picker><input type="text" name="colorHex" value="#b5654a" maxlength="7" pattern="#[0-9a-fA-F]{6}"></rb-color-picker>
 *
 * Sans JavaScript, le champ texte fonctionne seul (la valeur envoyée reste `#rrggbb`, le serveur la valide toujours). Avec lui, une
 * palette aux couleurs du thème s'ajoute au-dessus : motif ARIA « radiogroup » (tabindex itinérant, flèches, Début, Fin), la
 * pastille choisie est `aria-checked`. Une couleur déjà enregistrée hors palette est conservée et affichée comme pastille
 * « personnalisée ». Taper une couleur dans le champ met la palette à jour ; choisir une pastille remplit le champ.
 * Émet `color:change` { value } (remonte). La logique pure est dans color-palette.js.
 */
import { PALETTE, normalizeHex, paletteIndex, nextColorIndex } from './color-palette.js';

// HTMLElement/customElements n'existent pas sous node --test (pas de DOM) : la classe n'est déclarée que dans un navigateur.
export let RbColorPicker;

if (typeof HTMLElement !== 'undefined') {
  RbColorPicker = class extends HTMLElement {
    #input = null;
    #group = null;
    #custom = null;

    connectedCallback() {
      this.#input = this.querySelector('input');
      if (!this.#input) {
        return;
      }
      if (!this.#group) {
        this.#group = document.createElement('div');
        this.#group.className = 'rb-color-swatches';
        this.#group.setAttribute('role', 'radiogroup');
        this.#group.setAttribute('aria-label', 'Palette de couleurs');
        for (const color of PALETTE) {
          this.#group.append(this.#swatch(color, 'Couleur'));
        }
        this.insertBefore(this.#group, this.#input);
      }
      this.#group.addEventListener('click', this.#onClick);
      this.#group.addEventListener('keydown', this.#onKeydown);
      this.#input.addEventListener('input', this.#sync);
      this.#input.form?.addEventListener('reset', this.#onReset);
      this.#sync();
    }

    disconnectedCallback() {
      this.#group?.removeEventListener('click', this.#onClick);
      this.#group?.removeEventListener('keydown', this.#onKeydown);
      this.#input?.removeEventListener('input', this.#sync);
      this.#input?.form?.removeEventListener('reset', this.#onReset);
    }

    #swatch(color, label) {
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'rb-color-swatch';
      button.setAttribute('role', 'radio');
      button.setAttribute('aria-label', `${label} ${color}`);
      button.dataset.color = color;
      button.style.setProperty('--swatch', color);

      return button;
    }

    #swatches() {
      return Array.from(this.#group.querySelectorAll('.rb-color-swatch'));
    }

    /** Pastille « personnalisée » : présente seulement si le champ porte une couleur valide hors palette. */
    #syncCustom(value) {
      const needed = value !== null && paletteIndex(value) === -1;
      if (!needed) {
        this.#custom?.remove();
        this.#custom = null;

        return;
      }
      if (this.#custom === null) {
        this.#custom = this.#swatch(value, 'Couleur personnalisée');
        this.#group.append(this.#custom);
      } else if (this.#custom.dataset.color !== value) {
        this.#custom.replaceWith((this.#custom = this.#swatch(value, 'Couleur personnalisée')));
      }
    }

    #sync = () => {
      const value = normalizeHex(this.#input.value);
      this.#syncCustom(value);
      const swatches = this.#swatches();
      const checked = swatches.findIndex((swatch) => swatch.dataset.color === value);
      swatches.forEach((swatch, position) => {
        swatch.setAttribute('aria-checked', position === checked ? 'true' : 'false');
        swatch.tabIndex = position === Math.max(checked, 0) ? 0 : -1;
      });
    };

    #choose(color) {
      this.#input.value = color;
      this.#sync();
      this.dispatchEvent(new CustomEvent('color:change', { detail: { value: color }, bubbles: true }));
    }

    #onClick = (event) => {
      const swatch = event.target.closest('.rb-color-swatch');
      if (swatch) {
        this.#choose(swatch.dataset.color);
      }
    };

    #onKeydown = (event) => {
      const swatches = this.#swatches();
      const next = nextColorIndex(swatches.indexOf(event.target.closest('.rb-color-swatch')), event.key, swatches.length);
      if (next === null) {
        return;
      }
      event.preventDefault();
      this.#choose(swatches[next].dataset.color);
      swatches[next].focus();
    };

    // L'événement « reset » précède la remise à zéro des champs : on relit la valeur juste après.
    #onReset = () => {
      setTimeout(this.#sync, 0);
    };
  };

  if (!customElements.get('rb-color-picker')) {
    customElements.define('rb-color-picker', RbColorPicker);
  }
}
