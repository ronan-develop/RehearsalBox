<?php

declare(strict_types=1);

namespace App\TaintFixture;

use App\Http\Request;

/** Flux volontairement tachés (#433) : l'analyse de teinte DOIT les signaler, sinon elle ne protège plus de rien. Jamais chargé par l'application. */
final class TaintedFlows
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function sql(Request $request): void
    {
        $this->pdo->query('SELECT * FROM users WHERE name = \'' . $request->body('name') . '\'');
    }

    public function html(Request $request): void
    {
        echo $request->query('q');
    }

    public function shell(Request $request): void
    {
        shell_exec('ls ' . $request->query('d'));
    }
}
