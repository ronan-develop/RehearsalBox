<?php

declare(strict_types=1);

use App\Controller\Api\AccountApiController;
use App\Controller\Api\AuthApiController;
use App\Controller\Api\AvailabilityApiController;
use App\Controller\Api\GroupApiController;
use App\Controller\Api\UserAdminApiController;
use App\Controller\Api\GroupContactApiController;
use App\Controller\Api\GroupDocumentApiController;
use App\Controller\Api\GroupSpaceApiController;
use App\Controller\Api\PasswordResetApiController;
use App\Controller\Api\SlotApiController;
use App\Controller\PageController;

return [
    'pages' => [
        ['GET', '/', [PageController::class, 'dashboard']],
        ['GET', '/login', [PageController::class, 'login']],
        ['GET', '/forgot-password', [PageController::class, 'forgotPassword']],
        ['GET', '/reset-password', [PageController::class, 'resetPassword']],
        ['GET', '/account/password', [PageController::class, 'accountPassword']],
        ['GET', '/account/secure', [PageController::class, 'secureAccount']],
        ['GET', '/admin/slots', [PageController::class, 'adminSlots']],
        ['GET', '/admin/groups', [PageController::class, 'adminGroups']],
        ['GET', '/admin/users', [PageController::class, 'adminUsers']],
        ['GET', '/groups/{slug}/space', [PageController::class, 'groupSpace']],
    ],
    'api' => [
        ['POST', '/api/auth/login', [AuthApiController::class, 'login']],
        ['POST', '/api/auth/forgot-password', [PasswordResetApiController::class, 'forgotPassword']],
        ['POST', '/api/auth/reset-password', [PasswordResetApiController::class, 'resetPassword']],
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
        ['POST',   '/api/groups/{id}/contact', [GroupContactApiController::class, 'send']],
        ['GET',    '/api/groups/{id}/space', [GroupSpaceApiController::class, 'show']],
        ['PATCH',  '/api/groups/{id}/space', [GroupSpaceApiController::class, 'updateProfile']],
        ['POST',   '/api/groups/{id}/documents', [GroupDocumentApiController::class, 'store']],
        ['GET',    '/api/groups/{id}/documents', [GroupDocumentApiController::class, 'index']],
        ['GET',    '/api/documents/{id}', [GroupDocumentApiController::class, 'download']],
        ['DELETE', '/api/documents/{id}', [GroupDocumentApiController::class, 'destroy']],
    ],
];
