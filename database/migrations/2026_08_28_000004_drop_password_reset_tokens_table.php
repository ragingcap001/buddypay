<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The mobile contract's password reset is OTP-based (forgot-password ->
 * verify-reset-otp -> reset-password), reusing OtpChallenge exactly like
 * email verification does. The old random-token "reset link" table is
 * superseded, not just unused — dropping it removes a second, parallel
 * reset mechanism instead of leaving dead schema behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }

    public function down(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20);
            $table->string('token');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->index(['phone', 'token']);
        });
    }
};
