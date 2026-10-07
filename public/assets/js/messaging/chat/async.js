/**
 * Briques asynchrones de la messagerie (#181) : attente annulable et attente de visibilité. Elles remplacent les
 * minuteries `setTimeout` récursives par de simples boucles `while` + `await`, annulables d'un seul `AbortController`.
 */

export function isAbort(error) {
  return error?.name === 'AbortError';
}

function abortError(signal) {
  return signal?.reason instanceof Error ? signal.reason : new DOMException('Aborted', 'AbortError');
}

/** Attend `ms` millisecondes ; rejette aussitôt avec une AbortError si le signal est annulé. */
export function sleep(ms, signal) {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) {
      reject(abortError(signal));
      return;
    }
    const onAbort = () => {
      clearTimeout(timer);
      reject(abortError(signal));
    };
    const timer = setTimeout(() => {
      signal?.removeEventListener('abort', onAbort);
      resolve();
    }, ms);
    signal?.addEventListener('abort', onAbort, { once: true });
  });
}

/** Résout quand la page est visible (tout de suite si elle l'est déjà) ; rejette si le signal est annulé. */
export function whenVisible(doc = document, signal) {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) {
      reject(abortError(signal));
      return;
    }
    if (!doc.hidden) {
      resolve();
      return;
    }
    const cleanup = () => {
      doc.removeEventListener('visibilitychange', onChange);
      signal?.removeEventListener('abort', onAbort);
    };
    const onChange = () => {
      if (!doc.hidden) {
        cleanup();
        resolve();
      }
    };
    const onAbort = () => {
      cleanup();
      reject(abortError(signal));
    };
    doc.addEventListener('visibilitychange', onChange);
    signal?.addEventListener('abort', onAbort, { once: true });
  });
}
