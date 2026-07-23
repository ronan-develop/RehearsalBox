<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\JsonResponse;

/** Route de validation du socle technique — à retirer une fois les vrais Api\*Controller en place. */
final class PingApiController
{
    public function index(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
