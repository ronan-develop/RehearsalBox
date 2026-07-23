<?php

declare(strict_types=1);

namespace App\Routing;

final class Route
{
    private readonly string $compiledPattern;

    /** @var list<string> */
    private readonly array $paramNames;

    /** @param array{0: class-string, 1: string} $handler */
    public function __construct(
        public readonly string $method,
        public readonly string $pattern,
        public readonly array $handler,
    ) {
        [$this->compiledPattern, $this->paramNames] = self::compile($pattern);
    }

    /** @return array{0: string, 1: list<string>} */
    private static function compile(string $pattern): array
    {
        $paramNames = [];

        $regex = preg_replace_callback(
            '#\{(\w+)\}#',
            function (array $matches) use (&$paramNames): string {
                $paramNames[] = $matches[1];
                return '(?P<' . $matches[1] . '>[^/]+)';
            },
            $pattern,
        );

        return ['#^' . $regex . '$#', $paramNames];
    }

    /** @return array<string, string>|null */
    public function match(string $path): ?array
    {
        if (!preg_match($this->compiledPattern, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($this->paramNames as $name) {
            $params[$name] = $matches[$name];
        }

        return $params;
    }
}
