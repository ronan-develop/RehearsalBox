/**
 * Logique pure de <rb-color-picker> (#383), testable sans DOM.
 *
 * Grille « teinte + nuance » : 13 teintes (la dernière, Gris, est neutre) × 7 nuances.
 */

/** Teintes : angle HSL (0-360) et saturation (0 pour le neutre). */
export const HUES = [
  { name: 'Rouge', hue: 0, saturation: 55 },
  { name: 'Orange', hue: 25, saturation: 55 },
  { name: 'Ambre', hue: 42, saturation: 55 },
  { name: 'Jaune', hue: 55, saturation: 55 },
  { name: 'Citron vert', hue: 85, saturation: 55 },
  { name: 'Vert', hue: 130, saturation: 55 },
  { name: 'Sarcelle', hue: 170, saturation: 55 },
  { name: 'Cyan', hue: 190, saturation: 55 },
  { name: 'Bleu', hue: 215, saturation: 55 },
  { name: 'Indigo', hue: 240, saturation: 55 },
  { name: 'Violet', hue: 275, saturation: 55 },
  { name: 'Rose', hue: 330, saturation: 55 },
  { name: 'Gris', hue: 0, saturation: 0 },
];

/** Nuances, de la plus claire à la plus foncée (luminosité HSL en %). */
export const TONES = [
  { name: 'très clair', lightness: 85 },
  { name: 'clair', lightness: 75 },
  { name: 'assez clair', lightness: 65 },
  { name: 'moyen', lightness: 55 },
  { name: 'assez foncé', lightness: 45 },
  { name: 'foncé', lightness: 35 },
  { name: 'très foncé', lightness: 25 },
];

/** Index de la nuance par défaut : moyen. */
export const DEFAULT_TONE = 3;

/** Saturation en dessous de laquelle une couleur est considérée comme grise (neutre). */
const NEUTRAL_SATURATION = 12;

/**
 * Index entier valide dans [0, count[ ; false sinon.
 *
 * @param {unknown} index
 * @param {number} count
 */
function isIndex(index, count) {
  return Number.isInteger(index) && index >= 0 && index < count;
}

/**
 * Couleur #rrggbb (minuscules) de la grille pour une teinte et une nuance ; null si un index est invalide.
 *
 * @param {number} hueIndex index dans HUES
 * @param {number} toneIndex index dans TONES
 */
export function colorAt(hueIndex, toneIndex) {
  if (!isIndex(hueIndex, HUES.length) || !isIndex(toneIndex, TONES.length)) {
    return null;
  }

  const { hue, saturation } = HUES[hueIndex];
  const lightness = TONES[toneIndex].lightness;
  const s = saturation / 100;
  const l = lightness / 100;

  // Conversion HSL -> RGB standard
  const c = (1 - Math.abs(2 * l - 1)) * s;
  const x = c * (1 - Math.abs(((hue / 60) % 2) - 1));
  const m = l - c / 2;

  let rgb;
  if (hue < 60) {
    rgb = [c, x, 0];
  } else if (hue < 120) {
    rgb = [x, c, 0];
  } else if (hue < 180) {
    rgb = [0, c, x];
  } else if (hue < 240) {
    rgb = [0, x, c];
  } else if (hue < 300) {
    rgb = [x, 0, c];
  } else {
    rgb = [c, 0, x];
  }

  return '#' + rgb.map((channel) => {
    const byte = Math.round((channel + m) * 255);

    return byte.toString(16).padStart(2, '0');
  }).join('');
}

/**
 * Nom de la couleur de la grille, « <Teinte> <nuance> » ; null si un index est invalide.
 *
 * @param {number} hueIndex index dans HUES
 * @param {number} toneIndex index dans TONES
 */
export function colorName(hueIndex, toneIndex) {
  if (!isIndex(hueIndex, HUES.length) || !isIndex(toneIndex, TONES.length)) {
    return null;
  }

  return `${HUES[hueIndex].name} ${TONES[toneIndex].name}`;
}

/** Couleur par défaut : Orange moyen. */
export const DEFAULT_COLOR = colorAt(1, DEFAULT_TONE);

/**
 * Couleur normalisée en minuscules si la valeur est exactement « # » suivi de 6 chiffres hexadécimaux ; null sinon.
 *
 * @param {unknown} value
 */
export function normalizeHex(value) {
  if (typeof value !== 'string' || !/^#[0-9a-fA-F]{6}$/.test(value)) {
    return null;
  }

  return value.toLowerCase();
}

/**
 * Conversion d'une couleur hexadécimale en teinte, saturation et luminosité (non arrondies) ; null si invalide.
 *
 * @param {unknown} value
 * @returns {{ h: number, s: number, l: number } | null}
 */
export function hexToHsl(value) {
  const color = normalizeHex(value);
  if (color === null) {
    return null;
  }

  const r = parseInt(color.slice(1, 3), 16) / 255;
  const g = parseInt(color.slice(3, 5), 16) / 255;
  const b = parseInt(color.slice(5, 7), 16) / 255;
  const max = Math.max(r, g, b);
  const min = Math.min(r, g, b);
  const delta = max - min;
  const l = (max + min) / 2;

  let h = 0;
  let s = 0;
  if (delta !== 0) {
    s = delta / (1 - Math.abs(2 * l - 1));
    if (max === r) {
      h = 60 * (((g - b) / delta) % 6);
    } else if (max === g) {
      h = 60 * ((b - r) / delta + 2);
    } else {
      h = 60 * ((r - g) / delta + 4);
    }
  }

  return {
    h: (h + 360) % 360,
    s: s * 100,
    l: l * 100,
  };
}

/** Distance circulaire entre deux angles (0-360). */
function hueDistance(a, b) {
  const d = Math.abs(a - b);

  return Math.min(d, 360 - d);
}

/**
 * Décrit une couleur par rapport à la grille. Null si la valeur n'est pas un hexadécimal valide.
 *
 * Si la couleur est exactement dans la grille : exact = true. Sinon, on renvoie les index les plus proches
 * (neutre si la saturation est faible, sinon teinte la plus proche sur le cercle, nuance de luminosité la plus proche).
 *
 * @param {unknown} value
 * @returns {{ value: string, hueIndex: number, toneIndex: number, name: string, exact: boolean } | null}
 */
export function describeColor(value) {
  const color = normalizeHex(value);
  if (color === null) {
    return null;
  }

  for (let hueIndex = 0; hueIndex < HUES.length; hueIndex++) {
    for (let toneIndex = 0; toneIndex < TONES.length; toneIndex++) {
      if (colorAt(hueIndex, toneIndex) === color) {
        return { value: color, hueIndex, toneIndex, name: colorName(hueIndex, toneIndex), exact: true };
      }
    }
  }

  const { h, s, l } = hexToHsl(color);

  let hueIndex = HUES.length - 1;
  if (s >= NEUTRAL_SATURATION) {
    // Teintes colorées uniquement (le neutre est le dernier index)
    let bestDistance = Infinity;
    for (let i = 0; i < HUES.length - 1; i++) {
      const distance = hueDistance(h, HUES[i].hue);
      if (distance < bestDistance) {
        bestDistance = distance;
        hueIndex = i;
      }
    }
  }

  // À égalité de luminosité, la nuance la plus claire (index plus petit) l'emporte
  let toneIndex = 0;
  let bestToneDistance = Infinity;
  for (let i = 0; i < TONES.length; i++) {
    const distance = Math.abs(l - TONES[i].lightness);
    if (distance < bestToneDistance) {
      bestToneDistance = distance;
      toneIndex = i;
    }
  }

  return {
    value: color,
    hueIndex,
    toneIndex,
    name: `proche de ${colorName(hueIndex, toneIndex)}`,
    exact: false,
  };
}

/**
 * Couleur à sélectionner pour une touche (flèches en boucle, Début, Fin) ; null pour toute autre touche.
 *
 * @param {number} current index de la couleur active
 * @param {string} key valeur de KeyboardEvent.key
 * @param {number} count nombre de couleurs
 */
export function nextColorIndex(current, key, count) {
  if (count <= 0) {
    return null;
  }
  switch (key) {
    case 'ArrowRight':
    case 'ArrowDown':
      return (current + 1) % count;
    case 'ArrowLeft':
    case 'ArrowUp':
      return (current - 1 + count) % count;
    case 'Home':
      return 0;
    case 'End':
      return count - 1;
    default:
      return null;
  }
}
