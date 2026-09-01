<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // android | ios | web — delivery goes through FCM (which relays
            // to APNs for iOS), so Apple and Google share the same token store.
            $table->string('platform', 20);
            $table->string('token', 255);
            $table->string('name')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'token']);
            $table->index(['active', 'platform']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_devices');
    }
};
