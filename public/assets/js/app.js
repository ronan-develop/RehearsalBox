import { initAuth } from './auth.js';
import { initAvailability } from './availability.js';
import { initAdminSlots } from './admin-slots.js';
import { initAdminGroups } from './admin-groups.js';
import { initPlanningSlider } from './planning-slider.js';
import { initExceptionDeck } from './exception-deck.js';
import { initContact } from './contact.js';

document.addEventListener('DOMContentLoaded', () => {
  initAuth();
  initAvailability();
  initAdminSlots();
  initAdminGroups();
  initPlanningSlider();
  initExceptionDeck();
  initContact();
});
