/**
 * Rendu DOM de la messagerie (#169) : liste des conversations, bulles du fil, pastilles. Aucune logique réseau ni d'état
 * (cf. chat.js) ; le texte des messages n'est JAMAIS inséré en HTML (textContent uniquement).
 */
import {
  groupByDay, formatTime, formatListDate, safeColor, previewText, systemLine, routeFor,
} from './model.js';

function el(tag, className, text) {
  const node = document.createElement(tag);
  if (className) {
    node.className = className;
  }
  if (text !== undefined) {
    node.textContent = text;
  }

  return node;
}

function avatar(message) {
  const node = el('span', 'rb-chat-avatar', message.initials);
  node.setAttribute('aria-hidden', 'true');
  const color = safeColor(message.groupColor);
  if (color) {
    node.style.setProperty('--group-color', color);
  }
  if (message.groupName) {
    node.title = message.groupName;
  }

  return node;
}

export function renderMessages(container, messages, { firstUnreadId = null, pending = [], now = new Date() } = {}) {
  const nodes = [];
  let previousAuthor = null;

  for (const group of groupByDay(messages, now)) {
    nodes.push(el('li', 'rb-chat-day', group.label));
    previousAuthor = null;
    for (const message of group.messages) {
      if (message.id === firstUnreadId) {
        nodes.push(el('li', 'rb-chat-unread', 'Messages non lus'));
        previousAuthor = null;
      }
      if (message.system) {
        nodes.push(el('li', 'rb-chat-system', systemLine(message)));
        previousAuthor = null;
        continue;
      }
      const startsRun = previousAuthor !== message.authorName || message.mine;
      previousAuthor = message.authorName;
      nodes.push(renderBubble(message, startsRun, false));
    }
  }
  for (const draft of pending) {
    nodes.push(renderBubble(draft, true, true));
  }

  container.replaceChildren(...nodes);
}

function renderBubble(message, startsRun, isPending) {
  const item = el('li', 'rb-chat-message'
    + (message.mine ? ' rb-chat-message--mine' : '')
    + (isPending ? ' rb-chat-message--pending' : '')
    + (message.failed ? ' rb-chat-message--failed' : ''));
  const bubble = el('div', 'rb-chat-bubble');

  if (!message.mine) {
    item.append(startsRun ? avatar(message) : el('span', 'rb-chat-avatar-spacer'));
    if (startsRun) {
      bubble.append(el('span', 'rb-chat-author', message.authorName));
    }
  }
  bubble.append(el('p', 'rb-chat-text', message.body), el('span', 'rb-chat-time', isPending ? (message.failed ? 'Échec' : '…') : formatTime(message.createdAt)));
  item.append(bubble);

  return item;
}

export function renderList(container, conversations, activeId, now = new Date()) {
  const items = conversations.map((conversation) => {
    const item = el('li', 'rb-chat-item' + (conversation.unread ? ' rb-chat-item--unread' : '') + (String(conversation.id) === String(activeId) ? ' rb-chat-item--active' : ''));
    const link = el('a', 'rb-chat-item-link');
    link.href = routeFor(conversation.id);
    link.dataset.conversationId = String(conversation.id);

    const head = el('span', 'rb-chat-item-head');
    head.append(el('span', 'rb-chat-item-title', conversation.displayTitle), el('span', 'rb-chat-item-date', formatListDate(conversation.lastMessage.createdAt, now)));
    link.append(head, el('span', 'rb-chat-item-preview', previewText(conversation.lastMessage)));
    if (conversation.unread) {
      const dot = el('span', 'rb-chat-item-dot');
      dot.setAttribute('aria-label', 'Non lu');
      link.append(dot);
    }
    item.append(link);

    return item;
  });
  container.replaceChildren(...items);
}
