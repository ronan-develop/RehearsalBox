<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;

/** Route de validation du socle technique — à retirer une fois PageController réel en place (étape 5). */
final class PingController
{
    public function index(): Response
    {
        return new Response(body: 'OK', statusCode: 200);
    }
}
