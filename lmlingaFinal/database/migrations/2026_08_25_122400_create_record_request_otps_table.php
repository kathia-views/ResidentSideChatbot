<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Request-scoped OTP storage for household-record SMS verification.
 *
 * Live MySQL: do not auto-run this against the developer database.
 * PHPUnit sqlite :memory: creates the table via migrate:fresh.
 *
 * One-active-OTP-per-request is NOT a database unique constraint
 * (verified_at / invalidated_at / expires_at cannot safely express that).
 * Later generation/resend code must enforce it transactionally.
 *
 * FK delete: restrict. record_requests FKs in this project omit cascade;
 * health-record child tables use restrictOnDelete. OTP rows are audit
 * history and must not vanish if a request row is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('record_request_otps')) {
            return;
        }

        Schema::create('record_request_otps', function (Blueprint $table) {
            $table->id('otp_id');
            $table->unsignedBigInteger('request_id');
            $table->string('code_hash', 255);
            $table->string('destination_fingerprint', 255);
            $table->timestamp('expires_at');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->unsignedInteger('resend_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();

            $table->foreign('request_id')
                ->references('request_id')
                ->on('record_requests')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Intentionally empty. Do not DROP live MySQL OTP history.
        // PHPUnit uses migrate:fresh (drop-all + migrate up), not this down().
    }
};
