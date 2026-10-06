/**
 * Défilement automatique du slider planning, en pause au survol (souris)
 * et sans interférer avec le scroll tactile natif sur mobile.
 *
 * Le défilement est une animation CSS sur le track (transform, compositeur) :
 * le conteneur visible [data-planning-slider] n'est pas lui-même scrollable
 * dans ce layout, seul [data-planning-track] déborde (width: max-content).
 * Il ne tourne que sur bureau (≥ 768 px) : sur mobile le planning est une liste
 * (#201). La copie des cartes qui permet de boucler est AJOUTÉE ICI, seulement
 * quand l'animation tourne (jamais dans le HTML : pas de cartes en double hors
 * boucle) ; l'animation va de 0 à -50 % de la largeur du track et boucle donc
 * pile là où la copie est visuellement identique à l'original (pas de saut).
 * Ce module ne fait que la copie, la durée du tour et les pauses.
 *
 * Logique extraite de tout DOM/timer pour rester testable en environnement
 * node --test.
 */
import { apiFetch } from './api.js';
import { initTornPaper } from './tornpaper-init.js';
import { escapeHtml } from './html.js';
import { isDesktopWidth } from './viewport.js';

/** Vitesse historique : 1 px toutes les 40 ms. */
const DEFAULT_SPEED_PX_PER_SECOND = 25;

/**
 * Contrôleur d'auto-défilement. Le défilement lui-même est une animation CSS
 * (transform, compositeur : aucun JS par image, donc rien ne se dispute le
 * processeur avec le scroll de la page sur mobile, #149). Ce contrôleur ne fait
 * que (1) calculer la durée d'un tour d'après la largeur de la piste (mesurée
 * une fois), et (2) gérer plusieurs raisons de pause à la fois (survol,
 * toucher, hors écran, onglet caché) : il ne repart que lorsqu'elles sont
 * toutes levées, et ne signale (onChange) que les vrais changements d'état.
 */
export function createAutoScrollController(track, { speed = DEFAULT_SPEED_PX_PER_SECOND, onChange = () => {} } = {}) {
  const pauseReasons = new Set();

  function setReason(reason, active) {
    const wasRunning = pauseReasons.size === 0;
    if (active) {
      pauseReasons.add(reason);
    } else {
      pauseReasons.delete(reason);
    }
    const isRunning = pauseReasons.size === 0;
    if (isRunning !== wasRunning) {
      onChange(isRunning);
    }
  }

  /**
   * La piste contient le contenu dupliqué une fois : on boucle à la moitié de
   * sa largeur (translate -50 %), là où la copie est visuellement identique à
   * l'original. Retourne la durée d'un tour en secondes, ou null si la piste
   * n'a pas de largeur mesurable (pas d'animation à durée nulle).
   */
  function measure() {
    const halfWidth = track.offsetWidth / 2;
    if (!(halfWidth > 0)) {
      return null;
    }

    const duration = halfWidth / speed;
    track.style.setProperty('--rb-planning-duration', `${duration}s`);

    return duration;
  }

  return {
    measure,
    pause: (reason = 'user') => setReason(reason, true),
    resume: (reason = 'user') => setReason(reason, false),
    isRunning: () => pauseReasons.size === 0,
  };
}

/**
 * Breakpoint desktop (#83, #201) : en dessous, le planning est une liste à onglets, pas un carrousel — règle basée sur le
 * viewport (comme le CSS), pas sur les dimensions DOM post-rendu.
 */
export function shouldAutoScroll(viewportWidth) {
  return isDesktopWidth(viewportWidth);
}

/**
 * Ajoute à la fin de la piste UNE copie des cartes pour boucler sans saut : masquée aux lecteurs d'écran, inerte (ni focus ni
 * clic) et sans effet de mise en page (display: contents, cf. CSS). Appelée seulement quand l'animation démarre.
 */
export function appendLoopCopy(track) {
  const doc = track.ownerDocument;
  if (!doc) {
    return;
  }
  const copy = doc.createElement('div');
  copy.className = 'rb-planning-loop-copy';
  copy.setAttribute('aria-hidden', 'true');
  copy.setAttribute('inert', '');
  copy.append(...Array.from(track.children).map((child) => child.cloneNode(true)));
  track.append(copy);
}

function attachAutoScroll(slider, track, win, root) {
  // Mouvement réduit : pas d'auto-défilement, le balayage natif reste possible.
  if (win.matchMedia('(prefers-reduced-motion: reduce)').matches) {
    return;
  }

  const controller = createAutoScrollController(track, {
    onChange: (running) => track.classList.toggle('rb-planning-track--paused', !running),
  });

  // La copie double la largeur de la piste : la durée du tour est mesurée APRÈS son ajout (moitié de la largeur totale).
  appendLoopCopy(track);
  if (controller.measure() === null) {
    return;
  }
  track.classList.add('rb-planning-track--auto');

  // Souris : pause au survol (desktop).
  slider.addEventListener('mouseenter', () => controller.pause('hover'));
  slider.addEventListener('mouseleave', () => controller.resume('hover'));

  // Tactile : pause pendant le contact pour ne pas gêner un scroll au doigt
  // en cours (cf. ticket #27) ; reprend au relâchement, pas de bouton dédié.
  slider.addEventListener('touchstart', () => controller.pause('touch'), { passive: true });
  slider.addEventListener('touchend', () => controller.resume('touch'));
  slider.addEventListener('touchcancel', () => controller.resume('touch'));

  // Hors écran ou onglet caché : l'animation est en pause (pas de rendu inutile).
  if (typeof win.IntersectionObserver === 'function') {
    new win.IntersectionObserver((entries) => {
      const visible = entries.some((entry) => entry.isIntersecting);
      if (visible) {
        controller.resume('offscreen');
      } else {
        controller.pause('offscreen');
      }
    }).observe(slider);
  }

  root.addEventListener('visibilitychange', () => {
    if (root.visibilityState === 'hidden') {
      controller.pause('hidden');
    } else {
      controller.resume('hidden');
    }
  });

  // La largeur de la piste n'est mesurée qu'une fois : on la remesure si la fenêtre change.
  win.addEventListener('resize', () => controller.measure(), { passive: true });

  return { controller };
}

export function initPlanningSlider(root = document, win = window) {
  const slider = root.querySelector('[data-planning-slider]');
  const track = root.querySelector('[data-planning-track]');
  if (!slider || !track || !shouldAutoScroll(win.innerWidth)) {
    return;
  }

  return attachAutoScroll(slider, track, win, root);
}


const WEEKDAY_LABELS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

function formatTime(time) {
  return time.slice(0, 5);
}

function formatOccurrenceDate(isoDate) {
  const [year, month, day] = isoDate.split('-');
  return `${day}/${month}/${year}`;
}

/**
 * Miroir JS de $renderExceptionalCard (templates/dashboard/index.php) —
 * utilisé par refreshExceptionalPlanning() pour reconstruire le slider de
 * créneaux exceptionnels sans reload après acceptation d'une demande (#79).
 * Cartes non cliquables (#81) : pas de role/tabindex/data-contact-group-*.
 */
export function buildExceptionalCardMarkup(requestableSlot) {
  const groupName = escapeHtml(requestableSlot.groupName);
  const weekdayLabel = WEEKDAY_LABELS[requestableSlot.weekday];
  const occurrenceDate = requestableSlot.occurrenceDate ? formatOccurrenceDate(requestableSlot.occurrenceDate) : '';

  return `
    <article class="rb-planning-card rb-planning-card--exceptional">
      <span class="rb-badge" aria-hidden="true">Occasionnel</span>
      <h4 class="rb-planning-card-group">${groupName}</h4>
      <p class="rb-planning-card-when"><span class="rb-planning-card-weekday">${escapeHtml(weekdayLabel)}</span> <span class="rb-planning-card-date">${escapeHtml(occurrenceDate)}</span></p>
      <p class="rb-planning-card-time">${escapeHtml(formatTime(requestableSlot.startTime))} – ${escapeHtml(formatTime(requestableSlot.endTime))}</p>
    </article>
  `;
}

/**
 * Recharge uniquement le slider de créneaux exceptionnels depuis
 * /api/planning après une acceptation réussie (availability.js::handleRespond)
 * — le planning fixe n'est pas affecté par une acceptation, pas besoin de
 * le reconstruire (et data-current-user-group-role n'est pas exposé par
 * l'API, donc pas reconstructible fidèlement côté client).
 */
export async function refreshExceptionalPlanning(root = document) {
  const track = root.querySelector('[data-planning-track-exceptional]');
  if (!track) {
    return;
  }

  const data = await apiFetch('/api/planning');
  track.innerHTML = data.occasionalSlots.map(buildExceptionalCardMarkup).join('');
  initTornPaper(track);

  const count = root.querySelector('[data-planning-tab-count]');
  if (count) {
    count.textContent = String(data.occasionalSlots.length);
  }

  // Vide : masquée sur bureau (CSS), message « aucun créneau » sur mobile.
  root.querySelector('[data-exceptional-planning-section]')?.classList.toggle('rb-planning-section--empty', data.occasionalSlots.length === 0);
}

