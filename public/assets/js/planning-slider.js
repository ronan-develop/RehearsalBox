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

export function initPlanningSlider(root = document) {
  const slider = root.querySelector('[data-planning-slider]');
  const track = root.querySelector('[data-planning-track]');
  if (!slider || !track) {
    return;
  }

  const controller = createAutoScrollController(track, { step: 2 });
  const intervalId = setInterval(controller.tick, 20);

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
