<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Declarative risk rules (evaluated by the risk engine in addition
        // to the tier limits in config/ase.php).
        Schema::create('risk_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->json('conditions');
            $table->string('outcome', 20);
            $table->unsignedInteger('priority')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('risk_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('transaction_type', 30);
            $table->unsignedBigInteger('amount');
            $table->unsignedInteger('kyc_tier')->default(0);
            $table->string('outcome', 20)->index();
            $table->json('signals')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_assessments');
        Schema::dropIfExists('risk_rules');
    }
};
