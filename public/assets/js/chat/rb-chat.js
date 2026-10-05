/**
 * <rb-chat> : contrôleur de la messagerie (#169, #181). Il possède l'état (conversation ouverte, messages, brouillon),
 * écoute les événements des composants (<rb-sidebar>, <rb-thread-header>, <rb-message-list>, <rb-composer>) et parle à
 * l'API. Quasi temps réel par polling (mutualisé : ni WebSocket ni processus permanent) : des boucles async/await
 * annulables d'un seul AbortController, en pause quand l'onglet est caché.
 */
import './rb-sidebar.js';
import './rb-thread-header.js';
import './rb-message-list.js';
import './rb-composer.js';
import {
  fetchList, fetchThread, sendMessage, renameConversation, sendTyping, startConversation,
} from './api.js';
import { isAbort, sleep, whenVisible } from './async.js';
import { EVT } from './events.js';
import {
  typingText, seenText, nextPollDelay, routeFor, parseRoute, mergeMessages, lastMessageId,
} from './model.js';
import { showToast } from '../toast.js';

const LIST_POLL_MS = 30000;

export class RbChat extends HTMLElement {
  #lifetime = new AbortController(); // vit autant que l'élément (boucle de la liste)
  #session = null; // AbortController de la conversation ouverte (chargement + polling)
  #activeId = null;
  #draftTargetId = null;
  #inArchives = false;
  #messages = [];
  #pending = [];
  #pendingSeq = 0;
  #idle = 0;

  connectedCallback() {
    this.sidebar = this.querySelector('rb-sidebar');
    this.header = this.querySelector('rb-thread-header');
    this.messageList = this.querySelector('rb-message-list');
    this.composer = this.querySelector('rb-composer');
    this.statusEl = this.querySelector('[data-chat-status]');
    this.placeholder = this.querySelector('[data-chat-placeholder]');
    this.threadEl = this.querySelector('[data-chat-thread]');

    this.#activeId = this.dataset.activeId || null;
    this.#draftTargetId = this.dataset.draftTargetId || null;

    this.addEventListener(EVT.SELECT, (event) => this.#open(event.detail.id));
    this.addEventListener(EVT.ARCHIVES, (event) => this.#showArchives(event.detail.on));
    this.addEventListener(EVT.BACK, () => this.#close());
    this.addEventListener(EVT.RENAME, (event) => this.#rename(event.detail.title));
    this.addEventListener(EVT.SUBMIT, (event) => this.#submit(event.detail.text));
    this.addEventListener(EVT.TYPING, () => {
      if (this.#activeId !== null) {
        sendTyping(this.#activeId).catch(() => {});
      }
    });
    window.addEventListener('popstate', () => this.#onPopState(), { signal: this.#lifetime.signal });

    this.#listLoop(this.#lifetime.signal);
    if (this.#draftTargetId !== null) {
      this.header.setDraft(true);
      this.composer.focus();
    } else if (this.#activeId !== null) {
      this.#open(this.#activeId, { push: false });
    } else {
      this.#setView('list');
    }
  }

  disconnectedCallback() {
    this.#lifetime.abort();
    this.#session?.abort();
  }

  #setView(view) {
    this.dataset.view = view;
  }

  // --- Liste ---------------------------------------------------------------------------------------------

  async #listLoop(signal) {
    let first = true;
    while (!signal.aborted) {
      try {
        await whenVisible(document, signal);
        await this.#loadList({ signal, quiet: !first });
        first = false;
        await sleep(LIST_POLL_MS, signal);
      } catch (error) {
        if (isAbort(error)) {
          return;
        }
        await sleep(LIST_POLL_MS, signal).catch(() => {});
      }
    }
  }

  async #loadList({ signal, quiet = true } = {}) {
    try {
      const { conversations, unread } = await fetchList(this.#inArchives ? 'archived' : 'active', { signal });
      this.sidebar.setConversations(conversations, this.#activeId, { archived: this.#inArchives });
      this.sidebar.setArchivesUnread(unread.archived);
    } catch (error) {
      if (!isAbort(error) && !quiet) {
        showToast(error.message, 'error');
      }
      throw error;
    }
  }

  #refreshList() {
    this.#loadList().catch(() => {});
  }

  #showArchives(on) {
    this.#inArchives = on;
    this.sidebar.showArchives(on);
    this.#refreshList();
  }

  // --- Fil ---------------------------------------------------------------------------------------------------

  async #open(id, { push = true } = {}) {
    this.#session?.abort();
    const session = new AbortController();
    this.#session = session;
    this.#activeId = String(id);
    this.#draftTargetId = null;
    this.#pending = [];
    this.#idle = 0;
    this.placeholder.hidden = true;
    this.threadEl.hidden = false;
    this.header.setDraft(false);
    this.#setView('thread');
    if (push) {
      history.pushState({ id: this.#activeId }, '', routeFor(this.#activeId));
    }

    let data;
    try {
      data = await fetchThread(this.#activeId, undefined, { signal: session.signal });
    } catch (error) {
      if (!isAbort(error)) {
        showToast(error.message, 'error');
        this.#close({ push: false });
      }
      return;
    }

    this.#messages = data.messages;
    this.header.setThread(data);
    this.messageList.render(this.#messages, { firstUnreadId: data.firstUnreadId });
    if (!this.messageList.scrollToUnread()) {
      this.messageList.scrollToBottom();
    }
    this.#refreshStatus(data);
    this.#refreshList();
    this.#pollLoop(session.signal);
  }

  #close({ push = true } = {}) {
    this.#session?.abort();
    this.#session = null;
    this.#activeId = null;
    this.#draftTargetId = null;
    this.#messages = [];
    this.#pending = [];
    this.threadEl.hidden = true;
    this.placeholder.hidden = false;
    this.#setView('list');
    if (push) {
      history.pushState({ id: null }, '', routeFor(null));
    }
    this.#refreshList();
  }

  async #pollLoop(signal) {
    while (!signal.aborted) {
      try {
        await sleep(nextPollDelay(this.#idle), signal);
        await whenVisible(document, signal);
        await this.#pollOnce(signal);
      } catch (error) {
        if (isAbort(error)) {
          return;
        }
        this.#idle += 1;
      }
    }
  }

  /** Une lecture incrémentale : les messages plus récents, qui écrit, « vu par », titre éventuellement renommé. */
  async #pollOnce(signal = this.#session?.signal) {
    const id = this.#activeId;
    const update = await fetchThread(id, lastMessageId(this.#messages), { signal });
    if (id !== this.#activeId) {
      return;
    }
    const stick = this.messageList.nearBottom();
    const before = this.#messages.length;
    this.#messages = mergeMessages(this.#messages, update.messages);
    this.header.setThread(update);
    this.messageList.render(this.#messages, { pending: this.#pending });
    this.#refreshStatus(update);
    if (this.#messages.length > before) {
      this.#idle = 0;
      if (stick) {
        this.messageList.scrollToBottom();
      }
      this.#refreshList();
    } else {
      this.#idle += 1;
    }
  }

  #refreshStatus(data) {
    const typing = typingText(data.typing ?? []);
    this.statusEl.textContent = typing || seenText(data.seen ?? null);
    this.statusEl.classList.toggle('rb-chat-status--typing', typing !== '');
  }

  // --- Envoi optimiste, brouillon, titre -------------------------------------------------------------------------------

  async #submit(text) {
    if (this.#draftTargetId !== null) {
      await this.#submitDraft(text);
      return;
    }
    if (this.#activeId === null) {
      return;
    }
    const id = this.#activeId;
    const draftMessage = { tempId: `tmp-${++this.#pendingSeq}`, body: text, mine: true, failed: false };
    this.#pending.push(draftMessage);
    this.messageList.render(this.#messages, { pending: this.#pending });
    this.messageList.scrollToBottom();

    try {
      const { message } = await sendMessage(id, text);
      this.#pending = this.#pending.filter((item) => item !== draftMessage);
      if (id === this.#activeId) {
        this.#messages = mergeMessages(this.#messages, [message]);
        this.messageList.render(this.#messages, { pending: this.#pending });
        this.messageList.scrollToBottom();
        this.#idle = 0;
        this.#refreshList();
      }
    } catch (error) {
      this.#pending = this.#pending.filter((item) => item !== draftMessage);
      this.messageList.render(this.#messages, { pending: this.#pending });
      this.composer.restore(text);
      showToast(error.message, 'error');
    }
  }

  /** Premier message d'un brouillon : la conversation est créée, l'adresse devient /messages/{id} sans rechargement. */
  async #submitDraft(text) {
    const draftMessage = { tempId: `tmp-${++this.#pendingSeq}`, body: text, mine: true, failed: false };
    this.messageList.render([], { pending: [draftMessage] });
    try {
      const { id } = await startConversation({
        groupId: this.header.senderId,
        targetGroupId: this.#draftTargetId,
        message: text,
      });
      history.replaceState({ id: String(id) }, '', routeFor(id));
      delete this.dataset.draftTargetId;
      await this.#open(id, { push: false });
    } catch (error) {
      this.messageList.render([]);
      this.composer.restore(text);
      showToast(error.message, 'error');
    }
  }

  async #rename(title) {
    const id = this.#activeId;
    if (id === null) {
      return;
    }
    try {
      await renameConversation(id, title);
      this.header.closeRename();
      await this.#pollOnce();
      this.messageList.scrollToBottom();
    } catch (error) {
      if (!isAbort(error)) {
        showToast(error.message, 'error');
      }
    }
  }

  // --- Navigation --------------------------------------------------------------------------------------------------------------

  #onPopState() {
    if (window.location.pathname.startsWith('/messages/new/')) {
      window.location.reload();

      return;
    }
    const { id } = parseRoute(window.location.pathname);
    if (id === null) {
      this.#close({ push: false });
    } else {
      this.#open(id, { push: false });
    }
  }
}

if (!customElements.get('rb-chat')) {
  customElements.define('rb-chat', RbChat);
}
