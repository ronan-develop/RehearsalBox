/**
 * <rb-chat> : contrôleur du direct de la messagerie (#169, #181, #183). Les pages sont rendues par le serveur et la
 * navigation est de la navigation classique (vrais liens) : ce contrôleur n'ajoute que ce qui doit vivre sans recharger
 * la page — recevoir les nouveaux messages (polling), envoyer, signaler qu'on écrit, renommer. Il écoute les événements
 * des composants (<rb-thread-header>, <rb-composer>) et ajoute les fragments HTML rendus par le serveur à <rb-message-list>.
 * Boucles async/await annulables d'un seul AbortController, en pause quand l'onglet est caché ; les opérations qui
 * touchent au fil sont sérialisées pour qu'une réponse et un polling ne s'entrecroisent jamais.
 */
import './rb-sidebar.js';
import './rb-thread-header.js';
import './rb-message-list.js';
import './rb-composer.js';
import {
  fetchUpdates, sendMessage, renameConversation, sendTyping, startConversation, searchMembers, editMessage, setMute,
} from './api.js';
import { isAbort, sleep, whenVisible } from './async.js';
import { EVT } from './events.js';
import { browserStorage, createDraftStore } from './drafts.js';
import { nextPollDelay } from './model.js';
import { showToast } from '../toast.js';

export class RbChat extends HTMLElement {
  #lifetime = new AbortController();
  #activeId = null;
  #draftTargetId = null;
  #lastId = 0;
  #editedAt = 0; // curseur des corrections déjà reçues (secondes Unix)
  #idle = 0;
  #chain = Promise.resolve();

  connectedCallback() {
    this.sidebar = this.querySelector('rb-sidebar');
    this.header = this.querySelector('rb-thread-header');
    this.messageList = this.querySelector('rb-message-list');
    this.composer = this.querySelector('rb-composer');
    this.statusEl = this.querySelector('[data-chat-status]');

    this.#activeId = this.dataset.activeId || null;
    this.#draftTargetId = this.dataset.draftTargetId || null;
    this.#lastId = Number(this.dataset.lastId || 0);
    this.#editedAt = Number(this.dataset.editedAt || 0);

    this.addEventListener(EVT.EDIT_REQUEST, (event) => this.composer.startEdit(event.detail));
    this.addEventListener(EVT.QUOTE_REQUEST, (event) => this.composer.startQuote(event.detail));
    this.addEventListener(EVT.MUTE_REQUEST, (event) => this.#mute(event));
    this.addEventListener(EVT.EDIT, (event) => this.#serial(() => this.#edit(event.detail)));
    this.addEventListener(EVT.RENAME, (event) => this.#serial(() => this.#rename(event.detail.title)));
    this.addEventListener(EVT.SUBMIT, (event) => this.#serial(() => this.#submit(event.detail)));
    this.composer.suggest = (query) => this.#suggestMembers(query);
    // Brouillon conservé par utilisateur et par conversation (ou par page de démarrage), jamais envoyé avant l'envoi.
    this.composer.configureDrafts(
      createDraftStore(browserStorage(), { userId: this.dataset.userId ?? '0' }),
      this.#activeId !== null ? this.#activeId : `new-${this.#draftTargetId ?? 'inconnu'}`,
    );
    this.addEventListener(EVT.TYPING, () => {
      if (this.#activeId !== null) {
        sendTyping(this.#activeId).catch(() => {});
      }
    });

    if (this.#draftTargetId !== null) {
      this.header.setDraft(true);
      this.composer.focus();
    } else if (this.#activeId !== null) {
      if (!this.messageList.scrollToUnread()) {
        this.messageList.scrollToBottom();
      }
      this.#pollLoop(this.#lifetime.signal);
    }
  }

  disconnectedCallback() {
    this.#lifetime.abort();
  }

  /** Une seule opération sur le fil à la fois (envoi, renommage, polling) : jamais de doublon de message. */
  #serial(task) {
    const run = this.#chain.then(task, task);
    this.#chain = run.catch(() => {});

    return run;
  }

  async #pollLoop(signal) {
    while (!signal.aborted) {
      try {
        await sleep(nextPollDelay(this.#idle), signal);
        await whenVisible(document, signal);
        await this.#serial(() => this.#pollOnce(signal));
      } catch (error) {
        if (isAbort(error)) {
          return;
        }
        this.#idle += 1;
      }
    }
  }

  /** Une lecture incrémentale : nouveaux messages, qui écrit, « vu par », titre éventuellement renommé. */
  async #pollOnce(signal) {
    this.#apply(await fetchUpdates(this.#activeId, this.#lastId, { signal, editedAfter: this.#editedAt }));
  }

  /** Corrections reçues (les miennes ou celles des autres) : le corps des bulles est remplacé, le curseur avance. */
  #applyEdits({ edited = [], editedAt = 0 }) {
    edited.forEach(({ id, html }) => this.messageList.replaceBody(id, html));
    this.#editedAt = Math.max(this.#editedAt, editedAt);
  }

  /** Sourdine (#210) : l'appel est indépendant du fil (pas de file d'attente) ; le serveur confirme, la cloche affiche l'état reçu. */
  async #mute(event) {
    const toggle = event.target.closest('rb-mute-toggle');
    const { id, muted } = event.detail;
    try {
      await setMute(id, muted);
      if (toggle !== null) {
        toggle.muted = muted;
      }
      this.sidebar.refresh().catch(() => {});
    } catch (error) {
      toggle?.release();
      showToast(error.message, 'error');
    }
  }

  /** Corrige un de mes messages : la saisie garde son texte tant que le serveur n'a pas répondu. */
  async #edit({ id, text, mentions }) {
    if (this.#activeId === null) {
      return;
    }
    try {
      this.#applyEdits(await editMessage(this.#activeId, id, text, mentions));
      this.composer.finishEdit();
    } catch (error) {
      showToast(error.message, 'error');
    }
  }

  /** Applique une réponse du serveur : ajoute les nouveaux messages dessinés par PHP et met à jour l'état. */
  #apply(update) {
    this.#applyEdits(update);
    const stick = this.messageList.nearBottom();
    this.messageList.append(update.html);
    this.#lastId = Math.max(this.#lastId, update.lastId);
    this.header.setThread(update);
    this.statusEl.textContent = update.status;
    this.statusEl.classList.toggle('rb-chat-status--typing', update.typing);
    if (update.hasNew) {
      this.#idle = 0;
      if (stick) {
        this.messageList.scrollToBottom();
      } else {
        this.messageList.showNewMessagesHint();
      }
      this.sidebar.refresh().catch(() => {});
    } else {
      this.#idle += 1;
    }
  }

  /** Liste après « @ » : le contexte est la conversation ouverte, ou les deux groupes d'un brouillon. */
  async #suggestMembers(query) {
    if (this.#activeId === null && this.#draftTargetId === null) {
      return [];
    }
    const context = this.#activeId !== null
      ? { conversation: this.#activeId }
      : { groupId: this.header.senderId, targetGroupId: this.#draftTargetId };
    const { members } = await searchMembers({ query, ...context });

    return members;
  }

  /** @param {{ text: string, mentions?: number[], replyTo?: number | null, quote?: object | null }} message */
  async #submit({ text, mentions = [], replyTo = null, quote = null }) {
    if (this.#draftTargetId !== null) {
      await this.#submitDraft(text, mentions);
      return;
    }
    if (this.#activeId === null) {
      return;
    }
    try {
      this.#apply(await sendMessage(this.#activeId, text, this.#lastId, mentions, replyTo));
      this.messageList.scrollToBottom();
      this.#idle = 0;
    } catch (error) {
      this.composer.restore(text, quote);
      showToast(error.message, 'error');
    }
  }

  /** Premier message d'un brouillon : la conversation est créée, puis navigation classique vers sa page. */
  async #submitDraft(text, mentions = []) {
    try {
      const { id } = await startConversation({
        groupId: this.header.senderId,
        targetGroupId: this.#draftTargetId,
        message: text,
        mentions,
      });
      window.location.assign(`/messages/${id}`);
    } catch (error) {
      this.composer.restore(text);
      showToast(error.message, 'error');
    }
  }

  async #rename(title) {
    if (this.#activeId === null) {
      return;
    }
    try {
      await renameConversation(this.#activeId, title);
      this.header.closeRename();
      await this.#pollOnce();
      this.messageList.scrollToBottom();
    } catch (error) {
      showToast(error.message, 'error');
    }
  }
}

if (!customElements.get('rb-chat')) {
  customElements.define('rb-chat', RbChat);
}
