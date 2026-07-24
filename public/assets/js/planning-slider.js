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
  const track = root.querySelector('[data-planning-track]');
  if (!slider || !track) {
    return;
  }

  const controller = createAutoScrollController(track, { step: 1 });
  const intervalId = setInterval(controller.tick, 30);

  slider.addEventListener('mouseenter', () => controller.pause());
  slider.addEventListener('mouseleave', () => controller.resume());

  return { controller, intervalId };
}
