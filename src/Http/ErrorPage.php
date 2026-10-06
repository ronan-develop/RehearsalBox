<?php

declare(strict_types=1);

namespace App\Http;

/**
 * Page d'erreur HTML (#259) : un seul gabarit (templates/errors/error.php) pour tous les codes. Volontairement autonome (ni conteneur,
 * ni base, ni session) pour s'afficher même quand c'est justement l'un d'eux qui est en panne. Les libellés sont fixes : jamais le
 * message d'une exception, ni trace, ni indice sur l'existence d'une ressource (une conversation interdite ressemble à une absente).
 */
final class ErrorPage
{
    /** @var array<int, array{string, string}> code => [titre, explication] */
    private const MESSAGES = [
        400 => ['Requête incorrecte', 'Nous n’avons pas compris cette demande. Revenez à la page précédente et réessayez.'],
        401 => ['Connexion requise', 'Connectez-vous pour accéder à cette page.'],
        403 => ['Accès refusé', 'Vous n’avez pas accès à cette page.'],
        404 => ['Page introuvable', 'Cette page n’existe pas ou a été déplacée.'],
        405 => ['Méthode non autorisée', 'Cette action n’est pas possible depuis cette page.'],
        419 => ['Session expirée', 'Votre session a expiré. Reconnectez-vous puis recommencez.'],
        429 => ['Trop de requêtes', 'Merci de patienter un instant avant de réessayer.'],
        500 => ['Erreur interne', 'Un incident est survenu de notre côté. Il a été enregistré ; réessayez dans un moment.'],
        503 => ['Service indisponible', 'Le service est momentanément indisponible. Réessayez dans quelques minutes.'],
    ];

    public static function response(int $status, ?int $retryAfter = null): Response
    {
        [$title, $text] = self::MESSAGES[$status] ?? ($status >= 500
            ? ['Erreur du serveur', 'Un incident est survenu de notre côté. Réessayez dans un moment.']
            : ['Requête refusée', 'Cette demande n’a pas pu être traitée.']);

        if ($status === 429 && $retryAfter !== null) {
            $minutes = max(1, (int) ceil($retryAfter / 60));
            $text = sprintf('Merci de patienter %d minute%s avant de réessayer.', $minutes, $minutes > 1 ? 's' : '');
        }

        $headers = ['Content-Type' => 'text/html; charset=utf-8'];
        if ($retryAfter !== null) {
            $headers['Retry-After'] = (string) $retryAfter;
        }

        return new Response(self::render($status, $title, $text), $status, $headers);
    }

    private static function render(int $status, string $title, string $text): string
    {
        require_once __DIR__ . '/../View/helpers.php';

        ob_start();
        require __DIR__ . '/../../templates/errors/error.php';

        return (string) ob_get_clean();
    }
}
