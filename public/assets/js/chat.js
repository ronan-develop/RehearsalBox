/**
 * Messagerie à la Signal (#169) : contrôleur de la page (état, navigation par route, polling, envoi optimiste, titre
 * modifiable). Le rendu est dans chat-view.js, la logique pure dans chat-model.js, les appels réseau dans chat-api.js.
 * Quasi temps réel par polling (mutualisé : ni WebSocket ni processus permanent).
 */
import {
  fetchList, fetchThread, sendMessage, renameConversation, sendTyping,
} from './chat-api.js';
import {
  typingText, seenText, nextPollDelay, shouldSendTyping, parseRoute, routeFor, mergeMessages, lastMessageId,
} from './chat-model.js';
import { renderList, renderMessages } from './chat-view.js';
import { showToast } from './toast.js';

const LIST_POLL_MS = 30000;

/** Pastille « non lu » du lien Messages (dashboard) : chargée à l'ouverture, rafraîchie à la minute tant que l'onglet est visible. */
export function initMessagesBadge(root = document) {
  const badge = root.querySelector('[data-messages-link-badge]');
  if (!badge) {
    return;
  }
  const refresh = async () => {
    try {
      const { unread } = await fetchList('active');
      badge.textContent = String(unread.total);
      badge.hidden = unread.total === 0;
    } catch (error) {
      badge.hidden = true;
    }
  };
  refresh();
  setInterval(() => {
    if (!document.hidden) {
      refresh();
    }
  }, 60000);
}

export function initChat(root = document) {
  const chat = root.querySelector('[data-chat]');
  if (!chat) {
    return;
  }

  const $ = (selector) => chat.querySelector(selector);
  const listEl = $('[data-chat-list]');
  const emptyEl = $('[data-chat-empty]');
  const archivesBtn = $('[data-chat-archives]');
  const archivesBadge = $('[data-chat-archives-unread]');
  const leaveArchivesBtn = $('[data-chat-leave-archives]');
  const homeLink = $('[data-chat-home]');
  const listTitle = $('[data-chat-list-title]');
  const placeholder = $('[data-chat-placeholder]');
  const threadEl = $('[data-chat-thread]');
  const titleBtn = $('[data-chat-title]');
  const renameForm = $('[data-chat-rename-form]');
  const labelEl = $('[data-chat-label]');
  const messagesEl = $('[data-chat-messages]');
  const statusEl = $('[data-chat-status]');
  const form = $('[data-chat-form]');
  const textarea = form.elements.message;

  let inArchives = false;
  let activeId = chat.dataset.activeId || null;
  let thread = null;
  let messages = [];
  let pending = [];
  let pollTimer = null;
  let listTimer = null;
  let idlePolls = 0;
  let lastTypingSent = null;
  let pendingSeq = 0;

  const nearBottom = () => messagesEl.scrollHeight - messagesEl.scrollTop - messagesEl.clientHeight < 80;
  const scrollToBottom = () => { messagesEl.scrollTop = messagesEl.scrollHeight; };
  const setView = (view) => { chat.dataset.view = view; };

  const refreshStatus = () => {
    const typing = typingText(thread?.typing ?? []);
    statusEl.textContent = typing || seenText(thread?.seen ?? null);
    statusEl.classList.toggle('rb-chat-status--typing', typing !== '');
  };

  const draw = (opts = {}) => {
    renderMessages(messagesEl, messages, { pending, firstUnreadId: opts.firstUnreadId ?? null });
    refreshStatus();
  };

  // --- Liste -------------------------------------------------------------------------------------
  const loadList = async () => {
    try {
      const { conversations, unread } = await fetchList(inArchives ? 'archived' : 'active');
      renderList(listEl, conversations, activeId);
      emptyEl.hidden = conversations.length > 0;
      emptyEl.textContent = inArchives ? 'Aucune conversation archivée.' : 'Aucune conversation. Écrivez à un groupe depuis le planning ou depuis sa page.';
      archivesBadge.textContent = String(unread.archived);
      archivesBadge.hidden = unread.archived === 0;
    } catch (error) {
      showToast(error.message, 'error');
    }
  };

  const scheduleList = () => {
    clearTimeout(listTimer);
    listTimer = setTimeout(async () => {
      if (!document.hidden) {
        await loadList();
      }
      scheduleList();
    }, LIST_POLL_MS);
  };

  const showArchives = (on) => {
    inArchives = on;
    archivesBtn.hidden = on;
    leaveArchivesBtn.hidden = !on;
    homeLink.hidden = on;
    listTitle.textContent = on ? 'Archivées' : 'Messages';
    loadList();
  };

  // --- Fil -----------------------------------------------------------------------------------------
  const applyThread = (data) => {
    thread = data;
    titleBtn.textContent = data.displayTitle;
    labelEl.textContent = data.title ? data.label : '';
    labelEl.hidden = !data.title;
  };

  const stopPolling = () => clearTimeout(pollTimer);

  const schedulePoll = () => {
    clearTimeout(pollTimer);
    pollTimer = setTimeout(poll, nextPollDelay(idlePolls));
  };

  async function poll() {
    if (activeId === null) {
      return;
    }
    if (document.hidden) {
      schedulePoll();
      return;
    }
    const id = activeId;
    try {
      const update = await fetchThread(id, lastMessageId(messages));
      if (id !== activeId) {
        return;
      }
      const stick = nearBottom();
      const before = messages.length;
      messages = mergeMessages(messages, update.messages);
      applyThread({ ...update, messages: undefined });
      draw();
      idlePolls = messages.length > before ? 0 : idlePolls + 1;
      if (messages.length > before) {
        if (stick) {
          scrollToBottom();
        }
        loadList();
      }
    } catch (error) {
      idlePolls += 1;
    }
    schedulePoll();
  }

  const openConversation = async (id, { push = true } = {}) => {
    stopPolling();
    activeId = String(id);
    pending = [];
    idlePolls = 0;
    placeholder.hidden = true;
    threadEl.hidden = false;
    setView('thread');
    if (push) {
      history.pushState({ id: activeId }, '', routeFor(activeId));
    }
    try {
      const data = await fetchThread(activeId);
      if (String(data.id) !== activeId) {
        return;
      }
      messages = data.messages;
      applyThread(data);
      draw({ firstUnreadId: data.firstUnreadId });
      const marker = messagesEl.querySelector('.rb-chat-unread');
      if (marker) {
        marker.scrollIntoView({ block: 'start' });
      } else {
        scrollToBottom();
      }
      loadList();
      schedulePoll();
    } catch (error) {
      showToast(error.message, 'error');
      closeConversation({ push: false });
    }
  };

  function closeConversation({ push = true } = {}) {
    stopPolling();
    activeId = null;
    thread = null;
    messages = [];
    pending = [];
    threadEl.hidden = true;
    placeholder.hidden = false;
    setView('list');
    if (push) {
      history.pushState({ id: null }, '', routeFor(null));
    }
    loadList();
  }

  // --- Envoi (optimiste) -----------------------------------------------------------------------------
  const autosize = () => {
    textarea.style.height = 'auto';
    textarea.style.height = `${Math.min(textarea.scrollHeight, 140)}px`;
  };

  const submit = async () => {
    const text = textarea.value.trim();
    if (text === '' || activeId === null) {
      return;
    }
    const draftMessage = { tempId: `tmp-${++pendingSeq}`, body: text, mine: true, failed: false };
    pending.push(draftMessage);
    textarea.value = '';
    autosize();
    draw();
    scrollToBottom();
    const id = activeId;
    try {
      const { message } = await sendMessage(id, text);
      pending = pending.filter((draft) => draft !== draftMessage);
      if (id === activeId) {
        messages = mergeMessages(messages, [message]);
        draw();
        scrollToBottom();
        idlePolls = 0;
        loadList();
      }
    } catch (error) {
      draftMessage.failed = true;
      pending = pending.filter((draft) => draft !== draftMessage);
      textarea.value = text;
      autosize();
      draw();
      showToast(error.message, 'error');
    }
  };

  form.addEventListener('submit', (event) => {
    event.preventDefault();
    submit();
  });
  textarea.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
      event.preventDefault();
      submit();
    }
  });
  textarea.addEventListener('input', () => {
    autosize();
    if (activeId !== null && textarea.value.trim() !== '' && shouldSendTyping(lastTypingSent, Date.now())) {
      lastTypingSent = Date.now();
      sendTyping(activeId).catch(() => {});
    }
  });

  // --- Titre modifiable --------------------------------------------------------------------------------
  titleBtn.addEventListener('click', () => {
    renameForm.title.value = thread?.title ?? '';
    renameForm.hidden = false;
    titleBtn.hidden = true;
    renameForm.title.focus();
  });
  const closeRename = () => {
    renameForm.hidden = true;
    titleBtn.hidden = false;
  };
  renameForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const id = activeId;
    try {
      await renameConversation(id, renameForm.title.value);
      closeRename();
      if (id === activeId) {
        const data = await fetchThread(id, lastMessageId(messages));
        messages = mergeMessages(messages, data.messages);
        applyThread({ ...data, messages: undefined });
        draw();
        scrollToBottom();
        loadList();
      }
    } catch (error) {
      showToast(error.message, 'error');
    }
  });
  renameForm.title.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      closeRename();
    }
  });

  // --- Navigation (une route par conversation) -----------------------------------------------------------------
  chat.addEventListener('click', (event) => {
    const link = event.target.closest('[data-conversation-id]');
    if (link && !(event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1)) {
      event.preventDefault();
      openConversation(link.dataset.conversationId);
      return;
    }
    if (event.target.closest('[data-chat-back]') && !(event.metaKey || event.ctrlKey)) {
      event.preventDefault();
      closeConversation();
    }
  });
  archivesBtn.addEventListener('click', () => showArchives(true));
  leaveArchivesBtn.addEventListener('click', () => showArchives(false));
  window.addEventListener('popstate', () => {
    const { id } = parseRoute(window.location.pathname);
    if (id === null) {
      closeConversation({ push: false });
    } else {
      openConversation(id, { push: false });
    }
  });
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) {
      idlePolls = 0;
      loadList();
      if (activeId !== null) {
        stopPolling();
        poll();
      }
    }
  });

  loadList();
  scheduleList();
  if (activeId !== null) {
    openConversation(activeId, { push: false });
  } else {
    setView('list');
  }
}
