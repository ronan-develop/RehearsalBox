<?php

declare(strict_types=1);

use App\Controller\Api\AuthApiController;
use App\Controller\PageController;

return [
    'pages' => [
        ['GET', '/login', [PageController::class, 'login']],
        ['GET', '/register', [PageController::class, 'register']],
    ],
    'api' => [
        ['POST', '/api/auth/login', [AuthApiController::class, 'login']],
        ['POST', '/api/auth/register', [AuthApiController::class, 'register']],
        ['POST', '/api/auth/logout', [AuthApiController::class, 'logout']],
    ],
];
