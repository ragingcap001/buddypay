<?php

namespace App\Domain\Providers\Services;

use App\Domain\Providers\Enums\CircuitState;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * Per-provider circuit breaker backed by the application cache (Redis in
 * production), so every API node shares state.
 *
 *     CLOSED --(failures >= threshold)--> OPEN
 *     OPEN   --(cooldown elapsed)--------> HALF_OPEN
 *     HALF_OPEN --(success)--> CLOSED / --(failure)--> OPEN
 */
final class CircuitBreaker
{
    /**
     * @return array<string, mixed>
     */
    private function read(string $provider): array
    {
        $meta = $this->cache->get($this->cacheKey($provider));

        return is_array($meta) ? $meta : ['state' => CircuitState::Closed->value, 'failures' => 0, 'opened_at' => 0, 'half_open_calls' => 0];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function write(string $provider, array $meta): void
    {
        $this->cache->forever($this->cacheKey($provider), $meta);
    }

    private function cacheKey(string $provider): string
    {
        return 'circuit_breaker:'.$provider;
    }

    public function state(string $provider): CircuitState
    {
        return CircuitState::from((string) ($this->read($provider)['state'] ?? CircuitState::Closed->value));
    }

    public function allowRequest(string $provider): bool
    {
        $meta = $this->read($provider);
        $state = CircuitState::from((string) $meta['state']);

        if ($state === CircuitState::Closed) {
            return true;
        }

        if ($state === CircuitState::HalfOpen) {
            return (int) $meta['half_open_calls'] < (int) config('ase.circuit_breaker.half_open_max_calls', 1);
        }

        // OPEN: allow a probe after the cooldown elapses.
        $cooldown = (int) config('ase.circuit_breaker.cooldown_seconds', 60);
        $openedAt = (int) $meta['opened_at'];

        if (time() - $openedAt >= $cooldown) {
            $this->write($provider, [
                'state' => CircuitState::HalfOpen->value,
                'failures' => 0,
                'opened_at' => 0,
                'half_open_calls' => 0,
            ]);

            return true;
        }

        return false;
    }

    public function recordSuccess(string $provider): void
    {
        $this->write($provider, [
            'state' => CircuitState::Closed->value,
            'failures' => 0,
            'opened_at' => 0,
            'half_open_calls' => 0,
        ]);
    }

    public function recordFailure(string $provider): void
    {
        $meta = $this->read($provider);
        $state = CircuitState::from((string) $meta['state']);
        $threshold = (int) config('ase.circuit_breaker.failure_threshold', 5);

        if ($state === CircuitState::HalfOpen) {
            $this->write($provider, [
                'state' => CircuitState::Open->value,
                'failures' => $threshold,
                'opened_at' => time(),
                'half_open_calls' => 0,
            ]);

            return;
        }

        $failures = (int) $meta['failures'] + 1;

        if ($failures >= $threshold) {
            $this->write($provider, [
                'state' => CircuitState::Open->value,
                'failures' => $failures,
                'opened_at' => time(),
                'half_open_calls' => 0,
            ]);

            return;
        }

        $this->write($provider, [
            'state' => CircuitState::Closed->value,
            'failures' => $failures,
            'opened_at' => 0,
            'half_open_calls' => 0,
        ]);
    }

    public function reset(string $provider): void
    {
        $this->cache->forget($this->cacheKey($provider));
    }

    public function __construct(private readonly CacheRepository $cache)
    {
    }
}
