import { createTornPaperFilter } from './tornpaper.js';

/**
 * Applique un filtre TornPaper distinct par carte planning (seed aléatoire
 * par carte, cf. tornpaper.js). Dégradation propre : si aucune carte n'est
 * présente, ne fait rien — le clip-path CSS de repli (.rb-planning-card)
 * reste alors le seul rendu du bord déchiré.
 */
export function initTornPaper(root = document, createFilter = createTornPaperFilter) {
  const cards = root.querySelectorAll('.rb-planning-card');

  cards.forEach((card, index) => {
    const filterName = createFilter({
      filterName: `tornpaper-card-${index}`,
      tornFrequency: 0.045,
      tornScale: 9,
      grungeFrequency: 0.04,
      grungeScale: 2,
    });
    card.style.filter = `url(#${filterName})`;
  });
}
