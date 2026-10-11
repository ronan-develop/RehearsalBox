// Génère public/assets/img/paper-grain.png (#268) : le relief du papier du filtre TornPaper (feTurbulence + feDiffuseLighting, réglages
// d'origine : fréquence 0.04, relief 2, lumière à 45°/60°), calculé UNE fois ici puis servi comme image ; plus aucun calcul dans le navigateur.
// Tuilable (stitchTiles). Usage (Playwright présent sur le poste, rien n'est ajouté au dépôt) : node tools/paper-grain.mjs <sortie.png>
import { chromium } from 'playwright';
const out = process.argv[2] ?? 'paper-grain.png';
const size = 256;
const browser = await chromium.launch({ executablePath: process.env.CHROME });
const page = await browser.newPage({ viewport: { width: size, height: size } });
await page.setContent(`<body style="margin:0;background:#fff"><svg width="${size}" height="${size}" xmlns="http://www.w3.org/2000/svg"><filter id="g" x="0" y="0" width="100%" height="100%"><feTurbulence type="fractalNoise" baseFrequency="0.04" numOctaves="4" seed="1873204" stitchTiles="stitch" result="n"/><feDiffuseLighting in="n" lighting-color="white" surfaceScale="2"><feDistantLight azimuth="45" elevation="60"/></feDiffuseLighting></filter><rect width="${size}" height="${size}" filter="url(#g)"/></svg></body>`);
await page.screenshot({ path: out, clip: { x: 0, y: 0, width: size, height: size } });
await browser.close();
