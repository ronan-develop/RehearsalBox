/**
 * Messagerie entre groupes (#153) : onglets Reçues / Envoyées / Archivées, fil de conversation
 * en modale, réponse et archivage. Toute écriture passe par apiFetch (CSRF) ; le contenu des
 * messages n'est JAMAIS inséré en HTML (textContent uniquement).
 */
import { apiFetch } from './api.js';
import { showToast } from './toast.js';

export const BOXES = ['received', 'sent', 'archived'];

export function fetchBox(box) {
  return apiFetch(`/api/conversations?box=${encodeURIComponent(box)}`);
}

export function fetchThread(id) {
  return apiFetch(`/api/conversations/${encodeURIComponent(id)}`);
}

export function postReply(id, message) {
  return apiFetch(`/api/conversations/${encodeURIComponent(id)}/messages`, {
    method: 'POST',
    body: JSON.stringify({ message }),
  });
}

export function setArchived(id, archived) {
  return apiFetch(`/api/conversations/${encodeURIComponent(id)}`, {
    method: 'PATCH',
    body: JSON.stringify({ archived }),
  });
}

/** Envoyées : MON dernier message ; Reçues et Archivées : le dernier message du fil. */
export function previewOf(box, conversation) {
  const message = box === 'sent' && conversation.myLastMessage ? conversation.myLastMessage : conversation.lastMessage;

  return { author: message.mine ? 'Vous' : message.authorName, body: message.body };
}

export function formatMessageDate(isoDate, now = new Date()) {
  const date = new Date(isoDate);
  if (Number.isNaN(date.getTime())) {
    return '';
  }

  const two = (n) => String(n).padStart(2, '0');
  const sameDay = date.getFullYear() === now.getFullYear() && date.getMonth() === now.getMonth() && date.getDate() === now.getDate();

  return sameDay ? `${two(date.getHours())}:${two(date.getMinutes())}` : `${two(date.getDate())}/${two(date.getMonth() + 1)}`;
}

/** Valeur à envoyer au bouton d'archivage : on archive depuis Reçues/Envoyées, on restaure depuis Archivées. */
export function archiveTargetFor(box) {
  return box !== 'archived';
}

function element(tag, className, text) {
  const node = document.createElement(tag);
  if (className) {
    node.className = className;
  }
  if (text !== undefined) {
    node.textContent = text;
  }

  return node;
}

function renderConversation(box, conversation) {
  const preview = previewOf(box, conversation);

  const item = element('li', 'rb-messages-item' + (conversation.unread ? ' rb-messages-item--unread' : ''));
  const button = element('button', 'rb-messages-item-button');
  button.type = 'button';
  button.dataset.conversationId = String(conversation.id);

  const head = element('span', 'rb-messages-item-head');
  head.append(
    element('span', 'rb-messages-item-label', conversation.label),
    element('span', 'rb-messages-item-date', formatMessageDate(conversation.lastMessage.createdAt)),
  );
  button.append(
    head,
    element('span', 'rb-messages-item-subject', conversation.subject),
    element('span', 'rb-messages-item-preview', `${preview.author} : ${preview.body}`),
  );
  if (conversation.unread) {
    const dot = element('span', 'rb-messages-item-dot');
    dot.setAttribute('aria-label', 'Non lu');
    button.append(dot);
  }
  item.append(button);

  return item;
}

function renderMessage(message) {
  const item = element('li', 'rb-thread-message' + (message.mine ? ' rb-thread-message--mine' : ''));
  const meta = element('span', 'rb-thread-message-meta', `${message.mine ? 'Vous' : message.authorName} · ${formatMessageDate(message.createdAt)}`);
  item.append(meta, element('p', 'rb-thread-message-body', message.body));

  return item;
}

export function initMessages(root = document) {
  const section = root.querySelector('[data-messages]');
  if (!section) {
    return;
  }

  const list = section.querySelector('[data-messages-list]');
  const empty = section.querySelector('[data-messages-empty]');
  const unreadBadge = section.querySelector('[data-messages-unread]');
  const overlay = root.querySelector('[data-thread-overlay]');

  let currentBox = 'received';
  let openId = null;

  const refreshList = async () => {
    try {
      const { conversations, unread } = await fetchBox(currentBox);
      list.replaceChildren(...conversations.map((conversation) => renderConversation(currentBox, conversation)));
      empty.hidden = conversations.length > 0;
      unreadBadge.textContent = String(unread);
      unreadBadge.hidden = unread === 0;
    } catch (error) {
      showToast(error.message, 'error');
    }
  };

  const showThread = async (id) => {
    try {
      const thread = await fetchThread(id);
      openId = id;
      overlay.querySelector('[data-thread-title]').textContent = thread.subject;
      overlay.querySelector('[data-thread-label]').textContent = thread.label;
      const messages = overlay.querySelector('[data-thread-messages]');
      messages.replaceChildren(...thread.messages.map(renderMessage));
      overlay.querySelector('[data-thread-archive]').textContent = archiveTargetFor(currentBox) ? 'Archiver' : 'Désarchiver';
      overlay.hidden = false;
      messages.scrollTop = messages.scrollHeight;
      await refreshList();
    } catch (error) {
      showToast(error.message, 'error');
    }
  };

  const closeThread = () => {
    overlay.hidden = true;
    openId = null;
    overlay.querySelector('[data-thread-form]').reset();
  };

  section.addEventListener('click', (event) => {
    const tab = event.target.closest('[data-messages-box]');
    if (tab) {
      currentBox = tab.dataset.messagesBox;
      section.querySelectorAll('[data-messages-box]').forEach((other) => {
        other.setAttribute('aria-selected', other === tab ? 'true' : 'false');
      });
      refreshList();
      return;
    }

    const item = event.target.closest('[data-conversation-id]');
    if (item) {
      showThread(item.dataset.conversationId);
    }
  });

  overlay?.addEventListener('click', async (event) => {
    if (event.target === overlay || event.target.closest('[data-thread-close]')) {
      closeThread();
      return;
    }

    if (event.target.closest('[data-thread-archive]') && openId !== null) {
      try {
        await setArchived(openId, archiveTargetFor(currentBox));
        closeThread();
        await refreshList();
      } catch (error) {
        showToast(error.message, 'error');
      }
    }
  });

  overlay?.querySelector('[data-thread-form]')?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const field = event.target.elements.message;
    const text = field.value.trim();
    if (text === '' || openId === null) {
      return;
    }

    try {
      await postReply(openId, text);
      field.value = '';
      await showThread(openId);
    } catch (error) {
      showToast(error.message, 'error');
    }
  });

  root.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && overlay && !overlay.hidden) {
      closeThread();
    }
  });

  refreshList();
}
