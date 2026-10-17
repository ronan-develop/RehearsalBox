<?php

declare(strict_types=1);

use App\Accounting\Controller\AccountingPageController;
use App\Accounting\Controller\Api\AccountingApiController;
use App\Accounting\Controller\Api\ImportApiController;
use App\Accounting\Controller\Api\RecurringApiController;
use App\Accounting\Controller\Api\StatementApiController;
use App\Accounting\Controller\CotisationPageController;
use App\Accounting\Controller\ImportPageController;
use App\Accounting\Controller\RecurringPageController;
use App\Accounting\Controller\ReportController;
use App\Accounting\Controller\StatementPageController;
use App\Association\Controller\Api\AssociationBoardApiController;
use App\Association\Controller\Api\AssociationTextApiController;
use App\Association\Controller\AssociationBoardPageController;
use App\Association\Controller\AssociationTextPageController;
use App\Assembly\Controller\Api\AssemblyApiController;
use App\Assembly\Controller\AssemblyPageController;
use App\Account\Controller\Api\AccountApiController;
use App\Account\Controller\Api\AccountDataApiController;
use App\Account\Controller\Api\AuthApiController;
use App\Planning\Controller\Api\AvailabilityApiController;
use App\Backup\Controller\RestoreController;
use App\Metrics\Controller\MetricsPageController;
use App\Operations\Controller\OpsPageController;
use App\Planning\Controller\AdminBookingPageController;
use App\Group\Controller\AdminGroupPageController;
use App\Planning\Controller\BookingPageController;
use App\Planning\Controller\ExchangePageController;
use App\Account\Controller\AdminUserPageController;
use App\Messaging\Controller\Api\ConversationApiController;
use App\Messaging\Controller\Api\DirectConversationApiController;
use App\Messaging\Controller\Api\ConversationFeedApiController;
use App\Messaging\Controller\Api\ConversationMuteApiController;
use App\Planning\Controller\Api\FreeSlotBookingApiController;
use App\Messaging\Controller\Api\ConversationTrashApiController;
use App\Messaging\Controller\Api\MemberApiController;
use App\Messaging\Controller\Api\MessageApiController;
use App\Group\Controller\Api\GroupApiController;
use App\Group\Controller\Api\UserGroupAdminApiController;
use App\Account\Controller\Api\UserAdminApiController;
use App\Group\Controller\Api\GroupDocumentApiController;
use App\Group\Controller\Api\GroupSpaceApiController;
use App\Account\Controller\Api\PasswordResetApiController;
use App\Planning\Controller\Api\PlanningFragmentApiController;
use App\Planning\Controller\Api\SlotApiController;
use App\Messaging\Controller\MessagesPageController;
use App\Controller\PageController;

return [
    'pages' => [
        ['GET', '/', [PageController::class, 'dashboard']],
        ['GET', '/login', [PageController::class, 'login']],
        ['GET', '/forgot-password', [PageController::class, 'forgotPassword']],
        ['GET', '/reset-password', [PageController::class, 'resetPassword']],
        ['GET', '/account/password', [PageController::class, 'accountPassword']],
        ['GET', '/account/secure', [PageController::class, 'secureAccount']],
        ['GET', '/account/email/confirm', [PageController::class, 'confirmEmail']],
        ['GET', '/admin', [AdminBookingPageController::class, 'home']],
        ['GET', '/admin/slots', [PageController::class, 'adminSlots']],
        ['GET', '/admin/groups', [AdminGroupPageController::class, 'index']],
        ['GET', '/admin/users', [AdminUserPageController::class, 'index']],
        ['GET', '/admin/bookings', [AdminBookingPageController::class, 'index']],
        ['GET', '/admin/assemblies', [AssemblyPageController::class, 'index']],
        ['GET', '/admin/assemblies/{id}', [AssemblyPageController::class, 'edit']],
        ['GET', '/admin/assemblies/{id}/print', [AssemblyPageController::class, 'print']],
        ['GET', '/admin/accounting', [AccountingPageController::class, 'index']],
        ['GET', '/admin/accounting/recurring', [RecurringPageController::class, 'index']],
        ['GET', '/admin/accounting/cotisations', [CotisationPageController::class, 'index']],
        ['GET', '/admin/accounting/imports', [ImportPageController::class, 'index']],
        ['GET', '/admin/accounting/rules', [ImportPageController::class, 'rules']],
        ['GET', '/admin/accounting/years/{id}/report', [ReportController::class, 'report']],
        ['GET', '/admin/accounting/years/{id}/export.csv', [ReportController::class, 'csv']],
        ['GET', '/admin/accounting/statements', [StatementPageController::class, 'index']],
        ['GET', '/admin/accounting/statements/{id}/file', [StatementPageController::class, 'download']],
        ['GET', '/admin/board', [AssociationBoardPageController::class, 'index']],
        ['GET', '/admin/texts', [AssociationTextPageController::class, 'index']],
        ['GET', '/admin/texts/{id}', [AssociationTextPageController::class, 'show']],
        ['GET', '/admin/restore', [RestoreController::class, 'page']],
        ['GET', '/admin/ops', [OpsPageController::class, 'backups']],
        ['GET', '/admin/ops/production', [OpsPageController::class, 'production']],
        ['GET', '/admin/metrics', [MetricsPageController::class, 'index']],
        ['GET', '/admin/metrics/security', [MetricsPageController::class, 'security']],
        ['GET', '/admin/metrics/load', [MetricsPageController::class, 'load']],
        ['GET', '/bookings', [BookingPageController::class, 'index']],
        ['GET', '/exchanges/{id}', [ExchangePageController::class, 'show']],
        ['GET', '/groups/{slug}/space', [PageController::class, 'groupSpace']],
        ['GET', '/messages', [MessagesPageController::class, 'list']],
        ['GET', '/messages/archives', [MessagesPageController::class, 'archives']],
        ['GET', '/messages/trash', [MessagesPageController::class, 'trash']],
        ['GET', '/messages/new/{groupId}', [MessagesPageController::class, 'compose']],
        ['GET', '/messages/direct', [MessagesPageController::class, 'direct']],
        ['GET', '/messages/direct/{userId}', [MessagesPageController::class, 'directCompose']],
        ['GET', '/messages/{id}', [MessagesPageController::class, 'show']],
    ],
    'api' => [
        ['POST', '/api/auth/login', [AuthApiController::class, 'login']],
        ['POST', '/api/auth/forgot-password', [PasswordResetApiController::class, 'forgotPassword']],
        ['POST', '/api/auth/reset-password', [PasswordResetApiController::class, 'resetPassword']],
        ['PATCH', '/api/account/profile', [AccountApiController::class, 'updateProfile']],
        ['PATCH', '/api/account/email', [AccountApiController::class, 'requestEmailChange']],
        ['POST', '/api/account/email/confirm', [AccountApiController::class, 'confirmEmailChange']],
        ['POST', '/api/auth/change-password', [AccountApiController::class, 'changePassword']],
        ['GET',  '/api/account/export', [AccountDataApiController::class, 'export']],
        ['DELETE', '/api/account', [AccountDataApiController::class, 'delete']],
        ['POST', '/api/auth/secure-account', [AccountApiController::class, 'secureAccount']],
        ['POST', '/api/auth/logout', [AuthApiController::class, 'logout']],
        ['POST', '/api/auth/select-group', [AuthApiController::class, 'selectGroup']],
        ['POST', '/api/availability', [AvailabilityApiController::class, 'store']],
        ['GET',  '/api/availability/pending/{groupId}', [AvailabilityApiController::class, 'pendingForGroup']],
        ['GET',  '/api/availability/requested/{groupId}', [AvailabilityApiController::class, 'requestedByGroup']],
        ['POST', '/api/availability/{exceptionId}/respond', [AvailabilityApiController::class, 'respond']],
        ['PATCH', '/api/availability/{exceptionId}', [AvailabilityApiController::class, 'update']],
        ['DELETE', '/api/availability/{exceptionId}', [AvailabilityApiController::class, 'destroy']],
        ['GET',    '/api/planning/exceptional', [PlanningFragmentApiController::class, 'exceptional']],
        ['GET',    '/api/admin/slots', [SlotApiController::class, 'index']],
        ['POST',   '/api/admin/slots', [SlotApiController::class, 'store']],
        ['PATCH',  '/api/admin/slots/{id}', [SlotApiController::class, 'update']],
        ['DELETE', '/api/admin/slots/{id}', [SlotApiController::class, 'destroy']],
        ['GET',    '/api/admin/groups', [GroupApiController::class, 'index']],
        ['POST',   '/api/admin/groups', [GroupApiController::class, 'store']],
        ['PATCH',  '/api/admin/groups/{id}', [GroupApiController::class, 'update']],
        ['DELETE', '/api/admin/groups/{id}', [GroupApiController::class, 'destroy']],
        ['GET',    '/api/bookings', [FreeSlotBookingApiController::class, 'index']],
        ['GET',    '/api/bookings/plan', [FreeSlotBookingApiController::class, 'plan']],
        ['POST',   '/api/bookings', [FreeSlotBookingApiController::class, 'store']],
        ['DELETE', '/api/bookings/{id}', [FreeSlotBookingApiController::class, 'destroy']],
        ['GET',    '/api/admin/bookings', [FreeSlotBookingApiController::class, 'pending']],
        ['POST',   '/api/admin/bookings/{id}/approve', [FreeSlotBookingApiController::class, 'approve']],
        ['POST',   '/api/admin/bookings/{id}/refuse', [FreeSlotBookingApiController::class, 'refuse']],
        ['POST',   '/api/admin/assemblies', [AssemblyApiController::class, 'store']],
        ['PUT',    '/api/admin/assemblies/{id}', [AssemblyApiController::class, 'update']],
        ['POST',   '/api/admin/assemblies/{id}/validate', [AssemblyApiController::class, 'validate']],
        ['POST',   '/api/admin/assemblies/{id}/corrections', [AssemblyApiController::class, 'correct']],
        ['DELETE', '/api/admin/assemblies/{id}', [AssemblyApiController::class, 'destroy']],
        ['POST',   '/api/admin/accounting/years', [AccountingApiController::class, 'createYear']],
        ['POST',   '/api/admin/accounting/statements', [StatementApiController::class, 'store']],
        ['GET',    '/api/admin/accounting/figures', [ReportController::class, 'figures']],
        ['POST',   '/api/admin/accounting/cotisations/exemptions', [AccountingApiController::class, 'setExemption']],
        ['POST',   '/api/admin/accounting/imports/preview', [ImportApiController::class, 'preview']],
        ['POST',   '/api/admin/accounting/imports', [ImportApiController::class, 'stage']],
        ['POST',   '/api/admin/accounting/imports/validate', [ImportApiController::class, 'validate']],
        ['POST',   '/api/admin/accounting/imports/lines/{id}/discard', [ImportApiController::class, 'discard']],
        ['PUT',    '/api/admin/accounting/rules/{id}', [ImportApiController::class, 'updateRule']],
        ['DELETE', '/api/admin/accounting/rules/{id}', [ImportApiController::class, 'deleteRule']],
        ['POST',   '/api/admin/accounting/recurring', [RecurringApiController::class, 'create']],
        ['PUT',    '/api/admin/accounting/recurring/{id}', [RecurringApiController::class, 'update']],
        ['POST',   '/api/admin/accounting/recurring/{id}/confirm', [RecurringApiController::class, 'confirm']],
        ['POST',   '/api/admin/accounting/recurring/{id}/ignore', [RecurringApiController::class, 'ignore']],
        ['POST',   '/api/admin/accounting/years/{id}/entries', [AccountingApiController::class, 'recordEntry']],
        ['POST',   '/api/admin/accounting/years/{id}/empty-month', [AccountingApiController::class, 'markEmptyMonth']],
        ['POST',   '/api/admin/accounting/years/{id}/close', [AccountingApiController::class, 'closeYear']],
        ['POST',   '/api/admin/accounting/years/{id}/approve', [AccountingApiController::class, 'approveYear']],
        ['POST',   '/api/admin/accounting/entries/{id}/reverse', [AccountingApiController::class, 'reverseEntry']],
        ['PUT',    '/api/admin/board/{post}', [AssociationBoardApiController::class, 'assign']],
        ['DELETE', '/api/admin/board/{post}', [AssociationBoardApiController::class, 'vacate']],
        ['POST',   '/api/admin/texts', [AssociationTextApiController::class, 'store']],
        ['PUT',    '/api/admin/texts/{id}', [AssociationTextApiController::class, 'update']],
        ['DELETE', '/api/admin/texts/{id}', [AssociationTextApiController::class, 'destroy']],
        ['GET',    '/api/admin/users', [UserAdminApiController::class, 'index']],
        ['POST',   '/api/admin/users', [UserAdminApiController::class, 'store']],
        ['PATCH',  '/api/admin/users/{id}', [UserAdminApiController::class, 'update']],
        ['POST',   '/api/admin/users/{id}/unlock', [UserAdminApiController::class, 'unlock']],
        ['PUT',    '/api/admin/users/{id}/identity', [UserAdminApiController::class, 'updateIdentity']],
        ['PUT',    '/api/admin/users/{id}/role', [UserAdminApiController::class, 'updateRole']],
        ['PUT',    '/api/admin/users/{id}/groups/{groupId}', [UserGroupAdminApiController::class, 'set']],
        ['DELETE', '/api/admin/users/{id}/groups/{groupId}', [UserGroupAdminApiController::class, 'remove']],
        ['POST',   '/api/admin/users/{id}/groups/{groupId}/move', [UserGroupAdminApiController::class, 'move']],
        ['POST',   '/api/admin/restore', [RestoreController::class, 'start']],
        ['POST',   '/api/admin/groups/{id}/members', [GroupApiController::class, 'addMember']],
        ['DELETE', '/api/admin/groups/{id}/members/{userId}', [GroupApiController::class, 'removeMember']],
        ['GET',    '/api/conversations', [ConversationFeedApiController::class, 'index']],
        ['POST',   '/api/conversations', [ConversationApiController::class, 'start']],
        ['POST',   '/api/conversations/direct', [DirectConversationApiController::class, 'start']],
        ['GET',    '/api/members', [MemberApiController::class, 'search']],
        ['GET',    '/api/conversation-list', [ConversationFeedApiController::class, 'listFragment']],
        ['GET',    '/api/conversations/{id}/updates', [ConversationFeedApiController::class, 'updates']],
        ['PATCH',  '/api/conversations/{id}', [ConversationApiController::class, 'rename']],
        ['DELETE', '/api/conversations/{id}', [ConversationTrashApiController::class, 'destroy']],
        ['PUT',    '/api/conversations/{id}/mute', [ConversationMuteApiController::class, 'mute']],
        ['DELETE', '/api/conversations/{id}/mute', [ConversationMuteApiController::class, 'unmute']],
        ['POST',   '/api/conversations/{id}/restore', [ConversationTrashApiController::class, 'restore']],
        ['DELETE', '/api/conversations/{id}/permanent', [ConversationTrashApiController::class, 'destroyPermanently']],
        ['DELETE', '/api/conversations/{id}/guests/{userId}', [ConversationApiController::class, 'removeGuest']],
        ['POST',   '/api/conversation-alerts/{id}/dismiss', [ConversationTrashApiController::class, 'dismissAlert']],
        ['POST',   '/api/conversations/{id}/typing', [ConversationFeedApiController::class, 'typing']],
        ['POST',   '/api/conversations/{id}/messages', [ConversationApiController::class, 'reply']],
        ['PATCH',  '/api/conversations/{id}/messages/{messageId}', [MessageApiController::class, 'edit']],
        ['GET',    '/api/groups/{id}/space', [GroupSpaceApiController::class, 'show']],
        ['PATCH',  '/api/groups/{id}/space', [GroupSpaceApiController::class, 'updateProfile']],
        ['POST',   '/api/groups/{id}/documents', [GroupDocumentApiController::class, 'store']],
        ['GET',    '/api/groups/{id}/documents', [GroupDocumentApiController::class, 'index']],
        ['GET',    '/api/documents/{id}', [GroupDocumentApiController::class, 'download']],
        ['DELETE', '/api/documents/{id}', [GroupDocumentApiController::class, 'destroy']],
    ],
];
