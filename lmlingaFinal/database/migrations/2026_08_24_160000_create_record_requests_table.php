<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Represents live MySQL `record_requests` for sqlite tests / fresh installs.
 *
 * Developer MySQL already has this table. If it exists, this migration is a
 * no-op and must not ALTER columns, indexes, or foreign keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('record_requests')) {
            return;
        }

        Schema::create('record_requests', function (Blueprint $table) {
            $table->id('request_id');
            $table->unsignedBigInteger('account_id');
            $table->string('household_no_submitted', 50);
            $table->string('zone_submitted', 20);
            $table->string('relationship_submitted', 50);
            $table->string('first_name_submitted', 100);
            $table->string('middle_name_submitted', 100);
            $table->string('last_name_submitted', 100);
            $table->string('mobile_number_submitted', 20);
            $table->string('email_submitted', 150);
            $table->string('submitter_ip', 45)->nullable();
            $table->unsignedBigInteger('matched_resident_id')->nullable();
            $table->string('status', 32)->default('Pending');
            $table->text('decision_reason')->nullable();
            $table->timestamp('evaluated_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->foreign('account_id')
                ->references('account_id')
                ->on('resident_accounts');
        });
    }

    public function down(): void
    {
        // Intentionally empty. Live MySQL already owns this table.
        // PHPUnit uses migrate:fresh (drop-all + migrate up), not this down().
    }
};
