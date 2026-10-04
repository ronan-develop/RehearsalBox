/**
 * Parallax du calque "#B27" en fond de dashboard.
 *
 * RÈGLE GÉNÉRALE (valable pour tous les écrans) : au repos, le CSS centre le
 * calque horizontalement dans la page (.rb-page-bg en flex centré). Le JS
 * n'ajoute qu'un décalage par rapport à ce centre :
 *  - au chargement (scroll 0), un décalage de départ qui place le texte dans
 *    le premier tiers horizontal du header, centré verticalement dans sa
 *    hauteur ;
 *  - ce décalage tend vers 0 à mesure qu'on scrolle : la position finale est
 *    donc centrée sans qu'aucune largeur d'écran, d'ancre ou de texte ne soit
 *    mesurée pour la calculer.
 *
 * Verticalement, la position finale est calée sur une ancre dans le flow
 * normal ([data-parallax-anchor], juste au-dessus du titre "Demandes de
 * créneau", cf. templates/dashboard/index.php) : le bas du watermark
 * coïncide avec la position à l'écran de l'ancre (getBoundingClientRect().top,
 * recalculé à chaque frame de scroll), puis figée dès que la progression
 * atteint 1 pour que le watermark s'arrête net plutôt que de continuer à
 * suivre l'ancre qui remonte au fil du scroll. La progression atteint 1 au
 * seuil END_SCROLL_RATIO du scroll réel de la page (scrollHeight/innerHeight,
 * pas un seuil arbitraire en px).
 *
 * Respecte prefers-reduced-motion. Les calculs géométriques sont extraits du
 * DOM réel (fonctions pures ci-dessous) pour rester testables en
 * environnement node --test.
 */

/**
 * Décalage de départ (scroll 0), relatif à la position de repos centrée du
 * calque : amène son bord gauche au premier tiers horizontal du header, et
 * le centre verticalement dans la hauteur du header.
 */
export function computeStartOffset(headerRect, bgTextRect) {
  return {
    x: headerRect.left + headerRect.width / 3 - bgTextRect.left,
    y: headerRect.top - bgTextRect.top + headerRect.height / 2 - bgTextRect.height / 2,
  };
}

/**
 * Position finale (progress 1).
 *  - x : 0 — le CSS centre déjà le calque, aucun décalage horizontal
 *    supplémentaire (identique en mobile et en desktop, quelle que soit la
 *    largeur de l'écran, du contenu ou du texte).
 *  - y : bg étant fixed (top constant à l'écran), le delta
 *    (anchorRect.top - bgTextRect.top) donne directement le --wm-y nécessaire
 *    pour que le bas du watermark coïncide avec l'ancre actuellement affichée.
 */
export function computeAnchorEndOffset(anchorRect, bgTextRect) {
  return {
    x: 0,
    y: anchorRect.top - bgTextRect.top - bgTextRect.height,
  };
}

/** Scroll maximal atteignable par la page (document.body.scrollHeight -
 * window.innerHeight) : le watermark doit être stabilisé bien avant ce
 * point (sinon l'utilisateur ne "voit" jamais la position finale avant la
 * toute fin de page) — cf. END_SCROLL_RATIO. */
export function computeMaxScrollY(scrollHeight, innerHeight) {
  return Math.max(scrollHeight - innerHeight, 0);
}

/** Fraction du scroll total de la page à partir de laquelle le watermark
 * doit être figé à sa position finale (0.6 = dès qu'on a parcouru 60% du
 * scroll disponible) — un ratio de la page réelle plutôt qu'un seuil en px
 * arbitraire, qui s'adapte automatiquement à la quantité de contenu. */
const END_SCROLL_RATIO = 0.6;

/**
 * Progression 0→1 de scroll entre le haut de page et le seuil de fin (une
 * fraction du scroll maximal atteignable, cf. END_SCROLL_RATIO) — clampée,
 * et 0 si maxScrollY est nul : une page sans scroll possible garde le
 * watermark dans le cadre du header (position de départ), au-dessus de la
 * barre de recherche, au lieu de le projeter sur l'ancre du bas de page.
 */
export function computeScrollProgress(scrollY, maxScrollY) {
  const threshold = maxScrollY * END_SCROLL_RATIO;
  if (threshold <= 0) {
    return 0;
  }
  return Math.min(Math.max(scrollY / threshold, 0), 1);
}

/** Interpolation linéaire simple entre deux valeurs. */
function lerp(from, to, progress) {
  return from + (to - from) * progress;
}

export function initParallax(root = document, windowRef = window) {
  const bg = root.querySelector('[data-parallax="bg"]');
  if (!bg) return;

  if (windowRef.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  const anchor = root.querySelector('[data-parallax-anchor]');
  const header = root.querySelector('.rb-dashboard-header');

  // Calculé une seule fois, pas à chaque frame de scroll : bg est en
  // position: fixed, donc son getBoundingClientRect() reflète déjà le
  // transform appliqué au frame précédent — le recalculer en continu créait
  // une boucle de rétroaction (chaque frame se basant sur la position issue
  // du frame d'avant) qui faisait trembler le watermark de façon erratique.
  const initialScrollY = windowRef.scrollY;
  const bgTextRectAtRest = bg.getBoundingClientRect();

  // header est dans le flow normal (son top varie avec le scroll),
  // contrairement à bg (fixed, top constant à l'écran) : on neutralise le
  // scrollY courant pour obtenir un rect "en haut de page", cohérent même
  // si initParallax() démarre à un scroll non nul (retour arrière
  // navigateur, ancre #...). DOMRect n'expose ses propriétés que via des
  // accesseurs du prototype : { ...rect } ne copie rien, d'où la
  // reconstruction explicite plutôt qu'un spread.
  const start = header
    ? computeStartOffset(
        {
          top: header.getBoundingClientRect().top + initialScrollY,
          left: header.getBoundingClientRect().left,
          width: header.getBoundingClientRect().width,
          height: header.getBoundingClientRect().height,
        },
        bgTextRectAtRest,
      )
    : { x: 0, y: 0 };

  let ticking = false;
  // L'ancre continue de remonter tant qu'on scrolle, même après
  // stabilisation (progress = 1) — figée au moment où progress atteint 1
  // pour la première fois, sinon le watermark suivrait indéfiniment l'ancre
  // au lieu de se stopper net (cf. retour utilisateur : "stoppé net, pas de
  // tremblement, pas de sortie d'écran").
  let frozenEnd = null;

  function update() {
    const y = windowRef.scrollY;
    // Recalculé à chaque frame (pas juste au chargement) : scrollHeight
    // peut changer après coup (contenu chargé en XHR, ex. acceptation d'une
    // exception qui révèle la section planning occasionnel) et innerHeight
    // varie si l'utilisateur redimensionne/tourne son appareil.
    const maxScrollY = computeMaxScrollY(root.documentElement?.scrollHeight ?? 0, windowRef.innerHeight ?? 0);
    const progress = computeScrollProgress(y, maxScrollY);

    let end = start;
    if (anchor) {
      if (frozenEnd === null) {
        end = computeAnchorEndOffset(anchor.getBoundingClientRect(), bgTextRectAtRest);
        if (progress === 1) frozenEnd = end;
      } else {
        end = frozenEnd;
      }
    }

    bg.style.setProperty('--wm-x', `${lerp(start.x, end.x, progress)}px`);
    bg.style.setProperty('--wm-y', `${lerp(start.y, end.y, progress)}px`);
    // Le parallax de scroll (translateY continu) s'estompe au fur et à
    // mesure de la progression : l'interpolation --wm-x/--wm-y pilote déjà
    // le déplacement voulu, et le watermark ne doit plus bouger une fois
    // stabilisé (progress = 1).
    bg.style.setProperty('--wm-scroll-y', `${y * 0.35 * (1 - progress)}px`);
    bg.classList.toggle('rb-page-bg-text--neon', progress === 1);
    ticking = false;
  }

  windowRef.addEventListener(
    'scroll',
    () => {
      if (!ticking) {
        ticking = true;
        windowRef.requestAnimationFrame(update);
      }
    },
    { passive: true }
  );

  update();
}
