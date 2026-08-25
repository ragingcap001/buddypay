<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('currency', 3)->default('NGN');
            $table->unsignedBigInteger('control_balance')->default(0);
            $table->unsignedBigInteger('reserved_balance')->default(0);
            $table->timestamps();
        });

        // Balance invariants are enforced at the database level.
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT wallets_balances_valid CHECK (control_balance >= 0 AND reserved_balance >= 0 AND reserved_balance <= control_balance)');
        }

        Schema::create('wallet_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 64)->unique();
            $table->unsignedBigInteger('amount');
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->string('release_reason')->nullable();
            $table->timestamps();
            $table->index(['wallet_id', 'status']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30)->index();
            $table->string('status', 20)->default('INITIATED')->index();
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('fee')->default(0);
            $table->string('currency', 3)->default('NGN');
            $table->json('metadata')->nullable();
            $table->string('provider', 64)->nullable();
            $table->string('provider_reference', 128)->nullable();
            $table->foreignId('reservation_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
            $table->index(['provider', 'status']);
        });

        Schema::create('transaction_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('from_status', 20);
            $table->string('to_status', 20);
            $table->string('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index('transaction_id');
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('key', 128);
            $table->string('request_hash', 64);
            $table->string('status', 20)->default('IN_PROGRESS');
            $table->json('response')->nullable();
            $table->string('transaction_reference', 64)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('transaction_events');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('wallet_reservations');

        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('ALTER TABLE wallets DROP CONSTRAINT IF EXISTS wallets_balances_valid');
        }

        Schema::dropIfExists('wallets');
    }
};
