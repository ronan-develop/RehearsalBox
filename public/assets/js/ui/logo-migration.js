/**
 * Logo « #B27 » du tableau de bord (#201) : au repos il est dans l'en-tête et défile avec la page ; en descendant, il MIGRE en
 * douceur vers la barre du haut (il rétrécit, se redresse et s'y pose, la barre apparaît), puis y reste. La migration est pilotée
 * par le scroll (progression 0 → 1), sans transition CSS : le logo suit le doigt. Sur bureau la brillance monte avec la migration ;
 * sur téléphone le logo est allumé en permanence. Mouvement réduit : pas de migration, le logo reste dans l'en-tête.
 *
 * Les calculs sont des fonctions pures (testables sans DOM) ; seul initLogoMigration touche la page : transform et opacité
 * uniquement (compositeur), aucune lecture de mise en page à chaque image.
 */
import { isDesktopWidth } from '../core/viewport.js';

/** Hauteur visuelle du logo une fois posé dans la barre (px). */
export const TOPBAR_LOGO_HEIGHT = 34;
const TOPBAR_PADDING = 16;
/** Part de la hauteur du logo située au-dessus de sa ligne de base (le reste : les coulures sous les lettres). */
export const LOGO_BASELINE_RATIO = 0.74;
/** Centre horizontal du logo au repos sur téléphone, en part de la largeur de l'en-tête (le point choisi par le propriétaire). */
const PHONE_CENTER_X_RATIO = 0.29;
/** Espace sous la ligne de base du logo, comme le padding bas de l'en-tête (l'ancienne place de l'icône photo) ; lu dans le CSS si possible (px). */
const PHONE_BASELINE_GAP = 16;
const REST_ROTATION_PHONE = -3;
const DOCKED_ROTATION = -3;
/** Progression à partir de laquelle le néon s'allume sur bureau. */
const NEON_THRESHOLD = 0.25;

const clamp = (value, min, max) => Math.min(Math.max(value, min), max);
const lerp = (from, to, progress) => from + (to - from) * progress;
const short = (value) => String(Number(value.toFixed(3)));

export function computeProgress(scrollY, distance) {
  return distance > 0 ? clamp(scrollY / distance, 0, 1) : 0;
}

/** Scroll au bout duquel le logo est dans la barre : l'en-tête est sorti de l'écran, sans dépasser le scroll possible. */
export function computeDistance(headerBottom, topbarHeight, maxScroll) {
  return Math.min(Math.max(headerBottom - topbarHeight, 1), maxScroll);
}

/**
 * Coin haut-gauche du logo au repos, en coordonnées de la PAGE (indépendantes du scroll). Sur téléphone, il est à gauche de
 * l'en-tête (centre à ~29 % de sa largeur) et sa ligne de base est $baseline, le bas du contenu de l'en-tête (là où se trouvait l'icône photo).
 */
export function computeStartPosition(headerRect, size, isPhone, rowBottom = null) {
  if (isPhone) {
    const baseline = rowBottom ?? headerRect.top + headerRect.height - PHONE_BASELINE_GAP;

    return {
      // Jamais à gauche du cadre de l'en-tête, même sur un écran très étroit.
      left: Math.max(headerRect.left, headerRect.left + headerRect.width * PHONE_CENTER_X_RATIO - size.width / 2),
      top: baseline - size.height * LOGO_BASELINE_RATIO,
    };
  }

  return {
    left: headerRect.left + headerRect.width / 3,
    top: headerRect.top + headerRect.height / 2 - size.height / 2,
  };
}

/** Coin haut-gauche (écran) et échelle du logo posé dans la barre. */
export function computeEndPosition(topbarRect, logoHeight) {
  return {
    left: topbarRect.left + TOPBAR_PADDING,
    top: topbarRect.top + (topbarRect.height - TOPBAR_LOGO_HEIGHT) / 2,
    scale: TOPBAR_LOGO_HEIGHT / logoHeight,
  };
}

/** Position à l'écran : suit la page au repos (start - scroll), converge vers la barre avec la progression. */
export function computePose({ progress, scrollY, start, end, startRotation, endRotation }) {
  return {
    x: lerp(start.left, end.left, progress),
    y: lerp(start.top - scrollY, end.top, progress),
    scale: lerp(1, end.scale, progress),
    rotate: lerp(startRotation, endRotation, progress),
  };
}

export function initLogoMigration(root = document, win = window) {
  const logo = root.querySelector('[data-logo]');
  const topbar = root.querySelector('[data-topbar]');
  const header = root.querySelector('.rb-dashboard-header');
  if (!logo || !topbar || !header) {
    return;
  }

  const isPhone = !isDesktopWidth(win.innerWidth);
  const reducedMotion = win.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const startRotation = isPhone ? REST_ROTATION_PHONE : 0;

  let start;
  let end;
  let distance = 0;

  function measure() {
    const scrollY = win.scrollY;
    const headerRect = header.getBoundingClientRect();
    const size = { width: logo.offsetWidth, height: logo.offsetHeight };
    const gap = Number.parseFloat(win.getComputedStyle?.(header)?.paddingBottom) || PHONE_BASELINE_GAP;
    start = computeStartPosition(
      { top: headerRect.top + scrollY, left: headerRect.left, width: headerRect.width, height: headerRect.height },
      size,
      isPhone,
      headerRect.top + scrollY + headerRect.height - gap,
    );
    const barRect = topbar.getBoundingClientRect();
    end = computeEndPosition(barRect, size.height);
    const maxScroll = Math.max((root.documentElement?.scrollHeight ?? 0) - (win.innerHeight ?? 0), 0);
    distance = computeDistance(headerRect.top + scrollY + headerRect.height, barRect.height, maxScroll);
  }

  // N'écrit que ce qui change : pas de recalcul de style inutile au repos ni une fois posé dans la barre.
  const written = new Map();
  function setProp(target, name, value) {
    if (written.get(target)?.get(name) !== value) {
      if (!written.has(target)) {
        written.set(target, new Map());
      }
      written.get(target).set(name, value);
      target.style.setProperty(name, value);
    }
  }
  let neonOn = null;

  function update() {
    const scrollY = win.scrollY;
    const progress = reducedMotion ? 0 : computeProgress(scrollY, distance);
    const pose = computePose({ progress, scrollY: reducedMotion ? 0 : scrollY, start, end, startRotation, endRotation: DOCKED_ROTATION });

    setProp(logo, '--wm-x', `${pose.x}px`);
    setProp(logo, '--wm-y', `${pose.y}px`);
    setProp(logo, '--wm-s', short(pose.scale));
    setProp(logo, '--wm-r', `${pose.rotate}deg`);
    const glow = isPhone ? 1 : progress;
    setProp(logo, '--wm-glow', short(glow));
    const neon = isPhone || progress >= NEON_THRESHOLD;
    if (neon !== neonOn) {
      neonOn = neon;
      logo.classList.toggle('rb-page-bg-text--neon', neon);
    }
    setProp(topbar, '--topbar-opacity', short(progress));
  }

  measure();
  // Invisible (CSS) tant qu'il n'est pas placé : sinon il resterait un instant en haut à gauche de l'écran.
  const place = () => {
    update();
    logo.classList.add('rb-page-bg-text--placed');
  };

  if (reducedMotion) {
    // Le logo défile avec la page par lui-même (position absolue) : aucune animation, aucun écouteur.
    logo.classList.add('rb-page-bg-text--static');
    place();
    return;
  }

  let ticking = false;
  const schedule = () => {
    if (!ticking) {
      ticking = true;
      win.requestAnimationFrame(() => {
        ticking = false;
        update();
      });
    }
  };
  const remeasure = () => {
    measure();
    schedule();
  };

  win.addEventListener('scroll', schedule, { passive: true });
  win.addEventListener('resize', remeasure, { passive: true });
  if (typeof win.ResizeObserver === 'function') {
    new win.ResizeObserver(remeasure).observe(root.documentElement ?? root.body);
  }
  place();
}
