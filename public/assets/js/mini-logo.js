/**
 * Mini logo du tableau de bord sur mobile (#201) : une fine barre « #B27 » apparaît dès que l'en-tête (où le logo est allumé)
 * est sorti de l'écran, et disparaît quand on y revient. Un IntersectionObserver suffit : aucun écouteur de scroll.
 */
export function initMiniLogo(root = document, win = window) {
  const mini = root.querySelector('[data-mini-logo]');
  const header = root.querySelector('.rb-dashboard-header');
  if (!mini || !header || typeof win.IntersectionObserver !== 'function') {
    return;
  }

  new win.IntersectionObserver((entries) => {
    const headerVisible = entries.some((entry) => entry.isIntersecting);
    mini.classList.toggle('rb-mini-logo--visible', !headerVisible);
  }).observe(header);
}
