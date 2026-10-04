<?php

declare(strict_types=1);

namespace App\Mail;

use App\View\PhpTemplateRenderer;
use App\View\TemplateRendererInterface;

/**
 * Rend un e-mail en deux versions à partir des gabarits `templates/mail/` : HTML
 * (gabarit commun `layout` + corps `<nom>.html`) et texte (`<nom>.txt`, alternative
 * pour les clients sans HTML — et meilleure note de délivrabilité qu'un HTML seul).
 */
final class MailRenderer
{
    public function __construct(private readonly TemplateRendererInterface $renderer)
    {
    }

    public static function withDefaultTemplates(): self
    {
        return new self(new PhpTemplateRenderer(__DIR__ . '/../../templates'));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{html: string, text: string}
     */
    public function render(string $template, array $data = []): array
    {
        $content = $this->renderer->render("mail/{$template}.html", $data);

        return [
            'html' => $this->renderer->render('mail/layout', [
                'content' => $content,
                'preheader' => (string) ($data['preheader'] ?? ''),
            ]),
            'text' => rtrim($this->renderer->render("mail/{$template}.txt", $data)) . "\n",
        ];
    }
}
