<?php

declare(strict_types=1);

use App\Controller\Api\AuthApiController;
use App\Controller\Api\AvailabilityApiController;
use App\Controller\Api\GroupApiController;
use App\Controller\Api\GroupContactApiController;
use App\Controller\Api\GroupDocumentApiController;
use App\Controller\Api\GroupSpaceApiController;
use App\Controller\Api\SlotApiController;
use App\Controller\PageController;

return [
    'pages' => [
        ['GET', '/', [PageController::class, 'dashboard']],
        ['GET', '/login', [PageController::class, 'login']],
        ['GET', '/register', [PageController::class, 'register']],
        ['GET', '/admin/slots', [PageController::class, 'adminSlots']],
        ['GET', '/admin/groups', [PageController::class, 'adminGroups']],
        ['GET', '/groups/{id}/space', [PageController::class, 'groupSpace']],
    ],
    'api' => [
        ['POST', '/api/auth/login', [AuthApiController::class, 'login']],
        ['POST', '/api/auth/register', [AuthApiController::class, 'register']],
        ['POST', '/api/auth/logout', [AuthApiController::class, 'logout']],
        ['POST', '/api/auth/select-group', [AuthApiController::class, 'selectGroup']],
        ['GET',  '/api/availability/pending/{groupId}', [AvailabilityApiController::class, 'pendingForGroup']],
        ['GET',  '/api/availability/requested/{groupId}', [AvailabilityApiController::class, 'requestedByGroup']],
        ['POST', '/api/availability/{exceptionId}/respond', [AvailabilityApiController::class, 'respond']],
        ['PATCH', '/api/availability/{exceptionId}', [AvailabilityApiController::class, 'update']],
        ['DELETE', '/api/availability/{exceptionId}', [AvailabilityApiController::class, 'destroy']],
        ['GET',    '/api/admin/slots', [SlotApiController::class, 'index']],
        ['POST',   '/api/admin/slots', [SlotApiController::class, 'store']],
        ['PATCH',  '/api/admin/slots/{id}', [SlotApiController::class, 'update']],
        ['DELETE', '/api/admin/slots/{id}', [SlotApiController::class, 'destroy']],
        ['GET',    '/api/admin/groups', [GroupApiController::class, 'index']],
        ['POST',   '/api/admin/groups', [GroupApiController::class, 'store']],
        ['PATCH',  '/api/admin/groups/{id}', [GroupApiController::class, 'update']],
        ['DELETE', '/api/admin/groups/{id}', [GroupApiController::class, 'destroy']],
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
