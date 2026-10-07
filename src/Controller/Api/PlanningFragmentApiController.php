<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Http\JsonResponse;
use App\Http\Request;
use App\Presenter\ExceptionalPlanningFragment;
use App\Security\AuthGuard;

/** Fragments HTML du planning (#243) : réservés aux personnes connectées, comme le planning lui-même. */
final class PlanningFragmentApiController
{
    public function __construct(
        private readonly AuthGuard $authGuard,
        private readonly ExceptionalPlanningFragment $exceptional,
    ) {
    }

    public function exceptional(Request $request): JsonResponse
    {
        $this->authGuard->requireLogin();

        return new JsonResponse($this->exceptional->render());
    }
}
