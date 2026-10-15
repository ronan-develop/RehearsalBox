// Génère public/favicon.ico (#470) à partir du logo « #B27 » du site (public/assets/img/mail-logo-b27.png) : le logo est recadré au plus
// près, posé sur un carré arrondi au fond du site (--rb-bg), en 16, 32 et 48 px (images PNG dans un conteneur ICO). Le halo rougeâtre
// de l'image (alpha faible) est retiré avant le recadrage, sinon il dessine un rectangle visible. Le fichier est servi tel quel par le
// serveur web (jamais par PHP). Usage (Playwright présent sur le poste, rien n'est ajouté au dépôt) :
//   CHROME=<binaire chromium> node tools/favicon.mjs [sortie.ico]
import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const out = process.argv[2] ?? path.join(root, 'public', 'favicon.ico');
const source = fs.readFileSync(path.join(root, 'public/assets/img/mail-logo-b27.png')).toString('base64');
const SIZES = [16, 32, 48];

const browser = await chromium.launch({ executablePath: process.env.CHROME });
const page = await browser.newPage();
await page.setContent(`<img id="i" src="data:image/png;base64,${source}">`);
const dataUrls = await page.evaluate(async (sizes) => {
  const img = document.getElementById('i');
  await img.decode();
  const logo = document.createElement('canvas');
  logo.width = img.naturalWidth;
  logo.height = img.naturalHeight;
  const ctx = logo.getContext('2d');
  ctx.drawImage(img, 0, 0);
  const pixels = ctx.getImageData(0, 0, logo.width, logo.height);
  let minX = logo.width, minY = logo.height, maxX = 0, maxY = 0;
  for (let y = 0; y < logo.height; y++) {
    for (let x = 0; x < logo.width; x++) {
      if (pixels.data[(y * logo.width + x) * 4 + 3] > 120) {
        minX = Math.min(minX, x); maxX = Math.max(maxX, x); minY = Math.min(minY, y); maxY = Math.max(maxY, y);
      }
    }
  }
  for (let i = 3; i < pixels.data.length; i += 4) {
    if (pixels.data[i] < 110) pixels.data[i] = 0; // halo
  }
  ctx.putImageData(pixels, 0, 0);
  const boxW = maxX - minX + 1, boxH = maxY - minY + 1;

  return sizes.map((size) => {
    const scale = 8; // dessiné en grand puis réduit : bords nets à 16 px
    const big = document.createElement('canvas');
    big.width = big.height = size * scale;
    const g = big.getContext('2d');
    g.fillStyle = '#15151a'; // --rb-bg
    g.beginPath();
    g.roundRect(0, 0, size * scale, size * scale, size * scale * 0.2);
    g.fill();
    const w = size * scale * 0.92, h = (w * boxH) / boxW;
    g.imageSmoothingQuality = 'high';
    g.drawImage(logo, minX, minY, boxW, boxH, (size * scale - w) / 2, (size * scale - h) / 2, w, h);
    const small = document.createElement('canvas');
    small.width = small.height = size;
    const s = small.getContext('2d');
    s.imageSmoothingQuality = 'high';
    s.drawImage(big, 0, 0, size, size);
    return small.toDataURL('image/png');
  });
}, SIZES);
await browser.close();

const pngs = dataUrls.map((url) => Buffer.from(url.split(',')[1], 'base64'));
const header = Buffer.alloc(6);
header.writeUInt16LE(1, 2); // type : icône
header.writeUInt16LE(pngs.length, 4);
let offset = 6 + 16 * pngs.length;
const entries = pngs.map((png, i) => {
  const entry = Buffer.alloc(16);
  entry.writeUInt8(SIZES[i], 0);
  entry.writeUInt8(SIZES[i], 1);
  entry.writeUInt16LE(1, 4); // plans
  entry.writeUInt16LE(32, 6); // bits par pixel
  entry.writeUInt32LE(png.length, 8);
  entry.writeUInt32LE(offset, 12);
  offset += png.length;
  return entry;
});
fs.writeFileSync(out, Buffer.concat([header, ...entries, ...pngs]));
console.log(`${out} : ${offset} octets, ${SIZES.join('/')} px`);
