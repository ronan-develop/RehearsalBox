/*!
 * TornPaper.js v0.0.3 (adapté en module ES pour RehearsalBox)
 * Copyright(c)2024 Wakana Y.K./happy358
 * Site: https://github.com/happy358/TornPaper/
 * Released under the MIT license.
 * see https://github.com/happy358/TornPaper/blob/master/LICENSE
 *
 * Auto-hébergé (pas de CDN, CSP default-src 'self'). Génère un <filter>
 * SVG (feTurbulence + feDisplacementMap) qui découpe un bord irrégulier
 * "papier déchiré" + une texture de grain, avec un seed pour un rendu
 * différent à chaque instanciation. Dérogation assumée à la règle "zéro
 * dépendance" du projet (cf. design-plan cartes planning) : un bord
 * déchiré vraiment aléatoire nécessite un filtre SVG paramétrique, pas
 * réalisable proprement en CSS pur.
 */
export function createTornPaperFilter({
  filterName = 'filter_tornpaper',
  seed = Math.floor(1e7 * Math.random()),
  grungeFrequency = 0.03,
  grungeScale = 3,
  tornFrequency = 0.05,
  tornScale = 10,
} = {}) {
  const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  svg.style.cssText = 'position:fixed;top:0;left:0;width:0;height:0;z-index:-1;';
  svg.innerHTML = `<filter id="${filterName}"><feTurbulence type="fractalNoise" baseFrequency="${grungeFrequency}" result="paper_noise" numOctaves="10" seed="${seed}" /><feDiffuseLighting in="paper_noise" lighting-color="white" surfaceScale="${grungeScale}" result="paper"><feDistantLight azimuth="45" elevation="60" /></feDiffuseLighting><feTurbulence baseFrequency="${tornFrequency}" type="turbulence" numOctaves="10" seed="${seed}" result="edge_noise" /><feGaussianBlur stdDeviation="0.5" in="SourceGraphic" /><feMorphology operator="erode" radius="5" /><feOffset dx="-2" dy="-2" /><feDisplacementMap scale="${tornScale}" xChannelSelector="B" yChannelSelector="G" in2="edge_noise" result="edge" /><feComposite in="paper" in2="edge" operator="atop" result="result_rough" /><feComposite in="SourceGraphic" in2="edge" operator="atop" result="result_sg" /><feBlend mode="multiply" in="result_rough" in2="result_sg" /></filter>`;
  document.body.appendChild(svg);

  return filterName;
}
