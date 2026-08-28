<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drop obsolete chatbot table resident_password_reset_tokens only when
 * the authoritative resident_password_resets table already exists.
 *
 * NEVER drop resident_password_resets.
 * NEVER recreate resident_password_reset_tokens.
 * Does not touch resident_accounts, residents, users, or
 * password_reset_tokens.
 *
 * Do not run this against developer MySQL from the Cursor task.
 * Later (operator): php artisan migrate --path=database/migrations/2026_08_24_140000_drop_obsolete_resident_password_reset_tokens_table.php
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('resident_password_resets')) {
            return;
        }

        if (! Schema::hasTable('resident_password_reset_tokens')) {
            return;
        }

        Schema::drop('resident_password_reset_tokens');
    }

    public function down(): void
    {
        // Do not recreate the obsolete table.
    }
};
