<?php

declare(strict_types=1);

use App\Controller\Api\AccountApiController;
use App\Controller\Api\AuthApiController;
use App\Controller\Api\AvailabilityApiController;
use App\Controller\AdminGroupPageController;
use App\Controller\AdminUserPageController;
use App\Controller\Api\ConversationApiController;
use App\Controller\Api\ConversationFeedApiController;
use App\Controller\Api\ConversationTrashApiController;
use App\Controller\Api\MemberApiController;
use App\Controller\Api\MessageApiController;
use App\Controller\Api\GroupApiController;
use App\Controller\Api\UserAdminApiController;
use App\Controller\Api\GroupDocumentApiController;
use App\Controller\Api\GroupSpaceApiController;
use App\Controller\Api\PasswordResetApiController;
use App\Controller\Api\SlotApiController;
use App\Controller\MessagesPageController;
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
        ['GET', '/admin/slots', [PageController::class, 'adminSlots']],
        ['GET', '/admin/groups', [AdminGroupPageController::class, 'index']],
        ['GET', '/admin/users', [AdminUserPageController::class, 'index']],
        ['GET', '/groups/{slug}/space', [PageController::class, 'groupSpace']],
        ['GET', '/messages', [MessagesPageController::class, 'list']],
        ['GET', '/messages/archives', [MessagesPageController::class, 'archives']],
        ['GET', '/messages/trash', [MessagesPageController::class, 'trash']],
        ['GET', '/messages/new/{groupId}', [MessagesPageController::class, 'compose']],
        ['GET', '/messages/{id}', [MessagesPageController::class, 'show']],
    ],
    'api' => [
        ['POST', '/api/auth/login', [AuthApiController::class, 'login']],
        ['POST', '/api/auth/forgot-password', [PasswordResetApiController::class, 'forgotPassword']],
        ['POST', '/api/auth/reset-password', [PasswordResetApiController::class, 'resetPassword']],
        ['PATCH', '/api/account/profile', [AccountApiController::class, 'updateProfile']],
        ['PATCH', '/api/account/notifications', [AccountApiController::class, 'updateNotifications']],
        ['PATCH', '/api/account/email', [AccountApiController::class, 'requestEmailChange']],
        ['POST', '/api/account/email/confirm', [AccountApiController::class, 'confirmEmailChange']],
        ['POST', '/api/auth/change-password', [AccountApiController::class, 'changePassword']],
        ['POST', '/api/auth/secure-account', [AccountApiController::class, 'secureAccount']],
        ['POST', '/api/auth/logout', [AuthApiController::class, 'logout']],
        ['POST', '/api/auth/select-group', [AuthApiController::class, 'selectGroup']],
        ['GET',  '/api/availability/pending/{groupId}', [AvailabilityApiController::class, 'pendingForGroup']],
        ['GET',  '/api/availability/requested/{groupId}', [AvailabilityApiController::class, 'requestedByGroup']],
        ['POST', '/api/availability/{exceptionId}/respond', [AvailabilityApiController::class, 'respond']],
        ['PATCH', '/api/availability/{exceptionId}', [AvailabilityApiController::class, 'update']],
        ['DELETE', '/api/availability/{exceptionId}', [AvailabilityApiController::class, 'destroy']],
        ['GET',    '/api/planning', [SlotApiController::class, 'planning']],
        ['GET',    '/api/admin/slots', [SlotApiController::class, 'index']],
        ['POST',   '/api/admin/slots', [SlotApiController::class, 'store']],
        ['PATCH',  '/api/admin/slots/{id}', [SlotApiController::class, 'update']],
        ['DELETE', '/api/admin/slots/{id}', [SlotApiController::class, 'destroy']],
        ['GET',    '/api/admin/groups', [GroupApiController::class, 'index']],
        ['POST',   '/api/admin/groups', [GroupApiController::class, 'store']],
        ['PATCH',  '/api/admin/groups/{id}', [GroupApiController::class, 'update']],
        ['DELETE', '/api/admin/groups/{id}', [GroupApiController::class, 'destroy']],
        ['GET',    '/api/admin/users', [UserAdminApiController::class, 'index']],
        ['POST',   '/api/admin/users', [UserAdminApiController::class, 'store']],
        ['PATCH',  '/api/admin/users/{id}', [UserAdminApiController::class, 'update']],
        ['POST',   '/api/admin/users/{id}/unlock', [UserAdminApiController::class, 'unlock']],
        ['POST',   '/api/admin/groups/{id}/members', [GroupApiController::class, 'addMember']],
        ['DELETE', '/api/admin/groups/{id}/members/{userId}', [GroupApiController::class, 'removeMember']],
        ['GET',    '/api/conversations', [ConversationFeedApiController::class, 'index']],
        ['POST',   '/api/conversations', [ConversationApiController::class, 'start']],
        ['GET',    '/api/members', [MemberApiController::class, 'search']],
        ['GET',    '/api/conversation-list', [ConversationFeedApiController::class, 'listFragment']],
        ['GET',    '/api/conversations/{id}/updates', [ConversationFeedApiController::class, 'updates']],
        ['PATCH',  '/api/conversations/{id}', [ConversationApiController::class, 'rename']],
        ['DELETE', '/api/conversations/{id}', [ConversationTrashApiController::class, 'destroy']],
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
