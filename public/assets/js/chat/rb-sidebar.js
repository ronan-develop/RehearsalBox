/**
 * <rb-sidebar> : liste des conversations et entrée « Archivées ». Composant de présentation : il reçoit les
 * conversations à afficher, émet sidebar:select { id } et sidebar:archives { on } ; ne connaît pas l'API.
 */
import { EVT, emit } from './events.js';
import { renderList } from './view.js';

export class RbSidebar extends HTMLElement {
  connectedCallback() {
    const $ = (selector) => this.querySelector(selector);
    this.listEl = $('[data-chat-list]');
    this.emptyEl = $('[data-chat-empty]');
    this.archivesBtn = $('[data-chat-archives]');
    this.archivesBadge = $('[data-chat-archives-unread]');
    this.leaveBtn = $('[data-chat-leave-archives]');
    this.homeLink = $('[data-chat-home]');
    this.titleEl = $('[data-chat-list-title]');

    this.addEventListener('click', (event) => {
      const link = event.target.closest('[data-conversation-id]');
      if (link && !(event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1)) {
        event.preventDefault();
        emit(this, EVT.SELECT, { id: link.dataset.conversationId });
        return;
      }
      if (event.target.closest('[data-chat-archives]')) {
        emit(this, EVT.ARCHIVES, { on: true });
      } else if (event.target.closest('[data-chat-leave-archives]')) {
        emit(this, EVT.ARCHIVES, { on: false });
      }
    });
  }

  setConversations(conversations, activeId, { archived = false } = {}) {
    renderList(this.listEl, conversations, activeId);
    this.emptyEl.hidden = conversations.length > 0;
    this.emptyEl.textContent = archived
      ? 'Aucune conversation archivée.'
      : 'Aucune conversation. Écrivez à un groupe depuis le planning ou depuis sa page.';
  }

  setArchivesUnread(count) {
    this.archivesBadge.textContent = String(count);
    this.archivesBadge.hidden = count === 0;
  }

  showArchives(on) {
    this.archivesBtn.hidden = on;
    this.leaveBtn.hidden = !on;
    this.homeLink.hidden = on;
    this.titleEl.textContent = on ? 'Archivées' : 'Messages';
  }
}

if (!customElements.get('rb-sidebar')) {
  customElements.define('rb-sidebar', RbSidebar);
}
