<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->string('aggregate_type', 64);
            $table->unsignedBigInteger('aggregate_id');
            $table->string('event_type', 64)->index();
            $table->json('payload');
            $table->string('status', 20)->default('PENDING')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamps();
            $table->index(['aggregate_type', 'aggregate_id']);
        });

        // Append-only audit log.
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action', 128)->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('outbox_events');
    }
};
