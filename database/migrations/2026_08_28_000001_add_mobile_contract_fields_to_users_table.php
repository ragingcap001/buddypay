<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the fields the mobile API contract needs on top of the existing
 * `name`/`phone`/`email` user record:
 *
 *  - first_name/last_name: the contract addresses users by these, not a
 *    single `name` string. `name` is kept (existing Filament tables,
 *    NotificationService, etc. all read it) and stays in sync via a model
 *    event rather than being removed.
 *  - gender, device_token: plain profile fields the contract exposes.
 *  - fpuid: the contract's public user identifier ("FP" + zero-padded id).
 *    Derived from the auto-increment id — no separate sequence/counter
 *    table, so no race condition to guard against.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->string('gender', 10)->nullable()->after('last_name');
            $table->string('device_token')->nullable()->after('pin_hash');
            $table->string('fpuid', 20)->nullable()->unique()->after('id');
        });

        // Backfill any existing rows (dev/test data only — no production
        // users exist against this contract yet).
        foreach (DB::table('users')->select('id', 'name')->cursor() as $user) {
            $parts = preg_split('/\s+/', trim((string) $user->name), 2);

            DB::table('users')->where('id', $user->id)->update([
                'fpuid' => sprintf('FP%08d', $user->id),
                'first_name' => $parts[0] ?? '',
                'last_name' => $parts[1] ?? '',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['fpuid', 'first_name', 'last_name', 'gender', 'device_token']);
        });
    }
};
