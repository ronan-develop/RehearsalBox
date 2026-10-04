<?php

declare(strict_types=1);

namespace App\Mail;

use App\View\PhpTemplateRenderer;
use App\View\TemplateRendererInterface;
use Symfony\Component\Mime\Email;

/**
 * Rend un e-mail en deux versions à partir des gabarits `templates/mail/` : HTML
 * (gabarit commun `layout` + corps `<nom>.html`) et texte (`<nom>.txt`, alternative
 * pour les clients sans HTML — et meilleure note de délivrabilité qu'un HTML seul).
 */
final class MailRenderer
{
    private const LOGO_PATH = __DIR__ . '/../../public/assets/img/mail-logo-b27.png';
    private const LOGO_CONTENT_ID = 'logo-b27';

    public function __construct(private readonly TemplateRendererInterface $renderer)
    {
    }

    public static function withDefaultTemplates(): self
    {
        return new self(new PhpTemplateRenderer(__DIR__ . '/../../templates'));
    }

    /**
     * Complète un e-mail (expéditeur, destinataire, sujet déjà posés) avec ses deux versions
     * et le logo du site intégré en pièce jointe en ligne (cid:logo-b27) : il s'affiche sans
     * « afficher les images », contrairement à une image distante.
     *
     * @param array<string, mixed> $data
     */
    public function compose(Email $email, string $template, array $data = []): Email
    {
        $mail = $this->render($template, $data);

        return $email
            ->html($mail['html'])
            ->text($mail['text'])
            ->embedFromPath(self::LOGO_PATH, self::LOGO_CONTENT_ID, 'image/png');
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
