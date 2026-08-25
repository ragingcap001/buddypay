<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 30)->default('PENDING');
            $table->unsignedInteger('tier')->default(0);
            $table->string('bvn_hash', 64)->nullable();
            $table->string('nin_hash', 64)->nullable();
            $table->string('full_name')->nullable();
            $table->timestamps();
        });

        Schema::create('kyc_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_profile_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 64)->unique();
            $table->string('type', 20);
            $table->string('status', 30);
            $table->string('input_hash', 64)->nullable();
            $table->json('provider_response')->nullable();
            $table->timestamps();
        });

        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_profile_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('storage_path');
            $table->string('status', 30)->default('PENDING');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');
        Schema::dropIfExists('kyc_verifications');
        Schema::dropIfExists('kyc_profiles');
    }
};
