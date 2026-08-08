/**
 * Parallax du calque "#B27" en fond de dashboard.
 *
 * Au chargement (scroll 0), le calque est décalé vers le premier tiers
 * horizontal et centré verticalement dans le header (--rb-dashboard-header-h)
 * plutôt que dans son coin haut-gauche par défaut. Cet offset (--wm-x/--wm-y)
 * s'interpole progressivement vers 0 — sa position par défaut — au fur et à
 * mesure du scroll, jusqu'à atteindre 0 une fois la zone planning dépassée
 * (repérée via [data-parallax-scroll-end], typiquement le deck d'exceptions).
 * Par-dessus cet offset, une translation verticale classique suit le scroll
 * (effet parallax habituel, cf. --wm-scroll-y). Respecte prefers-reduced-motion.
 *
 * Le calcul géométrique (computeStartOffset/computeScrollProgress) est extrait
 * du DOM réel pour rester testable en environnement node --test.
 */

/**
 * Décalage de départ (scroll 0) : premier tiers horizontal du header, centré
 * verticalement dans sa hauteur, relatif à la position par défaut du texte
 * (padding-left: var(--rb-space-3), en haut du calque plein-page).
 */
export function computeStartOffset(headerRect, bgTextRect) {
  return {
    x: headerRect.width / 3,
    y: headerRect.top - bgTextRect.top + headerRect.height / 2 - bgTextRect.height / 2,
  };
}

/**
 * Progression 0→1 de scroll entre le haut de page et le marqueur de fin
 * (typiquement le début du deck d'exceptions, après le planning) — clampée,
 * et 1 par défaut si aucun marqueur n'est trouvé (pas d'offset à interpoler).
 */
export function computeScrollProgress(scrollY, markerTopAbsolute) {
  if (markerTopAbsolute === null || markerTopAbsolute <= 0) {
    return 1;
  }
  return Math.min(Math.max(scrollY / markerTopAbsolute, 0), 1);
}

export function initParallax(root = document, windowRef = window) {
  const bg = root.querySelector('[data-parallax="bg"]');
  if (!bg) return;

  if (windowRef.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  const header = root.querySelector('.rb-dashboard-header');
  const scrollEndMarker = root.querySelector('[data-parallax-scroll-end]');

  let ticking = false;

  function update() {
    const y = windowRef.scrollY;

    const start = header
      ? computeStartOffset(header.getBoundingClientRect(), bg.getBoundingClientRect())
      : { x: 0, y: 0 };

    const markerTopAbsolute = scrollEndMarker
      ? scrollEndMarker.getBoundingClientRect().top + y
      : null;
    const progress = computeScrollProgress(y, markerTopAbsolute);

    bg.style.setProperty('--wm-x', `${start.x * (1 - progress)}px`);
    bg.style.setProperty('--wm-y', `${start.y * (1 - progress)}px`);
    bg.style.setProperty('--wm-scroll-y', `${y * 0.35}px`);
    ticking = false;
  }

  windowRef.addEventListener(
    'scroll',
    () => {
      if (!ticking) {
        windowRef.requestAnimationFrame(update);
        ticking = true;
      }
    },
    { passive: true }
  );

  update();
}
