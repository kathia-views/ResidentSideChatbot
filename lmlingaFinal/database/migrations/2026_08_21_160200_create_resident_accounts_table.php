<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Baseline chatbot login accounts.
 *
 * Developer MySQL already has this table from a legacy SQL schema.
 * If the table exists, this migration records as run and must not ALTER it.
 * Fresh installs and phpunit sqlite :memory: create the table here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('resident_accounts')) {
            return;
        }

        Schema::create('resident_accounts', function (Blueprint $table) {
            $table->id('account_id');
            $table->string('first_name', 100);
            $table->string('middle_name', 100)->nullable();
            $table->string('last_name', 100);
            $table->string('zone_purok', 20)->nullable();
            $table->string('email', 150)->unique();
            $table->string('password', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Intentionally empty. This table may already exist on the shared
        // developer MySQL with live chatbot accounts. Rollback must not DROP it.
        // PHPUnit uses migrate:fresh (drop-all + migrate up), not this down().
    }
};
