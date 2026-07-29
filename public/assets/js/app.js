import { initAuth } from './auth.js';
import { initAvailability } from './availability.js';
import { initAdminSlots } from './admin-slots.js';
import { initAdminGroups } from './admin-groups.js';
import { initPlanningSlider, initExceptionalPlanningSlider } from './planning-slider.js';
import { initExceptionDeck } from './exception-deck.js';
import { initContact } from './contact.js';
import { initGroupDocuments } from './group-documents.js';
import { initGroupSpaceEditor } from './group-space.js';

document.addEventListener('DOMContentLoaded', () => {
  initAuth();
  initAvailability();
  initAdminSlots();
  initAdminGroups();
  initPlanningSlider();
  initExceptionalPlanningSlider();
  initExceptionDeck();
  initContact();
  initGroupDocuments();
  initGroupSpaceEditor();
});
