/**
 * Défilement automatique du slider planning, en pause au survol (souris)
 * et sans interférer avec le scroll tactile natif sur mobile.
 *
 * Translate le track via transform (pas scrollLeft) : le conteneur visible
 * [data-planning-slider] n'est pas lui-même scrollable dans ce layout,
 * seul [data-planning-track] déborde (width: max-content). Le HTML dans
 * templates/dashboard/index.php duplique une fois la liste de cartes ; on
 * boucle donc dès la moitié de offsetWidth pour repartir pile là où la
 * copie dupliquée est visuellement identique à l'original (pas de saut).
 *
 * Logique de tick extraite de tout DOM/timer pour rester testable en
 * environnement node --test (pas de window/requestAnimationFrame).
 */
export function createAutoScrollController(track, { step = 1 } = {}) {
  let running = true;
  let offset = 0;

  function tick() {
    if (!running) {
      return;
    }

    const halfWidth = track.offsetWidth / 2;
    const next = offset + step;
    offset = next >= halfWidth ? 0 : next;
    track.style.transform = `translateX(${-offset}px)`;
  }

  return {
    tick,
    pause: () => { running = false; },
    resume: () => { running = true; },
    isRunning: () => running,
  };
}

/**
 * Génère un jeu de variables CSS (angles/positions des dégradés de
 * l'effet papier froissé, cf. .rb-planning-card-shape) pour qu'aucune
 * carte ne partage exactement le même motif visuel. `random` est injecté
 * (au lieu de Math.random directement) pour rester testable de façon
 * déterministe.
 */
export function generateWrinkleStyle(random = Math.random) {
  return {
    '--wrinkle-angle-1': `${Math.round(random() * 360)}deg`,
    '--wrinkle-angle-2': `${Math.round(random() * 360)}deg`,
    '--wrinkle-angle-3': `${Math.round(random() * 360)}deg`,
    '--wrinkle-pos-1': `${Math.round(random() * 100)}% ${Math.round(random() * 100)}%`,
    '--wrinkle-pos-2': `${Math.round(random() * 100)}% ${Math.round(random() * 100)}%`,
    '--wrinkle-pos-3': `${Math.round(random() * 100)}% ${Math.round(random() * 100)}%`,
  };
}

/**
 * Génère 0, 1 ou 2 plis courts supplémentaires (contrairement aux 3 plis
 * de generateWrinkleStyle qui traversent toute la carte, ceux-là sont
 * confinés à une zone rectangulaire via --wrinkle-extra-N-size) pour que
 * les cartes ne soient pas toutes uniformes (cf. retour utilisateur).
 * Taille "0% 0%" = pli invisible. `random` injecté pour rester testable.
 */
export function generateExtraWrinkles(random = Math.random) {
  const count = random() < 1 / 3 ? 0 : random() < 2 / 3 ? 1 : 2;

  const wrinkle = (visible) => visible
    ? {
      angle: `${Math.round(random() * 360)}deg`,
      pos: `${Math.round(random() * 100)}% ${Math.round(random() * 100)}%`,
      size: `${20 + Math.round(random() * 30)}% ${20 + Math.round(random() * 30)}%`,
    }
    : { angle: '0deg', pos: '50% 50%', size: '0% 0%' };

  const first = wrinkle(count >= 1);
  const second = wrinkle(count >= 2);

  return {
    '--wrinkle-extra-1-angle': first.angle,
    '--wrinkle-extra-1-pos': first.pos,
    '--wrinkle-extra-1-size': first.size,
    '--wrinkle-extra-2-angle': second.angle,
    '--wrinkle-extra-2-pos': second.pos,
    '--wrinkle-extra-2-size': second.size,
  };
}

const CARD_WIDTH = 220;
const CARD_HEIGHT = 180;
const CORNER_RADIUS = 22;
const JITTER_STEP_MIN = 1.5;
const JITTER_STEP_MAX = 4.5;
const JITTER_AMPLITUDE_MIN = 0.01;
const JITTER_AMPLITUDE_MAX = 2.5;

/**
 * Positions strictement croissantes le long d'un segment [0, length] en
 * coordonnées locales, espacées de JITTER_STEP_MIN à JITTER_STEP_MAX (mêmes
 * bornes que le tracé "papier déchiré" d'origine, cf. .rb-planning-card-shape).
 * Le dernier point est forcé à un pas minimal de `length` (symétrique au
 * point de départ implicite à 0) : sans cette garantie, un tirage où le pas
 * qui fait dépasser le seuil d'arrêt est grand (proche de JITTER_STEP_MAX)
 * laisse le dernier point poussé à plusieurs pixels du bord réel — assez
 * pour raccourcir la corde de l'arc de coin voisin (rayon 22) et casser
 * visuellement l'arrondi (constaté ticket #51 : coin bas-gauche coupé de
 * façon intermittente, selon le tirage aléatoire de la carte).
 */
function jitterPositions(length, random) {
  const positions = [];
  let x = 0;
  for (;;) {
    const step = JITTER_STEP_MIN + random() * (JITTER_STEP_MAX - JITTER_STEP_MIN);
    const next = Math.round((x + step) * 10) / 10;
    if (next >= length - JITTER_STEP_MIN) {
      break;
    }
    x = next;
    positions.push(x);
  }
  positions.push(Math.round((length - JITTER_STEP_MIN * random() * 0.3) * 10) / 10);
  return positions;
}

function jitterAmplitude(random) {
  return Math.round((JITTER_AMPLITUDE_MIN + random() * (JITTER_AMPLITUDE_MAX - JITTER_AMPLITUDE_MIN)) * 100) / 100;
}

/**
 * Construit la liste [x, y] (pas encore en chaîne "L x y") des points d'un
 * bord déchiré, en coordonnées locales le long de l'axe du bord (0 à
 * `length`), projetés dans le repère de la carte via `toCardPoint`. Séparer
 * le calcul local de la projection évite de refaire à la main, pour chaque
 * côté, le calcul des bornes exactes de tangence avec les arcs de coin.
 */
function buildEdgePoints(length, random, toCardPoint) {
  return jitterPositions(length, random).map((along) => toCardPoint(along, jitterAmplitude(random)));
}

/**
 * Génère un tracé clip-path: path(...) reprenant l'effet "papier déchiré"
 * sur les 4 côtés de la carte (220×180, coins arrondis rayon 22 — cf.
 * ticket #51, à l'origine la déchirure n'existait qu'entre les deux coins
 * hauts). Les 4 coins sont géométriquement identiques (même rayon) : chaque
 * bord est calculé dans un repère local commun [0, length] x [0, amplitude]
 * puis projeté sur le bord réel de la carte (translation + orientation).
 *
 * Point clé : chaque arc `A 22 22 0 0 1 x y` doit relier directement le
 * dernier point de bruit du bord précédent au premier point de bruit du
 * bord suivant — jamais un point théorique de coin intermédiaire (ex:
 * `(22, 180)`). Une première version passait par ce point théorique, ce qui
 * réduisait la corde de l'arc à moins de 2px (quasi un point), le rendant
 * visuellement invisible ; le "virage" de 90° se faisait alors sur le
 * segment L suivant, en ligne droite — d'où le coin visuellement coupé au
 * lieu d'arrondi (régression #51, constatée après un premier correctif qui
 * ne portait que sur l'espacement des points, pas sur cette structure).
 *
 * Chaque bord tire sa propre séquence indépendamment pour ne pas répéter
 * le même motif (random injecté, cf. generateWrinkleStyle plus haut).
 */
export function generateTornEdgesPath(random = Math.random) {
  const topLength = CARD_WIDTH - 2 * CORNER_RADIUS;
  const verticalLength = CARD_HEIGHT - 2 * CORNER_RADIUS;

  const top = buildEdgePoints(topLength, random,
    (along, amp) => [Math.round((CORNER_RADIUS + along) * 10) / 10, amp]);

  const right = buildEdgePoints(verticalLength, random,
    (along, amp) => [Math.round((CARD_WIDTH - amp) * 100) / 100, Math.round((CORNER_RADIUS + along) * 10) / 10]);

  const bottom = buildEdgePoints(topLength, random,
    (along, amp) => [Math.round((CARD_WIDTH - CORNER_RADIUS - along) * 10) / 10, Math.round((CARD_HEIGHT - amp) * 100) / 100]);

  const left = buildEdgePoints(verticalLength, random,
    (along, amp) => [amp, Math.round((CARD_HEIGHT - CORNER_RADIUS - along) * 10) / 10]);

  const edges = [top, right, bottom, left];
  const toLine = (points) => points.slice(1).map(([x, y]) => `L ${x} ${y}`).join(' ');

  // 4 arcs de coin au total : le premier va du point M fixe (0,22, sans
  // bruit — cf. tracé statique d'origine, qui n'a jamais de bruit sur ce
  // coin précis) vers le premier point de bruit de `top`. Les 3 arcs
  // suivants relient chaque bord au suivant (top->right->bottom->left).
  // Le tracé se referme ensuite en ligne droite (Z) du dernier point de
  // `left` vers M — comme dans l'original, cette dernière ligne reste
  // courte car ce point est proche du cercle de M, donc visuellement
  // indissociable d'un arc.
  let path = `path('M 0 22 A ${CORNER_RADIUS} ${CORNER_RADIUS} 0 0 1 ${top[0][0]} ${top[0][1]} ${toLine(top)} `;
  for (let i = 1; i < edges.length; i += 1) {
    const [x, y] = edges[i][0];
    path += `A ${CORNER_RADIUS} ${CORNER_RADIUS} 0 0 1 ${x} ${y} ${toLine(edges[i])} `;
  }
  path += `Z')`;

  return path;
}

function randomizeCardWrinkles(root) {
  root.querySelectorAll('.rb-planning-card-shape').forEach((shape) => {
    const style = { ...generateWrinkleStyle(), ...generateExtraWrinkles() };
    for (const [property, value] of Object.entries(style)) {
      shape.style.setProperty(property, value);
    }
    shape.style.clipPath = generateTornEdgesPath();
  });
}

const TAPE_POSITIONS = ['rb-planning-card-tape--corner-left', 'rb-planning-card-tape--corner-right', 'rb-planning-card-tape--top-center'];

/**
 * Choisit une des 3 positions de scotch (coin gauche/droit en diagonale,
 * ou centré vertical en haut — cf. .rb-planning-card-tape--*) à parts
 * égales. `random` injecté pour rester testable de façon déterministe.
 */
export function pickTapePosition(random = Math.random) {
  const index = Math.min(Math.floor(random() * TAPE_POSITIONS.length), TAPE_POSITIONS.length - 1);
  return TAPE_POSITIONS[index];
}

function randomizeCardTapes(root) {
  root.querySelectorAll('.rb-planning-card-tape').forEach((tape) => {
    tape.classList.add(pickTapePosition());
  });
}

/**
 * Breakpoint desktop (#83) : au-delà, le slider exceptionnel ne défile pas
 * automatiquement — règle simplifiée basée sur le viewport plutôt que sur
 * les dimensions DOM post-rendu (scrollWidth/clientWidth, cf. #81), pour ne
 * pas dépendre du layout déjà calculé.
 */
const DESKTOP_BREAKPOINT = 768;

export function shouldAutoScroll(viewportWidth) {
  return viewportWidth < DESKTOP_BREAKPOINT;
}

function attachAutoScroll(slider, track) {
  const controller = createAutoScrollController(track, { step: 1 });
  const intervalId = setInterval(controller.tick, 40);

  // Souris : pause au survol (desktop).
  slider.addEventListener('mouseenter', () => controller.pause());
  slider.addEventListener('mouseleave', () => controller.resume());

  // Tactile : pause pendant le contact pour ne pas gêner un scroll au doigt
  // en cours (cf. ticket #27) ; reprend au relâchement, pas de bouton dédié.
  slider.addEventListener('touchstart', () => controller.pause(), { passive: true });
  slider.addEventListener('touchend', () => controller.resume());
  slider.addEventListener('touchcancel', () => controller.resume());

  return { controller, intervalId };
}

export function initPlanningSlider(root = document) {
  const slider = root.querySelector('[data-planning-slider]');
  const track = root.querySelector('[data-planning-track]');
  if (!slider || !track) {
    return;
  }

  randomizeCardWrinkles(slider);
  randomizeCardTapes(slider);

  return attachAutoScroll(slider, track);
}

/**
 * Second slider (#81) : créneaux exceptionnels, cartes non cliquables
 * (pas de listener de contact posé dessus), défilement conditionnel via
 * shouldAutoScroll — contrairement au planning fixe qui défile toujours.
 */
export function initExceptionalPlanningSlider(root = document, win = window) {
  const slider = root.querySelector('[data-planning-slider-exceptional]');
  const track = root.querySelector('[data-planning-track-exceptional]');
  if (!slider || !track) {
    return;
  }

  randomizeCardWrinkles(slider);
  randomizeCardTapes(slider);

  if (!shouldAutoScroll(win.innerWidth)) {
    return;
  }

  return attachAutoScroll(slider, track);
}
