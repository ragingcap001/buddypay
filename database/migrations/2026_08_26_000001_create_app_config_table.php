<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_config', function (Blueprint $table) {
            $table->id();
            $table->string('key', 128)->unique();
            $table->string('group', 40)->index();
            // Stored encrypted at rest (model uses the `encrypted` cast).
            $table->text('value')->nullable();
            $table->boolean('is_secret')->default(false);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_config');
    }
};
