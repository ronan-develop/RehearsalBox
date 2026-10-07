<?php

declare(strict_types=1);

namespace App\Tests\Scenarios;

use App\Http\ExceptionTranslator;
use App\Http\Response;

/**
 * Enveloppe un contrôleur dans un test : une exception MÉTIER qu'il laisse remonter devient sa réponse, exactement comme le fait
 * le Kernel (même table, ExceptionTranslator). Tout le reste (accès refusé, erreur inattendue) remonte tel quel. Les tests de
 * contrôleur gardent ainsi leurs assertions sur le statut et le corps, sans que chaque contrôleur répète ses `catch`.
 *
 * @mixin object
 */
final class KernelTranslation
{
    public function __construct(private readonly object $controller)
    {
    }

    /** @param list<mixed> $arguments */
    public function __call(string $method, array $arguments): Response
    {
        try {
            return $this->controller->{$method}(...$arguments);
        } catch (\Throwable $e) {
            return (new ExceptionTranslator())->translate($e) ?? throw $e;
        }
    }
}
