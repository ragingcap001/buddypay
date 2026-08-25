<?php

namespace Tests\Unit;

use App\Domain\Providers\Enums\ProviderOutcome;
use App\Domain\Providers\Services\OutcomeClassifier;
use App\Exceptions\ProviderTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use PHPUnit\Framework\TestCase;

final class OutcomeClassifierTest extends TestCase
{
    private OutcomeClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new OutcomeClassifier();
    }

    public function test_confirmed_success_is_definitive(): void
    {
        $this->assertSame(
            ProviderOutcome::DefinitiveSuccess,
            $this->classifier->classify(['status_code' => 200, 'success' => true]),
        );
    }

    public function test_client_errors_are_definitive_failures(): void
    {
        $this->assertSame(
            ProviderOutcome::DefinitiveFailure,
            $this->classifier->classify(['status_code' => 400, 'success' => false]),
        );
        $this->assertSame(
            ProviderOutcome::DefinitiveFailure,
            $this->classifier->classify(['status_code' => 404]),
        );
    }

    public function test_server_errors_are_ambiguous(): void
    {
        $this->assertSame(
            ProviderOutcome::Ambiguous,
            $this->classifier->classify(['status_code' => 500, 'success' => false]),
        );
    }

    public function test_no_response_is_ambiguous(): void
    {
        $this->assertSame(
            ProviderOutcome::Ambiguous,
            $this->classifier->classify(['status_code' => 0]),
        );
    }

    public function test_timeout_exceptions_are_ambiguous(): void
    {
        $this->assertSame(
            ProviderOutcome::Ambiguous,
            $this->classifier->classifyException(new ProviderTimeoutException('mock')),
        );
    }

    public function test_connection_exceptions_are_ambiguous(): void
    {
        $this->assertSame(
            ProviderOutcome::Ambiguous,
            $this->classifier->classifyException(new ConnectionException('reset by peer')),
        );
    }
}
