<?php

namespace Tests\Support;

use App\Contracts\HostResolverInterface;
use Closure;

/**
 * DNS falso para los tests: resuelve cada host con el closure indicado.
 */
class FakeHostResolver implements HostResolverInterface
{
    /** @param Closure(string): array<int, string> $resolver */
    public function __construct(private readonly Closure $resolver) {}

    public function resolve(string $host): array
    {
        return array_values(($this->resolver)($host));
    }
}
