/**
 * Petit indicateur en pied de page à droite (#82) invitant à scroller quand
 * le contenu de la page dépasse la hauteur du viewport — masqué dès que
 * l'utilisateur a commencé à scroller. Vit dans le footer fixe partagé
 * (templates/partials/nav.php), jamais superposé au contenu scrollable.
 */
export function shouldShowScrollHint({ scrollHeight, innerHeight }) {
  return scrollHeight > innerHeight;
}

export function initScrollHint(doc = document, win = window) {
  const hint = doc.querySelector('[data-scroll-hint]');
  if (!hint) {
    return;
  }

  const update = () => {
    const overflowing = shouldShowScrollHint({
      scrollHeight: doc.documentElement.scrollHeight,
      innerHeight: win.innerHeight,
    });
    const hasScrolled = win.scrollY > 0;

    if (overflowing && !hasScrolled) {
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
