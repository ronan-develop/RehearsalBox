import { initParallax } from './parallax.js';
import { initAuth } from './auth.js';
import { initAvailability } from './availability.js';
import { initAdminSlots } from './admin-slots.js';
import { initAdminGroups } from './admin-groups.js';
import { initAdminUsers } from './admin-users.js';
import { initPlanningSlider, initExceptionalPlanningSlider } from './planning-slider.js';
import { initTornPaper } from './tornpaper-init.js';
import { initPlanningSearch } from './planning-search.js';
import { initScrollHint } from './scroll-hint.js';
import { initExceptionDeck, initExceptionTabs } from './exception-deck.js';
import { initContact } from './contact.js';
import { initMessages } from './messages.js';
import { initGroupDocuments } from './group-documents.js';
import { initGroupSpaceEditor } from './group-space.js';

document.addEventListener('DOMContentLoaded', () => {
  // Après le chargement de la police du watermark : la position de repos
  // (centrée par le CSS) dépend de la largeur réelle du texte.
  (document.fonts?.ready ?? Promise.resolve()).then(() => initParallax());
  initAuth();
  initAvailability();
  initAdminSlots();
  initAdminGroups();
  initAdminUsers();
  initPlanningSlider();
  initExceptionalPlanningSlider();
  initTornPaper();
  initPlanningSearch();
  initScrollHint();
  initExceptionDeck();
  initExceptionTabs();
  initContact();
  initMessages();
  initGroupDocuments();
  initGroupSpaceEditor();
});
