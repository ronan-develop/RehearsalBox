<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Response;
use App\Security\CsrfTokenManager;
use App\View\TemplateRendererInterface;

final class PageController
{
    public function __construct(
        private readonly TemplateRendererInterface $renderer,
        private readonly CsrfTokenManager $csrfTokenManager,
    ) {
    }

    public function login(): Response
    {
        return new Response($this->renderer->render('auth/login', ['csrfToken' => $this->csrfTokenManager->getToken()]));
    }

    public function register(): Response
    {
        return new Response($this->renderer->render('auth/register', ['csrfToken' => $this->csrfTokenManager->getToken()]));
    }
}
