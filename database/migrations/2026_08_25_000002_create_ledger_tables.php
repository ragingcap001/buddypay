<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('type', 20);
            $table->string('name');
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
        });

        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->string('description');
            $table->string('status', 20)->default('POSTED');
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        // Append-only: no updated_at column on purpose.
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ledger_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ledger_account_id')->constrained()->cascadeOnDelete();
            $table->string('direction', 10);
            $table->unsignedBigInteger('amount');
            $table->timestamp('created_at')->nullable();
            $table->index(['ledger_transaction_id', 'direction']);
            $table->index('ledger_account_id');
        });

        // Database-level immutability of posted entries (PostgreSQL).
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(<<<'SQL'
                CREATE OR REPLACE FUNCTION ase_prevent_ledger_entry_modification()
                RETURNS TRIGGER AS $$
                BEGIN
                    RAISE EXCEPTION 'ledger entries are immutable';
                END;
                $$ LANGUAGE plpgsql
                SQL);

            DB::statement('CREATE TRIGGER ase_ledger_entries_no_update BEFORE UPDATE ON ledger_entries FOR EACH ROW EXECUTE FUNCTION ase_prevent_ledger_entry_modification()');
            DB::statement('CREATE TRIGGER ase_ledger_entries_no_delete BEFORE DELETE ON ledger_entries FOR EACH ROW EXECUTE FUNCTION ase_prevent_ledger_entry_modification()');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS ase_ledger_entries_no_update ON ledger_entries');
            DB::statement('DROP TRIGGER IF EXISTS ase_ledger_entries_no_delete ON ledger_entries');
            DB::statement('DROP FUNCTION IF EXISTS ase_prevent_ledger_entry_modification()');
        }

        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('ledger_accounts');
    }
};
