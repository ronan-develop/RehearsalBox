/**
 * Petit indicateur fixe en bas-droite (#82) invitant à scroller quand le
 * contenu de la page dépasse la hauteur du viewport — masqué dès que
 * l'utilisateur a commencé à scroller.
 */
export function shouldShowScrollHint({ scrollHeight, innerHeight }) {
  return scrollHeight > innerHeight;
}

/**
 * L'indicateur (fixed bas-droite) peut recouvrir visuellement les cartes du
 * slider exceptionnel selon la position de scroll — on le masque tant que
 * les deux rectangles se chevauchent (comparaison AABB classique).
 */
export function isOverlappingExceptionalSlider(hintRect, sliderRect) {
  return (
    hintRect.left < sliderRect.right &&
    hintRect.right > sliderRect.left &&
    hintRect.top < sliderRect.bottom &&
    hintRect.bottom > sliderRect.top
  );
}

export function initScrollHint(doc = document, win = window) {
  const hint = doc.querySelector('[data-scroll-hint]');
  if (!hint) {
    return;
  }

  const exceptionalSlider = doc.querySelector('[data-planning-slider-exceptional]');

  const update = () => {
    const overflowing = shouldShowScrollHint({
      scrollHeight: doc.documentElement.scrollHeight,
      innerHeight: win.innerHeight,
    });
    const hasScrolled = win.scrollY > 0;
    const overlapping = exceptionalSlider !== null
      && isOverlappingExceptionalSlider(hint.getBoundingClientRect(), exceptionalSlider.getBoundingClientRect());

    if (overflowing && !hasScrolled && !overlapping) {
      hint.classList.add('rb-scroll-hint--visible');
    } else {
      hint.classList.remove('rb-scroll-hint--visible');
    }
  };

  update();
  win.addEventListener('scroll', update, { passive: true });
  hint.addEventListener('click', () => {
    win.scrollTo({ top: doc.documentElement.scrollHeight, behavior: 'smooth' });
  });
}
