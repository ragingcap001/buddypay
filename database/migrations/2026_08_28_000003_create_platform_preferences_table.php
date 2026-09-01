<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton row of product feature flags + social links, edited from the
 * admin panel and read publicly via GET /v1/preferences. Deliberately
 * separate from `app_config`: app_config is for infra/provider secrets
 * (encrypted, masked); this is public, non-secret product configuration.
 *
 * `bettingCharge` in the API response is NOT stored here — it's read live
 * from config('ase.fees.betting.flat'), the same figure FeeCalculation
 * already applies to betting transactions, so the two can never disagree.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_preferences', function (Blueprint $table) {
            $table->id();
            $table->json('features');
            $table->json('socials');
            $table->timestamps();
        });

        DB::table('platform_preferences')->insert([
            'features' => json_encode([
                'airtime' => true,
                'data' => true,
                'sme' => false,
                'tv' => true,
                'electricity' => true,
                'betting' => true,
                'giftcard' => true,
            ]),
            'socials' => json_encode([
                'facebook' => '',
                'instagram' => '',
                'twitter' => '',
                'whatsapp' => '',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_preferences');
    }
};
