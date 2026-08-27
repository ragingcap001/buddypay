<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Internal staff accounts for the Filament panel.
 *
 * Deliberately separate from `users`: that table carries customer PII,
 * KYC data and wallet ownership, and must not share a credential store
 * or a login surface with staff. The existing `users.role` column and
 * EnsureAdmin middleware stay in place for the /api/v1/admin/* API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admins', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admins');
    }
};
