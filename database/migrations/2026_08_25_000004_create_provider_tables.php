<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64)->unique();
            $table->string('type', 30)->default('BILL');
            $table->string('display_name')->nullable();
            $table->string('base_url')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->unsignedInteger('priority')->default(0);
            $table->json('config')->nullable();
            $table->timestamps();
        });

        Schema::create('provider_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 30);
            $table->string('status', 20)->index();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['transaction_id', 'type']);
        });

        Schema::create('provider_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 64);
            $table->string('provider_event_id', 128);
            $table->json('raw_payload');
            $table->string('status', 20)->default('RECEIVED');
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['provider_id', 'provider_event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_webhooks');
        Schema::dropIfExists('provider_attempts');
        Schema::dropIfExists('providers');
    }
};
