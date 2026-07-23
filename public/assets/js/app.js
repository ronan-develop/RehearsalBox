import { initAuth } from './auth.js';
import { initAvailability } from './availability.js';

document.addEventListener('DOMContentLoaded', () => {
  initAuth();
  initAvailability();
});
