/**
 * <rb-assembly-form> : le formulaire de compte rendu d'AG (#361). Rendu par le serveur (champs, sections de lignes, modèles
 * de ligne et boutons sont déjà dans le HTML) ; le composant ne parle jamais à l'API : il émet assembly:save { payload },
 * assembly:validate { payload, dirty } et assembly:delete {} au clic, et ajoute ou retire des lignes en clonant le modèle
 * <template data-row-template="…">. La page lit le résultat et rend les erreurs par showErrors / showError. Tout texte est posé
 * avec textContent, jamais en HTML. Cycle de vie : écouteurs posés à la connexion, retirés à la déconnexion.
 */
import { emit } from '../../messaging/chat/events.js';
import { buildPayload, locateFieldError } from './assembly-form.js';

export const SAVE_EVENT = 'assembly:save';
export const VALIDATE_EVENT = 'assembly:validate';
export const DELETE_EVENT = 'assembly:delete';

const HEADER_FIELDS = ['kind', 'heldOn', 'location', 'openedAt', 'closedAt', 'quorum', 'moralReport', 'financialReport', 'otherBusiness'];
const BOARD_POSTS = ['president', 'tresorier', 'secretaire'];
const ROW_FIELDS = {
  attendees: ['userId', 'name', 'attendance', 'proxyHolder'],
  agenda: ['title', 'discussion'],
  resolutions: ['text', 'for', 'against', 'abstain', 'outcome'],
};

export class RbAssemblyForm extends HTMLElement {
  #dirty = false;

  #onEdit = () => {
    this.#dirty = true;
  };

  #onClick = (event) => {
    const add = event.target.closest('[data-add-row]');
    if (add !== null && this.contains(add)) {
      this.#addRow(add.dataset.addRow);
      return;
    }

    const remove = event.target.closest('[data-remove-row]');
    if (remove !== null && this.contains(remove)) {
      remove.closest('[data-row]')?.remove();
      this.#dirty = true;
      return;
    }

    const button = event.target.closest('[data-action]');
    if (button !== null && this.contains(button) && !button.disabled && !this.hasAttribute('aria-busy')) {
      this.#act(button.dataset.action);
    }
  };

  connectedCallback() {
    this.addEventListener('input', this.#onEdit);
    this.addEventListener('change', this.#onEdit);
    this.addEventListener('click', this.#onClick);
  }

  disconnectedCallback() {
    this.removeEventListener('input', this.#onEdit);
    this.removeEventListener('change', this.#onEdit);
    this.removeEventListener('click', this.#onClick);
  }

  /** Vrai tant que des modifications ne sont pas enregistrées. */
  get dirty() {
    return this.#dirty;
  }

  /** Nouvelle révision après un enregistrement réussi : plus rien à enregistrer. */
  setRevision(revision) {
    this.dataset.revision = String(revision);
    this.#dirty = false;
  }

  setBusy(busy) {
    this.toggleAttribute('aria-busy', busy);
    this.querySelectorAll('[data-action]').forEach((button) => {
      button.disabled = busy;
    });
  }

  /** @param {Object<string, string>} fields clé pointée (voir locateFieldError) → message */
  showErrors(fields) {
    this.querySelectorAll('[data-field-error]').forEach((element) => this.#show(element, ''));
    for (const [key, message] of Object.entries(fields)) {
      const element = this.#locate(key);
      if (element !== null) {
        this.#show(element, message);
      } else {
        this.showError(message);
      }
    }
  }

  /** Message général ; une chaîne vide masque la zone. */
  showError(message) {
    this.#show(this.querySelector('[data-form-error]'), message);
  }

  #act(action) {
    if (action === 'save') {
      emit(this, SAVE_EVENT, { payload: this.#payload() });
    } else if (action === 'validate') {
      emit(this, VALIDATE_EVENT, { payload: this.#payload(), dirty: this.#dirty });
    } else if (action === 'delete') {
      emit(this, DELETE_EVENT, {});
    }
  }

  #addRow(section) {
    const template = this.querySelector(`template[data-row-template="${section}"]`);
    const container = this.querySelector(`[data-rows="${section}"]`);
    if (template === null || container === null) {
      return;
    }
    container.append(template.content.cloneNode(true));
    this.#dirty = true;
  }

  #payload() {
    const header = { revision: this.dataset.revision, board: {} };
    for (const name of HEADER_FIELDS) {
      header[name] = this.#value(name);
    }
    for (const post of BOARD_POSTS) {
      header.board[post] = this.#value(`board.${post}`);
    }

    const rows = {};
    for (const [section, fields] of Object.entries(ROW_FIELDS)) {
      rows[section] = [...this.querySelectorAll(`[data-rows="${section}"] [data-row]`)].map((row) => (
        Object.fromEntries(fields.map((field) => [field, row.querySelector(`[name="${field}"]`)?.value ?? '']))
      ));
    }

    return buildPayload(header, rows);
  }

  #value(name) {
    return this.querySelector(`[name="${name}"]`)?.value ?? '';
  }

  /** Élément d'erreur visé par une clé ; null quand il n'existe pas dans le formulaire. */
  #locate(key) {
    const target = locateFieldError(key);
    if (target === null) {
      return null;
    }

    const { section, index, field } = target;
    if (section === 'header') {
      return this.querySelector(`[data-field-error="${field}"]`);
    }
    if (section === 'board') {
      return this.querySelector(`[data-field-error="board.${field}"]`);
    }
    if (index === null) {
      return this.querySelector(`[data-field-error="${section}"]`);
    }

    const row = this.querySelector(`[data-rows="${section}"]`)?.querySelectorAll('[data-row]')[index];
    return field === null ? null : row?.querySelector(`[data-field-error="${field}"]`) ?? null;
  }

  #show(element, message) {
    if (element === null || element === undefined) {
      return;
    }
    element.textContent = message;
    element.hidden = message === '';
  }
}

if (!customElements.get('rb-assembly-form')) {
  customElements.define('rb-assembly-form', RbAssemblyForm);
}
