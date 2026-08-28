<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A gift card's redemption code IS the redeemable value — the same class
 * of secret as a provider credential — so it gets its own table with
 * encrypted columns (Eloquent 'encrypted' cast), never the shared
 * `transactions.metadata` JSON blob that every other provider integration
 * writes to in plain text and that Filament/API resources read as a
 * plain array throughout the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gift_card_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('card_number');
            $table->text('pin_code')->nullable();
            $table->string('redemption_url', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gift_card_redemptions');
    }
};
