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
import { apiFetch } from './api.js';
import { initTornPaper } from './tornpaper-init.js';

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

  return attachAutoScroll(slider, track);
}

/**
 * Second slider (#81) : créneaux exceptionnels, cartes non cliquables
 * (pas de listener de contact posé dessus), défilement conditionnel via
 * shouldAutoScroll — contrairement au planning fixe qui défile toujours.
 */
const WEEKDAY_LABELS = ['Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];

const HTML_ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>"']/g, (char) => HTML_ESCAPES[char]);
}

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
      <h3 class="rb-planning-card-group">${groupName}</h3>
      <p class="rb-planning-card-weekday">${escapeHtml(weekdayLabel)}</p>
      <p class="rb-planning-card-date">${escapeHtml(occurrenceDate)}</p>
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

  const section = root.querySelector('[data-exceptional-planning-section]');
  if (!section) {
    return;
  }

  if (data.occasionalSlots.length > 0) {
    section.removeAttribute('hidden');
  } else {
    section.setAttribute('hidden', '');
  }
}

export function initExceptionalPlanningSlider(root = document, win = window) {
  const slider = root.querySelector('[data-planning-slider-exceptional]');
  const track = root.querySelector('[data-planning-track-exceptional]');
  if (!slider || !track) {
    return;
  }

  if (!shouldAutoScroll(win.innerWidth)) {
    return;
  }

  return attachAutoScroll(slider, track);
}
