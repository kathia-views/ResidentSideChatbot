<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DB-05 Phase 3 — nullable resident_id FK bridge on death_requests.
 * Preserves household_no + member_id string identifiers.
 * FK index satisfies the Phase 3 indexing requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('death_requests', function (Blueprint $table) {
            $table->foreignId('resident_id')
                ->nullable()
                ->after('member_id')
                ->constrained('residents')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('death_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('resident_id');
        });
    }
};
