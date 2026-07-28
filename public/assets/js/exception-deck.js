/**
 * Deck de cartes empilées (#42) : navigation swipe manuelle bornée aux deux
 * extrémités — contrairement au planning-slider.js du dessus (défilement
 * auto en boucle continue), ici pas d'auto-scroll et pas de boucle : on
 * bloque en butée en début/fin de liste.
 *
 * Logique de navigation/drag extraite de tout DOM/event pour rester
 * testable en environnement node --test (cf. planning-slider.js).
 */
export function createDeckSwipeController({ count, threshold = 80 }) {
  let index = 0;
  let dragStartX = null;
  let dragOffset = 0;

  function clampIndex(value) {
    if (count === 0) {
      return 0;
    }
    return Math.min(Math.max(value, 0), count - 1);
  }

  return {
    currentIndex: () => index,
    dragOffset: () => dragOffset,

    next() {
      index = clampIndex(index + 1);
    },

    previous() {
      index = clampIndex(index - 1);
    },

    handleDragStart(clientX) {
      dragStartX = clientX;
      dragOffset = 0;
    },

    handleDragMove(clientX) {
      if (dragStartX === null) {
        return;
      }
      dragOffset = clientX - dragStartX;
    },

    handleDragEnd(clientX) {
      const delta = dragStartX === null ? 0 : clientX - dragStartX;

      if (delta <= -threshold) {
        index = clampIndex(index + 1);
      } else if (delta >= threshold) {
        index = clampIndex(index - 1);
      }

      dragStartX = null;
      dragOffset = 0;
    },
  };
}

/**
 * Neutralise les cartes hors du dessus de pile : sans ça, boutons et
 * formulaires des cartes en profondeur restent cliquables/focusables malgré
 * leur position visuelle en arrière-plan (transform + z-index seuls ne
 * suffisent pas). Piloté ici plutôt qu'en CSS pour suivre la carte
 * réellement active après un swipe, pas juste sa position dans le DOM.
 */
export function computeCardState(relativeIndex) {
  return {
    pointerEvents: relativeIndex === 0 ? 'auto' : 'none',
    hidden: relativeIndex < 0,
  };
}

export function initExceptionDeck(root = document) {
  const deck = root.querySelector('[data-exception-deck]');
  if (!deck) {
    return;
  }

  const cards = Array.from(deck.querySelectorAll('.rb-exception-card'));
  if (cards.length === 0) {
    return;
  }

  const controller = createDeckSwipeController({ count: cards.length });

  function render() {
    cards.forEach((card, cardPosition) => {
      const relativeIndex = cardPosition - controller.currentIndex();
      const { pointerEvents, hidden } = computeCardState(relativeIndex);
      card.style.setProperty('--deck-index', relativeIndex < 0 ? '0' : String(relativeIndex));
      card.style.setProperty('--deck-drag-x', relativeIndex === 0 ? `${controller.dragOffset()}px` : '0px');
      card.style.pointerEvents = pointerEvents;
      card.hidden = hidden;
    });
  }

  function onDragStart(clientX, card) {
    card.setAttribute('data-dragging', '');
    controller.handleDragStart(clientX);
  }

  function onDragMove(clientX) {
    controller.handleDragMove(clientX);
    render();
  }

  function onDragEnd(clientX, card) {
    card.removeAttribute('data-dragging');
    controller.handleDragEnd(clientX);
    render();
  }

  deck.addEventListener('mousedown', (event) => {
    const card = event.target.closest('.rb-exception-card');
    if (!card) {
      return;
    }
    onDragStart(event.clientX, card);

    const onMouseMove = (moveEvent) => onDragMove(moveEvent.clientX);
    const onMouseUp = (upEvent) => {
      onDragEnd(upEvent.clientX, card);
      document.removeEventListener('mousemove', onMouseMove);
      document.removeEventListener('mouseup', onMouseUp);
    };
    document.addEventListener('mousemove', onMouseMove);
    document.addEventListener('mouseup', onMouseUp);
  });

  deck.addEventListener('touchstart', (event) => {
    const card = event.target.closest('.rb-exception-card');
    if (!card) {
      return;
    }
    onDragStart(event.touches[0].clientX, card);
  }, { passive: true });

  deck.addEventListener('touchmove', (event) => {
    onDragMove(event.touches[0].clientX);
  }, { passive: true });

  deck.addEventListener('touchend', (event) => {
    const card = event.target.closest('.rb-exception-card');
    if (!card) {
      return;
    }
    onDragEnd(event.changedTouches[0].clientX, card);
  });

  render();

  return controller;
}
