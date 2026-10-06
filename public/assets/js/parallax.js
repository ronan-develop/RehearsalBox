import { isDesktopWidth } from './viewport.js';

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
 * La couleur (rouge → jaune néon) bascule quand le logo a dépassé la barre de
 * recherche, c'est-à-dire qu'il est passé en dessous (isLogoBelowSearchBar),
 * dans les deux sens ; plus le logo descend, plus il est brillant (--wm-glow,
 * computeGlowLevel).
 *
 * Sur mobile (< 768 px, #201) il n'y a ni voyage ni montée de brillance : le logo reste dans l'en-tête, allumé en néon en
 * permanence, et défile avec la page (le calque n'est plus fixe, cf. dashboard.css). Respecte prefers-reduced-motion. Les calculs géométriques sont extraits du
 * DOM réel (fonctions pures ci-dessous) pour rester testables en
 * environnement node --test.
 */

/** Hauteur du centre du logo dans l'en-tête sur téléphone (fraction de sa hauteur depuis le haut). */
const PHONE_LOGO_HEIGHT_RATIO = 0.36;

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
 * Décalage de départ sur téléphone (#201) : le logo est CENTRÉ dans l'en-tête, dans sa partie haute (le nom du groupe et
 * l'avatar restent en bas, jamais recouverts). Position de repos du calque = centre de la page.
 */
export function computePhoneStartOffset(headerRect, bgTextRect) {
  return {
    x: headerRect.left + headerRect.width / 2 - (bgTextRect.left + bgTextRect.width / 2),
    y: headerRect.top - bgTextRect.top + headerRect.height * PHONE_LOGO_HEIGHT_RATIO - bgTextRect.height / 2,
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

/**
 * Le logo a "dépassé" la barre de recherche quand le haut de son texte est au
 * niveau du bas de la barre, ou en dessous (positions à l'écran, même repère) :
 * il est alors entièrement sous la barre. Critère purement géométrique,
 * indépendant de la taille d'écran et de la quantité de contenu, et réversible
 * (il suit la position réelle à chaque frame).
 */
export function isLogoBelowSearchBar(logo, searchRect) {
  return logo.top >= searchRect.bottom;
}

/**
 * Brillance du jaune, de 0 à 1 : 0 quand le haut du logo est au niveau du bas
 * de la barre de recherche, 1 à la position finale, linéaire entre les deux.
 * Vaut 1 si la position finale n'est pas sous la barre (rien à descendre).
 */
export function computeGlowLevel(logoTop, searchBottom, finalTop) {
  if (finalTop <= searchBottom) {
    return 1;
  }
  return Math.min(Math.max((logoTop - searchBottom) / (finalTop - searchBottom), 0), 1);
}

/** Interpolation linéaire simple entre deux valeurs. */
function lerp(from, to, progress) {
  return from + (to - from) * progress;
}

export function initParallax(root = document, windowRef = window) {
  const bg = root.querySelector('[data-parallax="bg"]');
  if (!bg) return;

  const reducedMotion = windowRef.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const isPhone = !isDesktopWidth(windowRef.innerWidth);

  const anchor = root.querySelector('[data-parallax-anchor]');
  const header = root.querySelector('.rb-dashboard-header');
  const search = root.querySelector('[data-planning-search]');

  // Calculé une seule fois, pas à chaque frame de scroll : bg est en
  // position: fixed, donc son getBoundingClientRect() reflète déjà le
  // transform appliqué au frame précédent — le recalculer en continu créait
  // une boucle de rétroaction (chaque frame se basant sur la position issue
  // du frame d'avant) qui faisait trembler le watermark de façon erratique.
  const initialScrollY = windowRef.scrollY;
  const bgRect = bg.getBoundingClientRect();
  // Mobile : le calque n'est pas fixe, il défile avec la page : son rect dépend du scroll courant, on le ramène en haut de page.
  const bgTextRectAtRest = isPhone ? {
    top: bgRect.top + initialScrollY,
    left: bgRect.left,
    width: bgRect.width,
    height: bgRect.height,
  } : bgRect;

  // header est dans le flow normal (son top varie avec le scroll),
  // contrairement à bg (fixed, top constant à l'écran) : on neutralise le
  // scrollY courant pour obtenir un rect "en haut de page", cohérent même
  // si initParallax() démarre à un scroll non nul (retour arrière
  // navigateur, ancre #...). DOMRect n'expose ses propriétés que via des
  // accesseurs du prototype : { ...rect } ne copie rien, d'où la
  // reconstruction explicite plutôt qu'un spread.
  const headerRectAtTop = header
    ? {
        top: header.getBoundingClientRect().top + initialScrollY,
        left: header.getBoundingClientRect().left,
        width: header.getBoundingClientRect().width,
        height: header.getBoundingClientRect().height,
      }
    : null;
  const start = headerRectAtTop ? computeStartOffset(headerRectAtTop, bgTextRectAtRest) : { x: 0, y: 0 };

  // Mobile : le logo est posé une fois dans le cadre de l'en-tête, allumé en permanence (la montée de brillance est propre au
  // bureau), puis défile avec la page : aucun écouteur.
  if (isPhone) {
    if (header) {
      const phoneStart = computePhoneStartOffset(headerRectAtTop, bgTextRectAtRest);
      bg.style.setProperty('--wm-x', `${phoneStart.x}px`);
      bg.style.setProperty('--wm-y', `${phoneStart.y}px`);
      bg.style.setProperty('--wm-scroll-y', '0px');
    }
    bg.classList.add('rb-page-bg-text--neon');
    bg.style.setProperty('--wm-glow', '1');
    return;
  }

  // Mouvement réduit (réglage de l'appareil, ex. « Réduire les animations » sur
  // iPhone) : pas de parallax ni de néon au scroll, mais le logo doit toujours
  // être dans le cadre du header au démarrage : on applique le décalage de
  // départ une fois, sans aucun écouteur (sinon il restait au repos CSS, en haut
  // de l'écran, hors du cadre).
  if (reducedMotion) {
    if (header) {
      bg.style.setProperty('--wm-x', `${start.x}px`);
      bg.style.setProperty('--wm-y', `${start.y}px`);
      bg.style.setProperty('--wm-scroll-y', '0px');
    }
    return;
  }

  // Mesures en coordonnées du DOCUMENT (indépendantes du scroll), prises une
  // fois puis refaites seulement au redimensionnement ou quand la taille du
  // contenu change : à chaque image de scroll, plus aucune lecture de layout
  // (getBoundingClientRect / scrollHeight), qui forçait un recalcul de layout
  // après chaque écriture de style de l'image précédente (saccades, #143).
  let maxScrollY = 0;
  let anchorDocTop = null;
  let searchDocBottom = null;

  function measure() {
    const scrollY = windowRef.scrollY;
    maxScrollY = computeMaxScrollY(root.documentElement?.scrollHeight ?? 0, windowRef.innerHeight ?? 0);
    anchorDocTop = anchor ? anchor.getBoundingClientRect().top + scrollY : null;
    searchDocBottom = search ? search.getBoundingClientRect().bottom + scrollY : null;
  }

  measure();

  let ticking = false;
  // L'ancre continue de remonter tant qu'on scrolle, même après
  // stabilisation (progress = 1) — figée au moment où progress atteint 1
  // pour la première fois, sinon le watermark suivrait indéfiniment l'ancre
  // au lieu de se stopper net (cf. retour utilisateur : "stoppé net, pas de
  // tremblement, pas de sortie d'écran").
  let frozenEnd = null;

  // N'écrit que ce qui change : pas de recalcul de style inutile quand la
  // position ne bouge pas (repos, position finale).
  const written = new Map();
  function setProp(name, value) {
    if (written.get(name) !== value) {
      written.set(name, value);
      bg.style.setProperty(name, value);
    }
  }
  let neonOn = null;
  function setNeon(on) {
    if (neonOn !== on) {
      neonOn = on;
      bg.classList.toggle('rb-page-bg-text--neon', on);
    }
  }

  function update() {
    const y = windowRef.scrollY;
    const progress = computeScrollProgress(y, maxScrollY);

    let end = start;
    if (anchor) {
      if (frozenEnd === null) {
        end = computeAnchorEndOffset({ top: anchorDocTop - y }, bgTextRectAtRest);
        if (progress === 1) frozenEnd = end;
      } else {
        end = frozenEnd;
      }
    }

    const wmY = lerp(start.y, end.y, progress);
    const scrollOffsetY = y * 0.35 * (1 - progress);
    setProp('--wm-x', `${lerp(start.x, end.x, progress)}px`);
    setProp('--wm-y', `${wmY}px`);
    // Le parallax de scroll (translateY continu) s'estompe au fur et à
    // mesure de la progression : l'interpolation --wm-x/--wm-y pilote déjà
    // le déplacement voulu, et le watermark ne doit plus bouger une fois
    // stabilisé (progress = 1).
    setProp('--wm-scroll-y', `${scrollOffsetY}px`);
    // Couleur : néon quand le logo est passé sous la barre de recherche
    // (position à l'écran calculée depuis le repos + décalages de cette frame,
    // sans relire un rect déjà transformé). Sans barre de recherche : ancien
    // critère, la progression a atteint 1.
    const logoTop = bgTextRectAtRest.top + wmY + scrollOffsetY;
    const searchBottom = search ? searchDocBottom - y : null;
    const neon = search
      ? isLogoBelowSearchBar({ top: logoTop, height: bgTextRectAtRest.height }, { bottom: searchBottom })
      : progress === 1;
    setNeon(neon);
    // Brillance du jaune : plus le logo descend (de la barre de recherche à sa
    // position finale), plus elle monte, de 0 à 1. Seule l'opacité d'une couche
    // de lueur CSS suit cette valeur (compositeur) : pas de text-shadow à
    // redessiner à chaque image, donc pas de lag au défilement.
    const finalTop = bgTextRectAtRest.top + end.y;
    const glow = neon
      ? (search ? computeGlowLevel(logoTop, searchBottom, finalTop) : 1)
      : 0;
    setProp('--wm-glow', glow.toFixed(3));
    ticking = false;
  }

  // Nouvelle mesure quand la taille de la fenêtre ou du contenu change (ex.
  // contenu chargé en XHR qui révèle une section) ; une seule fois par image.
  function remeasure() {
    measure();
    if (!ticking) {
      ticking = true;
      windowRef.requestAnimationFrame(update);
    }
  }

  // Lissage : activé seulement une fois le logo en place (deux images après le
  // premier rendu), sinon il glisserait depuis le haut de l'écran au chargement.
  // Sans lui, sur une page peu scrollable (mobile), le logo saute du header à sa
  // position finale en quelques pixels de scroll.
  windowRef.requestAnimationFrame(() => {
    windowRef.requestAnimationFrame(() => bg.classList.add('rb-page-bg-text--smooth'));
  });

  windowRef.addEventListener('resize', remeasure, { passive: true });
  if (typeof windowRef.ResizeObserver === 'function') {
    new windowRef.ResizeObserver(remeasure).observe(root.documentElement ?? root.body);
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
