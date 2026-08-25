<?php

namespace Tests\Feature\Outbox;

use App\Infrastructure\Messaging\OutboxPublisher;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

final class OutboxTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    public function test_financial_events_are_recorded_in_the_outbox(): void
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'outbox-1')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', ['amount' => 100000])
            ->assertOk();

        $types = OutboxEvent::pluck('event_type')->all();

        $this->assertContains('transaction.processing', $types);
        $this->assertContains('transaction.completed', $types);
        $this->assertSame('PENDING', OutboxEvent::first()->status);
    }

    public function test_outbox_publish_dispatches_events_and_notifications(): void
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'outbox-2')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', ['amount' => 100000])
            ->assertOk();

        $this->assertSame(0, \App\Models\CustomerNotification::count());

        $published = app(OutboxPublisher::class)->publish();
        $this->assertSame(OutboxEvent::count(), $published);

        $this->assertSame(0, OutboxEvent::where('status', 'PENDING')->count());
        $this->assertGreaterThan(0, OutboxEvent::where('status', 'DISPATCHED')->count());

        // The completed-transaction event produced a customer notification.
        $this->assertDatabaseHas('customer_notifications', [
            'user_id' => $user->id,
            'type' => 'transaction.completed',
            'status' => 'SENT',
        ]);

        // The artisan command is a no-op when the outbox is drained.
        Artisan::call('outbox:publish');
        $this->assertSame(0, OutboxEvent::where('status', 'PENDING')->count());
    }
}
