/**
 * <rb-composer> : zone de saisie (champ + envoi). Entrée envoie, Maj+Entrée = retour à la ligne, le champ grandit avec le
 * texte. Émet composer:submit { text, mentions, replyTo } et composer:typing (limité à un signal toutes les 3 s). Ne connaît
 * ni l'API ni la conversation : le même composant sert à un fil existant et à un brouillon.
 *
 * Il orchestre trois pièces, chacune dans son module : les suggestions de mentions (mention-picker.js, #178), le brouillon
 * conservé (composer-drafts.js, #187) et l'aperçu de la citation (composer-quote.js, #214). Il garde la saisie, l'envoi et le
 * mode correction (#200).
 */
import { EVT, emit } from './events.js';
import { shouldSendTyping } from './model.js';
import { mentionedIds, pendingGuests } from './mentions.js';
import { MentionPicker } from './mention-picker.js';
import { ComposerDrafts } from './composer-drafts.js';
import { ComposerQuote } from './composer-quote.js';

export class RbComposer extends HTMLElement {
  #lastTypingSent = null;
  #picks = new Map();
  #editing = null; // identifiant du message en cours de correction (#200), null sinon
  #stashed = ''; // texte de la saisie en cours, mis de côté pendant la correction
  #picker = null;
  #drafts = null;
  #quote = null;

  /** Fournie par <rb-chat> : (requête) => Promise<[{ id, name, groups, participant }]>. */
  suggest = null;

  connectedCallback() {
    this.form = this.querySelector('form');
    this.field = this.form.elements.message;
    this.notice = this.querySelector('[data-mention-notice]');
    this.banner = this.querySelector('[data-composer-edit]');
    this.querySelector('[data-composer-edit-cancel]')?.addEventListener('click', () => this.cancelEdit());

    this.#drafts = new ComposerDrafts({ field: this.field, isSuspended: () => this.#editing !== null });
    this.#quote = new ComposerQuote(this.querySelector('[data-composer-quote]'), () => this.cancelQuote());
    this.#picker = new MentionPicker({
      field: this.field,
      list: this.querySelector('[data-mention-list]'),
      suggest: () => this.suggest,
      onPick: (member) => {
        this.#picks.set(member.id, { name: member.name, participant: member.participant });
        this.#autosize();
        this.#refreshNotice();
      },
    });

    this.form.addEventListener('submit', (event) => {
      event.preventDefault();
      this.#send();
    });
    this.field.addEventListener('keydown', (event) => {
      if (this.#picker.handleKey(event)) {
        return;
      }
      if (event.key === 'Escape' && this.#editing !== null) {
        event.preventDefault();
        this.cancelEdit();

        return;
      }
      if (event.key === 'Escape' && this.#quote.active) {
        event.preventDefault();
        this.cancelQuote();

        return;
      }
      if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        this.#send();
      }
    });
    this.field.addEventListener('input', () => {
      this.#autosize();
      if (this.field.value.trim() !== '' && shouldSendTyping(this.#lastTypingSent, Date.now())) {
        this.#lastTypingSent = Date.now();
        emit(this, EVT.TYPING);
      }
      this.#refreshNotice();
      this.#picker.onInput();
      this.#drafts.schedule();
    });
    this.field.addEventListener('blur', () => this.#picker.close());
    // On quitte la page ou l'onglet passe en arrière-plan : le brouillon est écrit tout de suite, sans attendre le délai.
    window.addEventListener('pagehide', () => this.#drafts.flush());
    document.addEventListener('visibilitychange', () => document.visibilityState === 'hidden' && this.#drafts.flush());
  }

  /**
   * Brouillon conservé dans le navigateur (#187) : restauré maintenant, réécrit à chaque saisie, effacé à l'envoi.
   * `drafts` : createDraftStore(...) ; `key` : une clé par conversation (ou par brouillon de page de démarrage).
   */
  configureDrafts(drafts, key) {
    this.#drafts.configure(drafts, key, () => this.#autosize());
  }

  /**
   * Citer un message (#214) : un aperçu (auteur et début du texte) apparaît au-dessus de la saisie, avec une croix pour le
   * retirer. Le brouillon n'en garde pas trace. Ignoré pendant la correction d'un message (on ne répond pas à ce moment-là).
   */
  startQuote({ id, author, text }) {
    if (this.#editing !== null) {
      return;
    }
    this.#quote.show({ id, author, text });
    this.field.focus();
  }

  cancelQuote() {
    this.#quote.clear();
    this.field.focus({ preventScroll: true });
  }

  /**
   * Correction d'un message (#200) : la saisie reçoit son texte, un bandeau le rappelle et permet d'annuler. La saisie en
   * cours et son brouillon sont mis de côté et reviennent à la fin ; le brouillon n'est pas écrit pendant la correction.
   * Une citation en attente est retirée : corriger n'est pas répondre.
   */
  startEdit({ id, text }) {
    if (this.#editing === null) {
      this.#stashed = this.field.value;
    }
    this.#quote.clear();
    this.#editing = String(id);
    this.field.value = text;
    this.#autosize();
    this.#picker.close();
    this.#refreshNotice();
    if (this.banner) {
      this.banner.hidden = false;
    }
    this.field.focus();
    this.field.setSelectionRange(text.length, text.length);
  }

  /** Correction terminée ou annulée : la saisie d'avant revient. */
  finishEdit() {
    this.#editing = null;
    this.field.value = this.#stashed;
    this.#stashed = '';
    this.#autosize();
    this.#refreshNotice();
    this.#drafts.flush(); // la saisie d'avant, mise de côté, redevient un brouillon conservé
    if (this.banner) {
      this.banner.hidden = true;
    }
    this.field.focus({ preventScroll: true });
  }

  cancelEdit() {
    if (this.#editing !== null) {
      this.finishEdit();
    }
  }

  /** Remet le texte (et la citation) quand l'envoi a échoué, pour que rien ne soit perdu. */
  restore(text, quote = null) {
    this.field.value = text;
    if (quote !== null) {
      this.#quote.show(quote);
    }
    this.#autosize();
    this.#drafts.flush();
    this.field.focus();
  }

  focus() {
    this.field?.focus();
  }

  #send() {
    const text = this.field.value.trim();
    if (text === '') {
      return;
    }
    const mentions = mentionedIds(text, this.#picks);
    if (this.#editing !== null) {
      // Correction : le texte reste dans le champ tant que le serveur n'a pas répondu (rien n'est perdu en cas d'échec).
      emit(this, EVT.EDIT, { id: this.#editing, text, mentions });

      return;
    }
    const quote = this.#quote.snapshot;
    this.field.value = '';
    this.#quote.clear();
    this.#autosize();
    this.#picker.close();
    this.#refreshNotice();
    this.#drafts.flush(); // champ vidé : le brouillon est effacé (réécrit par restore() si l'envoi échoue)
    emit(this, EVT.SUBMIT, { text, mentions, replyTo: quote === null ? null : Number(quote.id), quote });
    // Le champ garde le focus après l'envoi (bouton ou touche) : sur mobile le clavier reste ouvert pour enchaîner.
    this.field.focus({ preventScroll: true });
  }

  /** Prévient avant l'envoi : une personne extérieure à la conversation y sera ajoutée. */
  #refreshNotice() {
    if (!this.notice) {
      return;
    }
    const names = pendingGuests(this.field.value, this.#picks);
    this.notice.hidden = names.length === 0;
    this.notice.textContent = names.length === 0
      ? ''
      : `${names.join(', ')} ${names.length === 1 ? "n'est pas dans cette conversation : elle y aura accès." : "ne sont pas dans cette conversation : elles y auront accès."}`;
  }

  #autosize() {
    this.field.style.height = 'auto';
    this.field.style.height = `${Math.min(this.field.scrollHeight, 140)}px`;
  }
}

if (!customElements.get('rb-composer')) {
  customElements.define('rb-composer', RbComposer);
}
