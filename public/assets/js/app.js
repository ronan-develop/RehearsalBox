import './core/rb-async-form.js';
import './ui/rb-tabs.js';
import { initLogoMigration } from './ui/logo-migration.js';
import { initAuth } from './account/auth.js';
import { initAvailability } from './planning/availability.js';
import { initAdminSlots } from './planning/admin-slots.js';
import { initAdminGroups } from './group/admin-groups.js';
import { initAdminUsers } from './account/admin-users.js';
import { initAdminBookings } from './planning/booking/admin-bookings.js';
import { initBookingsBadge } from './planning/booking/bookings-badge.js';
import { initBookings } from './planning/booking/bookings.js';
import { initTornPaper } from './ui/tornpaper-init.js';
import { initPlanningSearch } from './planning/dashboard/planning-search.js';
import { initScrollHint } from './ui/scroll-hint.js';
import { initExceptionDeck } from './planning/dashboard/exception-deck.js';
import { initContact } from './planning/contact.js';
import './messaging/chat/components/rb-chat.js';
import { initMessagesBadge } from './messaging/messages-badge.js';
import { initMessagesTrash } from './messaging/messages-trash.js';
import { initSwipeDelete } from './messaging/chat/thread/swipe-delete.js';
import { initGroupDocuments } from './group/group-documents.js';
import { initGroupSpaceEditor } from './group/group-space.js';

document.addEventListener('DOMContentLoaded', () => {
  // Après le chargement de la police du watermark : la position de repos
  // (centrée par le CSS) dépend de la largeur réelle du texte.
  (document.fonts?.ready ?? Promise.resolve()).then(() => initLogoMigration());
  initAuth();
  initAvailability();
  initAdminSlots();
  initAdminGroups();
  initAdminUsers();
  initAdminBookings();
  initBookingsBadge();
  initBookings();
  initTornPaper();
  initPlanningSearch();
  initScrollHint();
  initExceptionDeck();
  initContact();
  initMessagesTrash();
  initSwipeDelete();
  initMessagesBadge();
  initGroupDocuments();
  initGroupSpaceEditor();
});
