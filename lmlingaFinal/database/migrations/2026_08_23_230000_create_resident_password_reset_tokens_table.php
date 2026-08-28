<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chatbot-only password reset records.
 *
 * Authoritative table: resident_password_resets
 * (reset_id, account_id, reset_token, requested_at, expires_at,
 * is_used, used_at, created_at). Isolated from Laravel's staff
 * password_reset_tokens table.
 *
 * This migration already ran on developer MySQL (historically as
 * resident_password_reset_tokens). Do not rewrite recorded
 * migration rows. If resident_password_resets already exists, this
 * is a no-op. If only the obsolete tokens table exists, leave it
 * for the rename/cleanup migrations. Fresh installs create the
 * authoritative schema directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('resident_password_resets')) {
            return;
        }

        if (Schema::hasTable('resident_password_reset_tokens')) {
            return;
        }

        Schema::create('resident_password_resets', function (Blueprint $table) {
            $table->id('reset_id');
            $table->unsignedBigInteger('account_id')->index();
            $table->string('reset_token');
            $table->timestamp('requested_at');
            $table->timestamp('expires_at');
            $table->boolean('is_used')->default(false);
            $table->timestamp('used_at')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        // Never drop resident_password_resets; it is the authoritative table.
    }
};
