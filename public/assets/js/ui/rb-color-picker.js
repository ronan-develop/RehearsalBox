/**
 * <rb-color-picker> (#383) — sélecteur « teinte + nuance » qui AMÉLIORE un champ texte `#rrggbb` rendu par le serveur :
 *
 *   <rb-color-picker><input type="text" name="colorHex" value="#cb824d" maxlength="7" pattern="#[0-9a-fA-F]{6}"></rb-color-picker>
 *
 * Sans JavaScript, le champ texte fonctionne seul. Avec lui, s'insèrent AVANT le champ : une rangée de teintes (motif ARIA
 * « radiogroup », tabindex itinérant, flèches, Début, Fin), un curseur de nuance, le nom de la couleur et, seulement si la couleur
 * n'est pas dans la grille, une pastille « Couleur personnalisée » cochée. La couleur existante n'est jamais modifiée tant que
 * l'utilisateur n'agit pas. Émet `color:change` { value } (remonte). La logique pure est dans color-palette.js.
 */
import { HUES, TONES, DEFAULT_TONE, colorAt, describeColor, nextColorIndex, normalizeHex } from './color-palette.js';

let sequence = 0;

// HTMLElement/customElements n'existent pas sous node --test (pas de DOM) : la classe n'est déclarée que dans un navigateur.
export let RbColorPicker;

if (typeof HTMLElement !== 'undefined') {
  RbColorPicker = class extends HTMLElement {
    #input = null;
    #hueGroup = null;
    #hueButtons = [];
    #custom = null;
    #range = null;
    #name = null;
    // Teinte la plus proche de la couleur courante (teinte cochée seulement si la couleur est dans la grille).
    #hue = 0;
    #tone = DEFAULT_TONE;
    #exact = false;

    connectedCallback() {
      this.#input = this.querySelector('input');
      if (!this.#input) {
        return;
      }
      if (!this.#hueGroup) {
        this.#build();
      }
      this.#hueGroup.addEventListener('click', this.#onHueClick);
      this.#hueGroup.addEventListener('keydown', this.#onHueKeydown);
      this.#range.addEventListener('input', this.#onTone);
      this.#input.addEventListener('input', this.#onText);
      this.#input.form?.addEventListener('reset', this.#onReset);
      this.#sync();
    }

    disconnectedCallback() {
      this.#hueGroup?.removeEventListener('click', this.#onHueClick);
      this.#hueGroup?.removeEventListener('keydown', this.#onHueKeydown);
      this.#range?.removeEventListener('input', this.#onTone);
      this.#input?.removeEventListener('input', this.#onText);
      this.#input?.form?.removeEventListener('reset', this.#onReset);
    }

    /** Crée une seule fois les éléments ajoutés avant le champ. */
    #build() {
      const id = `rb-color-range-${++sequence}`;

      this.#hueGroup = document.createElement('div');
      this.#hueGroup.className = 'rb-color-hues';
      this.#hueGroup.setAttribute('role', 'radiogroup');
      this.#hueGroup.setAttribute('aria-label', 'Teinte');
      this.#hueButtons = HUES.map((hue, index) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'rb-color-swatch';
        button.setAttribute('role', 'radio');
        button.setAttribute('aria-label', hue.name);
        button.dataset.hue = String(index);
        this.#hueGroup.append(button);

        return button;
      });

      const tone = document.createElement('div');
      tone.className = 'rb-color-tone';
      const label = document.createElement('label');
      label.htmlFor = id;
      label.textContent = 'Nuance';
      this.#range = document.createElement('input');
      this.#range.type = 'range';
      this.#range.id = id;
      this.#range.className = 'rb-color-range';
      this.#range.min = '0';
      this.#range.max = String(TONES.length - 1);
      this.#range.step = '1';
      const scale = document.createElement('div');
      scale.className = 'rb-color-scale';
      scale.setAttribute('aria-hidden', 'true');
      for (const end of [TONES[0], TONES[TONES.length - 1]]) {
        const span = document.createElement('span');
        span.textContent = end.name;
        scale.append(span);
      }
      tone.append(label, this.#range, scale);

      this.#name = document.createElement('p');
      this.#name.className = 'rb-color-name';
      this.#name.setAttribute('aria-live', 'polite');

      this.insertBefore(this.#hueGroup, this.#input);
      this.insertBefore(tone, this.#input);
      this.insertBefore(this.#name, this.#input);
    }

    /** Met la palette, le curseur et le nom en accord avec la valeur du champ (une valeur invalide ne change rien). */
    #sync() {
      const value = normalizeHex(this.#input.value);
      const desc = value === null ? null : describeColor(value);
      if (desc !== null) {
        this.#hue = desc.hueIndex;
        this.#tone = desc.toneIndex;
      }
      this.#exact = desc !== null && desc.exact;

      this.#syncCustom(desc !== null && !desc.exact ? desc.value : null);
      this.#range.value = String(this.#tone);
      this.#range.setAttribute('aria-valuetext', desc === null ? '' : desc.name);
      this.#name.textContent = desc === null ? '' : `${desc.name} (${desc.value})`;

      this.#hueButtons.forEach((button, index) => {
        const colour = colorAt(index, this.#tone);
        if (colour !== null) {
          button.style.setProperty('--swatch', colour);
        }
        const checked = this.#exact && index === this.#hue;
        button.setAttribute('aria-checked', String(checked));
        button.tabIndex = checked || (!this.#exact && this.#custom === null && index === 0) ? 0 : -1;
      });
    }

    /** Pastille « Couleur personnalisée » : présente seulement si le champ porte une couleur valide hors grille. */
    #syncCustom(value) {
      if (value === null) {
        this.#custom?.remove();
        this.#custom = null;

        return;
      }
      if (this.#custom === null) {
        this.#custom = document.createElement('button');
        this.#custom.type = 'button';
        this.#custom.className = 'rb-color-swatch';
        this.#custom.setAttribute('role', 'radio');
        this.#custom.setAttribute('aria-checked', 'true');
        this.#hueGroup.append(this.#custom);
      }
      this.#custom.setAttribute('aria-label', `Couleur personnalisée ${value}`);
      this.#custom.style.setProperty('--swatch', value);
      this.#custom.tabIndex = 0;
    }

    /** Remplit le champ avec la couleur choisie, resynchronise et émet l'évènement. `value` vient de colorAt ou de normalizeHex. */
    #apply(value) {
      this.#input.value = value;
      this.#sync();
      this.dispatchEvent(new CustomEvent('color:change', { detail: { value }, bubbles: true }));
    }

    #chooseHue(index) {
      const colour = colorAt(index, this.#tone);
      if (colour !== null) {
        this.#apply(colour);
      }
    }

    #onHueClick = (event) => {
      const button = event.target.closest('[data-hue]');
      if (button) {
        this.#chooseHue(Number(button.dataset.hue));
      }
    };

    #onHueKeydown = (event) => {
      const next = nextColorIndex(this.#hueButtons.indexOf(event.target), event.key, this.#hueButtons.length);
      if (next === null) {
        return;
      }
      event.preventDefault();
      this.#chooseHue(next);
      this.#hueButtons[next].focus();
    };

    /** Curseur : même teinte (la plus proche si la couleur est hors grille), nouvelle nuance. */
    #onTone = () => {
      const colour = colorAt(this.#hue, Number(this.#range.value));
      if (colour !== null) {
        this.#apply(colour);
      }
    };

    /** Saisie : seulement une valeur valide ; le champ n'est pas réécrit pendant la frappe. */
    #onText = () => {
      const value = normalizeHex(this.#input.value);
      if (value === null) {
        return;
      }
      this.#sync();
      this.dispatchEvent(new CustomEvent('color:change', { detail: { value }, bubbles: true }));
    };

    // L'événement « reset » précède la remise à zéro des champs : on relit la valeur juste après.
    #onReset = () => {
      setTimeout(() => this.#sync(), 0);
    };
  };

  if (!customElements.get('rb-color-picker')) {
    customElements.define('rb-color-picker', RbColorPicker);
  }
}
