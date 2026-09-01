<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's standard notifications table (Illuminate\Notifications\Notifiable,
 * already used by User). The mobile contract's in-app notification list is
 * this table read back through the database channel — no bespoke table,
 * no bespoke pagination shape, and Laravel's markAsRead()/unread() ship for
 * free instead of being reimplemented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
