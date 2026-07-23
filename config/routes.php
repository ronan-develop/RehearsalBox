<?php

declare(strict_types=1);

use App\Controller\Api\PingApiController;
use App\Controller\PingController;

return [
    'pages' => [
        ['GET', '/ping', [PingController::class, 'index']],
    ],
    'api' => [
        ['GET', '/api/ping', [PingApiController::class, 'index']],
    ],
];
