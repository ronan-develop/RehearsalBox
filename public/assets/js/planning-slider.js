/**
 * Défilement automatique du slider planning, en pause au survol (souris)
 * et sans interférer avec le scroll tactile natif sur mobile.
 * Logique de tick extraite de tout DOM/timer pour rester testable en
 * environnement node --test (pas de window/requestAnimationFrame).
 */
export function createAutoScrollController(track, { step = 1 } = {}) {
  let running = true;

  function tick() {
    if (!running) {
      return;
    }

    const maxScroll = track.scrollWidth - track.clientWidth;
    const next = track.scrollLeft + step;
    track.scrollLeft = next >= maxScroll ? 0 : next;
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
  if (!slider) {
    return;
  }

  // overflow-x est porté par [data-planning-slider] (le conteneur), pas par
  // [data-planning-track] (le contenu en width:max-content) : c'est donc
  // slider.scrollLeft qui doit avancer, sinon scrollWidth === clientWidth
  // sur track et le défilement n'a visuellement aucun effet.
  const controller = createAutoScrollController(slider, { step: 2 });
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
