/**
 * Parallax du calque "#B27" en fond de dashboard.
 *
 * Au chargement (scroll 0), le calque est décalé vers le premier tiers
 * horizontal du header et centré verticalement dans sa hauteur plutôt que
 * dans son coin haut-gauche par défaut. Cet offset (--wm-x/--wm-y) s'interpole
 * progressivement, au fur et à mesure du scroll, vers une position finale
 * centrée horizontalement sous le titre "Demandes de créneau"
 * ([data-parallax-target]) — atteinte une fois la zone planning dépassée
 * (repérée via [data-parallax-scroll-end]). Par-dessus cet offset, une
 * translation verticale classique suit le scroll (effet parallax habituel,
 * cf. --wm-scroll-y). Respecte prefers-reduced-motion.
 *
 * Le calcul géométrique est extrait du DOM réel (fonctions pures ci-dessous)
 * pour rester testable en environnement node --test.
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

/** Marge sous la cible (typiquement le titre "Demandes de créneau") avant de
 * stabiliser le watermark — le fait descendre sous les tabs Reçues/Envoyées/
 * Archivées plutôt que de coller juste sous le titre. */
const END_OFFSET_MARGIN_PX = 140;

/**
 * Décalage final (progress 1) : centré horizontalement sous le titre cible
 * (typiquement "Demandes de créneau"), relatif à la position par défaut du
 * texte. `null` si aucune cible n'est trouvée (deck vide, pas de section) —
 * l'offset final retombe alors sur {0, 0}, la position par défaut du calque.
 */
export function computeEndOffset(targetRect, bgTextRect) {
  if (!targetRect) {
    return { x: 0, y: 0 };
  }
  return {
    x: targetRect.left + targetRect.width / 2 - bgTextRect.width / 2 - bgTextRect.left,
    y: targetRect.bottom - bgTextRect.top + END_OFFSET_MARGIN_PX,
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

/** Interpolation linéaire simple entre deux valeurs. */
function lerp(from, to, progress) {
  return from + (to - from) * progress;
}

export function initParallax(root = document, windowRef = window) {
  const bg = root.querySelector('[data-parallax="bg"]');
  if (!bg) return;

  if (windowRef.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  const header = root.querySelector('.rb-dashboard-header');
  const scrollEndMarker = root.querySelector('[data-parallax-scroll-end]');
  const target = root.querySelector('[data-parallax-target]');

  let ticking = false;

  function update() {
    const y = windowRef.scrollY;
    const bgTextRect = bg.getBoundingClientRect();

    const start = header
      ? computeStartOffset(header.getBoundingClientRect(), bgTextRect)
      : { x: 0, y: 0 };

    const end = computeEndOffset(target ? target.getBoundingClientRect() : null, bgTextRect);

    const markerTopAbsolute = scrollEndMarker
      ? scrollEndMarker.getBoundingClientRect().top + y
      : null;
    const progress = computeScrollProgress(y, markerTopAbsolute);

    bg.style.setProperty('--wm-x', `${lerp(start.x, end.x, progress)}px`);
    bg.style.setProperty('--wm-y', `${lerp(start.y, end.y, progress)}px`);
    bg.style.setProperty('--wm-scroll-y', `${y * 0.35}px`);
    bg.classList.toggle('rb-page-bg-text--neon', progress === 1);
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
