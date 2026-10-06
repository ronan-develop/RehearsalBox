/**
 * Logique pure de la cloche de sourdine (#210), sans DOM : l'état suivant d'après `aria-pressed`, et le nom accessible, lu dans les
 * libellés rendus par le serveur (jamais reconstruit côté navigateur à partir du titre de la conversation).
 */

/** @param {string | null | undefined} pressed valeur de aria-pressed ; tout ce qui n'est pas « true » compte comme actif */
export function nextMuted(pressed) {
  return pressed !== 'true';
}

/** @param {{ labelMute?: string, labelUnmute?: string }} labels attributs data-label-* de <rb-mute-toggle> */
export function labelFor(labels, muted) {
  return (muted ? labels.labelUnmute : labels.labelMute) ?? '';
}
