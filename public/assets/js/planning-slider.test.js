import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createAutoScrollController, generateWrinkleStyle, generateExtraWrinkles, pickTapePosition, generateTornEdgesPath, shouldAutoScroll, buildExceptionalCardMarkup, refreshExceptionalPlanning } from './planning-slider.js';

function makeFakeTrack(offsetWidth = 1000) {
  let translateX = 0;
  return {
    offsetWidth,
    style: {
      set transform(value) {
        const match = /translateX\((-?\d+(?:\.\d+)?)px\)/.exec(value);
        translateX = match ? Number(match[1]) : 0;
      },
      get transform() {
        return `translateX(${translateX}px)`;
      },
    },
    get translateX() {
      return translateX;
    },
  };
}

test('createAutoScrollController translates the track further left on each tick while running', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  controller.tick();
  controller.tick();

  assert.equal(track.translateX, -4);
});

test('createAutoScrollController does not move the track while paused', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  controller.pause();
  controller.tick();
  controller.tick();

  assert.equal(track.translateX, 0);
});

test('createAutoScrollController resumes advancing after resume() following a pause()', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  controller.pause();
  controller.tick();
  controller.resume();
  controller.tick();

  assert.equal(track.translateX, -2);
});

test('createAutoScrollController wraps back to 0 once past half the track width (duplicated content)', () => {
  // Le track contient le contenu dupliqué une fois (2x offsetWidth réel de la
  // liste source) pour boucler sans saut visible ; on doit donc revenir à 0
  // dès qu'on a défilé la moitié de offsetWidth, pas la totalité.
  const track = makeFakeTrack(1000);
  const controller = createAutoScrollController(track, { step: 2 });

  for (let i = 0; i < 250; i += 1) {
    controller.tick();
  }

  assert.equal(track.translateX, 0);
});

test('createAutoScrollController starts running by default', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  assert.equal(controller.isRunning(), true);
});

test('createAutoScrollController pause() is idempotent under concurrent mouseenter/manual pause', () => {
  const track = makeFakeTrack();
  const controller = createAutoScrollController(track, { step: 2 });

  controller.pause();
  controller.pause();
  controller.tick();

  assert.equal(track.translateX, 0);
  assert.equal(controller.isRunning(), false);
});

test('generateWrinkleStyle returns 3 angles and 3 positions', () => {
  const style = generateWrinkleStyle(() => 0.5);

  assert.equal(Object.keys(style).length, 6);
  assert.ok('--wrinkle-angle-1' in style);
  assert.ok('--wrinkle-angle-2' in style);
  assert.ok('--wrinkle-angle-3' in style);
  assert.ok('--wrinkle-pos-1' in style);
  assert.ok('--wrinkle-pos-2' in style);
  assert.ok('--wrinkle-pos-3' in style);
});

test('generateWrinkleStyle produces angles in degrees within 0-360', () => {
  const style = generateWrinkleStyle(() => 0.9);

  for (const key of ['--wrinkle-angle-1', '--wrinkle-angle-2', '--wrinkle-angle-3']) {
    const value = Number(style[key].replace('deg', ''));
    assert.ok(value >= 0 && value <= 360, `${key}=${style[key]} out of range`);
  }
});

test('generateWrinkleStyle produces percentage positions within 0-100', () => {
  const style = generateWrinkleStyle(() => 0.1);

  for (const key of ['--wrinkle-pos-1', '--wrinkle-pos-2', '--wrinkle-pos-3']) {
    const [x, y] = style[key].split(' ').map((v) => Number(v.replace('%', '')));
    assert.ok(x >= 0 && x <= 100, `${key} x=${x} out of range`);
    assert.ok(y >= 0 && y <= 100, `${key} y=${y} out of range`);
  }
});

test('generateWrinkleStyle differs between two different random sources', () => {
  let call = 0;
  const seqA = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6];
  const seqB = [0.9, 0.8, 0.7, 0.6, 0.5, 0.4];

  const styleA = generateWrinkleStyle(() => seqA[call++]);
  call = 0;
  const styleB = generateWrinkleStyle(() => seqB[call++]);

  assert.notDeepEqual(styleA, styleB);
});

test('pickTapePosition returns one of the 3 known variants', () => {
  const variants = ['rb-planning-card-tape--corner-left', 'rb-planning-card-tape--corner-right', 'rb-planning-card-tape--top-center'];

  for (const value of [0, 0.34, 0.67, 0.99]) {
    assert.ok(variants.includes(pickTapePosition(() => value)));
  }
});

test('pickTapePosition splits the 0-1 range evenly across the 3 variants', () => {
  assert.equal(pickTapePosition(() => 0), 'rb-planning-card-tape--corner-left');
  assert.equal(pickTapePosition(() => 0.5), 'rb-planning-card-tape--corner-right');
  assert.equal(pickTapePosition(() => 0.9), 'rb-planning-card-tape--top-center');
});

test('generateExtraWrinkles returns styles for both extra wrinkles with a size for each', () => {
  const style = generateExtraWrinkles(() => 0.5);

  assert.ok('--wrinkle-extra-1-size' in style);
  assert.ok('--wrinkle-extra-2-size' in style);
  assert.ok('--wrinkle-extra-1-angle' in style);
  assert.ok('--wrinkle-extra-2-angle' in style);
  assert.ok('--wrinkle-extra-1-pos' in style);
  assert.ok('--wrinkle-extra-2-pos' in style);
});

test('generateExtraWrinkles can produce zero, one, or two visible wrinkles depending on random', () => {
  // count() dérive du nombre de tailles non "0% 0%" — pas d'uniformité,
  // certaines cartes n'ont aucun pli court supplémentaire (cf. retour
  // utilisateur : "une ou deux pliures supplémentaires").
  const countVisible = (style) => [style['--wrinkle-extra-1-size'], style['--wrinkle-extra-2-size']]
    .filter((size) => size !== '0% 0%').length;

  const zero = generateExtraWrinkles(() => 0.05);
  const one = generateExtraWrinkles(() => 0.5);
  const two = generateExtraWrinkles(() => 0.95);

  assert.equal(countVisible(zero), 0);
  assert.equal(countVisible(one), 1);
  assert.equal(countVisible(two), 2);
});

test('generateExtraWrinkles produces a size small enough to stay partial (not full card)', () => {
  const style = generateExtraWrinkles(() => 0.99);

  for (const key of ['--wrinkle-extra-1-size', '--wrinkle-extra-2-size']) {
    const [w, h] = style[key].split(' ').map((v) => Number(v.replace('%', '')));
    assert.ok(w > 0 && w <= 60, `${key} width=${w} should be a partial size`);
    assert.ok(h > 0 && h <= 60, `${key} height=${h} should be a partial size`);
  }
});

test('generateTornEdgesPath returns a CSS path() starting from the top-left corner point (no noise before the very first corner, like the original static trace)', () => {
  const path = generateTornEdgesPath(() => 0.5);

  assert.match(path, /^path\('M 0 22 A 22 22 0 0 1 [\d.]+ [\d.]+ /);
});

test('generateTornEdgesPath closes the shape and preserves the 4 rounded corner arcs (radius 22)', () => {
  const path = generateTornEdgesPath(() => 0.5);

  const arcs = path.match(/A 22 22 0 0 1/g) || [];
  assert.equal(arcs.length, 4);
  assert.match(path, /Z'\)$/);
});

test('generateTornEdgesPath keeps every jittered point within the 220x180 card bounds', () => {
  const path = generateTornEdgesPath(() => 0.5);
  const coords = [...path.matchAll(/L ([\d.]+) ([\d.]+)/g)].map(([, x, y]) => [Number(x), Number(y)]);

  assert.ok(coords.length > 0);
  for (const [x, y] of coords) {
    assert.ok(x >= 0 && x <= 220, `x=${x} out of bounds`);
    assert.ok(y >= 0 && y <= 180, `y=${y} out of bounds`);
  }
});

test('generateTornEdgesPath produces a different path for two different random sources (varies per card)', () => {
  let call = 0;
  const seqA = [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8];
  const seqB = [0.9, 0.8, 0.7, 0.6, 0.5, 0.4, 0.3, 0.2];

  const pathA = generateTornEdgesPath(() => seqA[(call++) % seqA.length]);
  call = 0;
  const pathB = generateTornEdgesPath(() => seqB[(call++) % seqB.length]);

  assert.notEqual(pathA, pathB);
});

/**
 * Extrait, pour chaque arc de coin `A 22 22 0 0 1 x y`, son point d'arrivée
 * (x, y) — c'est le point qui suit immédiatement le "A 22 22 0 0 1 " dans le
 * path. Avec la structure actuelle, ce point EST le premier point de bruit
 * du bord suivant (ou le point M de fermeture pour le dernier arc) : aucun
 * point théorique de coin fixe n'est utilisé comme intermédiaire (cf.
 * commentaire de generateTornEdgesPath — régression #51).
 */
function extractArcEndpoints(path) {
  return [...path.matchAll(/A 22 22 0 0 1 ([\d.]+) ([\d.]+)/g)].map(([, x, y]) => [Number(x), Number(y)]);
}

test('generateTornEdgesPath keeps a wide enough chord across every corner arc (visible quarter-circle, not a flattened/cut corner)', () => {
  // Régression #51 : un arc de coin (rayon 22, 90°) doit relier directement
  // le dernier point de bruit d'un bord au premier point de bruit du bord
  // suivant, avec une corde d'environ 22*√2 ≈ 31px (la vraie corde
  // géométrique d'un quart de cercle). Une version précédente insérait un
  // point théorique de coin fixe (ex: (22,180)) entre l'arc et le bruit
  // suivant : l'arc lui-même ne reliait alors que deux points quasi
  // identiques (corde < 2px, arc invisible), et le vrai virage de 90° se
  // faisait sur un segment L en ligne droite juste après — d'où un coin
  // visuellement coupé au lieu d'arrondi.
  for (const randomValue of [0.99, 0.95, 0.9, 0.5, 0.1, 0.05, 0.01]) {
    const path = generateTornEdgesPath(() => randomValue);
    const arcStarts = [[0, 22], ...extractArcEndpoints(path).slice(0, -1)];
    const arcEnds = extractArcEndpoints(path);

    arcStarts.forEach(([startX, startY], i) => {
      const [endX, endY] = arcEnds[i];
      const chord = Math.hypot(endX - startX, endY - startY);
      assert.ok(chord > 20, `random=${randomValue} arc ${i}: chord=${chord.toFixed(2)} too short (points ${startX},${startY} and ${endX},${endY} too close)`);
    });
  }
});

test('generateTornEdgesPath never uses a fixed theoretical corner point as an arc endpoint (each arc ends on real jittered noise)', () => {
  // Régression #51, symptôme direct : une version buggée faisait finir un
  // arc sur un point de coin théorique fixe (ex: (22,180), (220,22)) au lieu
  // du premier point de bruit réel du bord suivant. Comme l'amplitude de
  // bruit est bornée à JITTER_AMPLITUDE_MAX=2.5px, un point d'arc tombant
  // exactement sur une valeur ronde de coin (0, 22, 198, 220, 180) à plus
  // de 2.5px de tout point théorique voisin serait suspect ; ici on vérifie
  // l'inverse et plus direct : sur beaucoup de tirages, aucun point de fin
  // d'arc ne doit être une valeur entière "ronde" caractéristique d'un coin
  // fixe (22, 198, 220, 180, 0) alors que jitterAmplitude()/jitterPositions()
  // ne produisent que des décimales avec bruit.
  const theoreticalCorners = new Set(['22', '198', '220', '180', '0']);
  for (let trial = 0; trial < 200; trial += 1) {
    const path = generateTornEdgesPath();
    const endpoints = [...path.matchAll(/A 22 22 0 0 1 ([\d.]+) ([\d.]+)/g)];
    for (const [, x, y] of endpoints) {
      const bothRound = theoreticalCorners.has(x) && theoreticalCorners.has(y);
      assert.ok(!bothRound, `trial ${trial}: arc endpoint (${x},${y}) looks like a fixed theoretical corner, not jittered noise`);
    }
  }
});

test('generateTornEdgesPath keeps a wide chord across every corner arc under real Math.random (fuzz, not just fixed samples)', () => {
  // Complète le test à valeurs fixes ci-dessus avec un fuzz sur de vrais
  // tirages aléatoires : la régression #51 n'apparaissait que pour certaines
  // combinaisons de tirages (intermittent selon les cartes), donc un test à
  // quelques valeurs fixes de random() peut passer sans couvrir le cas réel.
  for (let trial = 0; trial < 300; trial += 1) {
    const path = generateTornEdgesPath();
    const arcStarts = [[0, 22], ...extractArcEndpoints(path).slice(0, -1)];
    const arcEnds = extractArcEndpoints(path);

    arcStarts.forEach(([startX, startY], i) => {
      const [endX, endY] = arcEnds[i];
      const chord = Math.hypot(endX - startX, endY - startY);
      assert.ok(chord > 20, `trial ${trial} arc ${i}: chord=${chord.toFixed(2)} too short (points ${startX},${startY} and ${endX},${endY} too close)`);
    });
  }
});

test('generateTornEdgesPath produces a different path for each of the 4 edges (not a repeated/mirrored pattern)', () => {
  const path = generateTornEdgesPath(() => 0.5);
  // 4 arcs -> 5 segments après split ; le premier ("path('M 0 22 ") ne
  // contient aucun bruit (coin haut-gauche sans irrégularité adjacente,
  // comme le tracé statique d'origine) et n'est pas un bord à comparer.
  const segments = path.split(/A 22 22 0 0 1 [\d.]+ [\d.]+ /).slice(1);

  assert.equal(segments.length, 4);
  const uniqueSegments = new Set(segments.map((s) => s.trim()));
  assert.equal(uniqueSegments.size, 4, 'the 4 edges must not share the same jitter sequence');
});

test('shouldAutoScroll returns true when the viewport is narrower than the desktop breakpoint (mobile-first)', () => {
  assert.equal(shouldAutoScroll(767), true);
});

test('shouldAutoScroll returns false when the viewport is at least as wide as the desktop breakpoint', () => {
  assert.equal(shouldAutoScroll(768), false);
});

test('buildExceptionalCardMarkup renders group name, weekday, occurrence date and time range', () => {
  const markup = buildExceptionalCardMarkup({
    groupId: 3,
    groupName: 'Rust Prophet',
    isRecurring: false,
    weekday: 1,
    startTime: '18:00:00',
    endTime: '20:00:00',
    occurrenceDate: '2026-08-04',
  });

  assert.match(markup, /Rust Prophet/);
  assert.match(markup, /Mardi/);
  assert.match(markup, /04\/08\/2026/);
  assert.match(markup, /18:00.*20:00/);
  assert.match(markup, /rb-planning-card-label--occasional/);
  assert.match(markup, /rb-planning-card--exceptional/);
});

test('buildExceptionalCardMarkup escapes the group name to prevent XSS', () => {
  const markup = buildExceptionalCardMarkup({
    groupId: 1,
    groupName: '<img src=x onerror=alert(1)>',
    isRecurring: false,
    weekday: 0,
    startTime: '18:00:00',
    endTime: '20:00:00',
    occurrenceDate: '2026-08-04',
  });

  assert.ok(!markup.includes('<img'));
});

test('buildExceptionalCardMarkup does not render role/tabindex/data-contact attributes (non clickable, cf. #81)', () => {
  const markup = buildExceptionalCardMarkup({
    groupId: 3,
    groupName: 'Rust Prophet',
    isRecurring: false,
    weekday: 1,
    startTime: '18:00:00',
    endTime: '20:00:00',
    occurrenceDate: '2026-08-04',
  });

  assert.ok(!markup.includes('role="button"'));
  assert.ok(!markup.includes('tabindex'));
  assert.ok(!markup.includes('data-contact-group'));
});

test('refreshExceptionalPlanning fetches /api/planning and replaces the exceptional track content', async () => {
  globalThis.fetch = async (url) => {
    assert.equal(url, '/api/planning');
    return {
      ok: true,
      json: async () => ({
        fixedSlots: [{ groupId: 1, groupName: 'Fixed Group', isRecurring: true, weekday: 0, startTime: '18:00:00', endTime: '20:00:00', occurrenceDate: null }],
        occasionalSlots: [
          { groupId: 3, groupName: 'Rust Prophet', isRecurring: false, weekday: 1, startTime: '18:00:00', endTime: '20:00:00', occurrenceDate: '2026-08-04' },
        ],
      }),
    };
  };

  let assignedHtml = null;
  const track = {
    set innerHTML(value) {
      assignedHtml = value;
    },
    get innerHTML() {
      return assignedHtml;
    },
    querySelectorAll: () => [],
  };
  const section = { hidden: true, removeAttribute: function (attr) { if (attr === 'hidden') this.hidden = false; } };
  const root = {
    querySelector: (selector) => {
      if (selector === '[data-planning-track-exceptional]') return track;
      if (selector === '[data-exceptional-planning-section]') return section;
      return null;
    },
  };

  await refreshExceptionalPlanning(root);

  assert.match(assignedHtml, /Rust Prophet/);
  assert.ok(!assignedHtml.includes('Fixed Group'));
  assert.equal(section.hidden, false, 'the section must be revealed when occasional slots are present');
});

test('refreshExceptionalPlanning hides the section again when there are no more occasional slots', async () => {
  globalThis.fetch = async () => ({
    ok: true,
    json: async () => ({ fixedSlots: [], occasionalSlots: [] }),
  });

  const track = { innerHTML: '', querySelectorAll: () => [] };
  const section = { hidden: false, setAttribute: function (attr) { if (attr === 'hidden') this.hidden = true; } };
  const root = {
    querySelector: (selector) => {
      if (selector === '[data-planning-track-exceptional]') return track;
      if (selector === '[data-exceptional-planning-section]') return section;
      return null;
    },
  };

  await refreshExceptionalPlanning(root);

  assert.equal(section.hidden, true);
});
