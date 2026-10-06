import { createTornPaperFilter } from './tornpaper.js';
import { isDesktopWidth } from './viewport.js';

const isDesktopViewport = () => isDesktopWidth(globalThis.innerWidth);

/**
 * Applique un filtre TornPaper distinct par carte planning (seed aléatoire
 * par carte, cf. tornpaper.js). Dégradation propre : si aucune carte n'est
 * présente, ne fait rien — le clip-path CSS de repli (.rb-planning-card)
 * reste alors le seul rendu du bord déchiré.
 */
export function initTornPaper(root = document, createFilter = createTornPaperFilter, isDesktop = isDesktopViewport) {
  // Sur mobile (< 768 px) le planning est une liste à plat : pas de bord déchiré (coûteux et sans objet sur une ligne).
  if (!isDesktop()) {
    return;
  }
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
