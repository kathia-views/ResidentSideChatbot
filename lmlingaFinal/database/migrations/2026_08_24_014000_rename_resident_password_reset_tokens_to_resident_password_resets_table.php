<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Historical compatibility: if a database still has only
 * resident_password_reset_tokens and not resident_password_resets,
 * rename the obsolete table so later cleanup can drop leftovers.
 *
 * If resident_password_resets already exists (developer MySQL
 * authoritative schema, or a fresh install), this is a no-op.
 *
 * Does not touch resident_accounts, residents, users, or
 * password_reset_tokens. Do not apply to developer MySQL from
 * this Cursor task.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('resident_password_resets')) {
            return;
        }

        if (Schema::hasTable('resident_password_reset_tokens')) {
            Schema::rename('resident_password_reset_tokens', 'resident_password_resets');
        }
    }

    public function down(): void
    {
        // Do not rename resident_password_resets back to the obsolete name.
    }
};
