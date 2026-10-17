<?php

declare(strict_types=1);

namespace App\Tests\Accounting\Presenter;

use App\Accounting\Presenter\TodoView;
use App\Accounting\Todo\TodoItem;
use App\Accounting\Todo\TodoState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** #362 : présentation de « À faire » : urgence lisible sans couleur, limite d'affichage, bouton « rien à saisir » seulement pour un mois à saisir. */
final class TodoViewTest extends TestCase
{
    private function item(string $key, TodoState $state = TodoState::AFaire, ?string $due = '2025-11-05'): TodoItem
    {
        return new TodoItem($key, 'Libellé ' . $key, 'Faire', '/admin/accounting', $state, $due === null ? null : new \DateTimeImmutable($due));
    }

    #[Test]
    public function testRowsCarryTheStateInWordsTheDueDateAndTheEmptyMonthOnlyForEntries(): void
    {
        $page = (new TodoView())->build([$this->item('entries:2025-10', TodoState::EnRetard), $this->item('statement:2025-10'), $this->item('close', TodoState::AFaire, null)], 1);

        self::assertSame('En retard', $page['items'][0]['stateLabel']);
        self::assertTrue($page['items'][0]['late']);
        self::assertSame('avant le 05/11/2025', $page['items'][0]['due']);
        self::assertSame('2025-10', $page['items'][0]['emptyMonth']);
        self::assertSame('', $page['items'][1]['emptyMonth']);
        self::assertSame('', $page['items'][2]['due']);
        self::assertSame(1, $page['lateCount']);
        self::assertSame(3, $page['total']);
    }

    #[Test]
    public function testOnlyTheFirstEightAreShownAndTheRestIsCounted(): void
    {
        $items = array_map(fn (int $i): TodoItem => $this->item('statement:' . $i), range(1, 11));

        $page = (new TodoView())->build($items, 1);

        self::assertCount(8, $page['items']);
        self::assertSame(3, $page['hidden']);
        self::assertSame(11, $page['total']);
    }

    #[Test]
    public function testNothingToDoGivesAnEmptyList(): void
    {
        self::assertSame(['items' => [], 'hidden' => 0, 'lateCount' => 0, 'total' => 0], (new TodoView())->build([], 1));
    }
}
