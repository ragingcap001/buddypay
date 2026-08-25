<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reconciliation_batches', function (Blueprint $table) {
            $table->id();
            $table->string('provider_name', 64);
            $table->timestamp('from');
            $table->timestamp('to');
            $table->string('status', 20)->default('RUNNING');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('matched')->default(0);
            $table->unsignedInteger('exceptions')->default(0);
            $table->json('summary')->nullable();
            $table->timestamps();
            $table->index(['provider_name', 'from']);
        });

        Schema::create('reconciliation_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('reconciliation_batches')->cascadeOnDelete();
            $table->string('reference', 64)->nullable();
            $table->string('provider_reference', 128)->nullable();
            $table->unsignedBigInteger('internal_amount')->nullable();
            $table->unsignedBigInteger('provider_amount')->nullable();
            $table->string('status', 40)->index();
            $table->json('details')->nullable();
            $table->timestamps();
            $table->index(['batch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reconciliation_items');
        Schema::dropIfExists('reconciliation_batches');
    }
};
