/** Parallax léger du calque "#B27" en fond de dashboard, respecte prefers-reduced-motion. */
export function initParallax(root = document, windowRef = window) {
  const bg = root.querySelector('[data-parallax="bg"]');
  if (!bg) return;

  if (windowRef.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

  let ticking = false;

  function update() {
    const y = windowRef.scrollY;
    bg.style.transform = `translateY(${y * 0.35}px)`;
    ticking = false;
  }

  windowRef.addEventListener(
    'scroll',
    () => {
      if (!ticking) {
        windowRef.requestAnimationFrame(update);
        ticking = true;
      }
    },
    { passive: true }
  );

  update();
}
