<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bill_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30)->unique();
            $table->string('display_name');
            $table->unsignedInteger('display_order')->default(0);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
        });

        Schema::create('bill_providers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('provider_id')->constrained()->cascadeOnDelete();
            $table->string('display_name');
            $table->text('description')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
        });

        Schema::create('bill_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_provider_id')->constrained()->cascadeOnDelete();
            $table->string('category', 30)->index();
            $table->string('name');
            $table->string('code', 64)->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['bill_provider_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bill_products');
        Schema::dropIfExists('bill_providers');
        Schema::dropIfExists('bill_categories');
    }
};
