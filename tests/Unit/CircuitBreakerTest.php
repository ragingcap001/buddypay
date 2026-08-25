<?php

namespace Tests\Unit;

use App\Domain\Providers\Enums\CircuitState;
use App\Domain\Providers\Services\CircuitBreaker;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

final class CircuitBreakerTest extends TestCase
{
    private CircuitBreaker $breaker;

    protected function setUp(): void
    {
        config([
            'ase.circuit_breaker' => [
                'failure_threshold' => 2,
                'cooldown_seconds' => 3600, // long: OPEN stays closed to traffic in tests
                'half_open_max_calls' => 1,
            ],
        ]);

        $this->breaker = new CircuitBreaker(new Repository(new ArrayStore));
    }

    public function test_starts_closed_and_allows_requests(): void
    {
        $this->assertSame(CircuitState::Closed, $this->breaker->state('mock'));
        $this->assertTrue($this->breaker->allowRequest('mock'));
    }

    public function test_opens_after_failure_threshold_and_blocks_requests(): void
    {
        $this->breaker->recordFailure('mock');
        $this->assertSame(CircuitState::Closed, $this->breaker->state('mock'));

        $this->breaker->recordFailure('mock');
        $this->assertSame(CircuitState::Open, $this->breaker->state('mock'));
        $this->assertFalse($this->breaker->allowRequest('mock'));
    }

    public function test_success_resets_the_circuit(): void
    {
        $this->breaker->recordFailure('mock');
        $this->breaker->recordSuccess('mock');

        $this->assertSame(CircuitState::Closed, $this->breaker->state('mock'));
        $this->assertTrue($this->breaker->allowRequest('mock'));
    }

    public function test_half_open_after_cooldown_reopens_on_failure(): void
    {
        config(['ase.circuit_breaker.cooldown_seconds' => 0]);

        $this->breaker->recordFailure('mock');
        $this->breaker->recordFailure('mock');
        $this->assertSame(CircuitState::Open, $this->breaker->state('mock'));

        // Cooldown elapsed (0s), so the next probe goes HALF_OPEN.
        $this->assertTrue($this->breaker->allowRequest('mock'));
        $this->assertSame(CircuitState::HalfOpen, $this->breaker->state('mock'));

        $this->breaker->recordFailure('mock');
        $this->assertSame(CircuitState::Open, $this->breaker->state('mock'));
    }

    public function test_half_open_closes_on_success(): void
    {
        config(['ase.circuit_breaker.cooldown_seconds' => 0]);

        $this->breaker->recordFailure('mock');
        $this->breaker->recordFailure('mock');
        $this->breaker->allowRequest('mock'); // -> HALF_OPEN

        $this->breaker->recordSuccess('mock');
        $this->assertSame(CircuitState::Closed, $this->breaker->state('mock'));
    }

    public function test_providers_are_tracked_independently(): void
    {
        $this->breaker->recordFailure('provider-a');
        $this->breaker->recordFailure('provider-a');

        $this->assertFalse($this->breaker->allowRequest('provider-a'));
        $this->assertTrue($this->breaker->allowRequest('provider-b'));
    }
}
