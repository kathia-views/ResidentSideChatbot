<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional chatbot-account ↔ official-resident link.
 *
 * Live MySQL `residents` PK is `resident_id` (legacy SQL).
 * Laravel sqlite tests create `residents.id`.
 * The FK target is chosen from whichever PK column exists.
 *
 * Idempotent: a prior failed run may already have added the nullable
 * `resident_id` column without the unique index or foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('resident_accounts')) {
            return;
        }

        if (! Schema::hasColumn('resident_accounts', 'resident_id')) {
            Schema::table('resident_accounts', function (Blueprint $table) {
                $table->unsignedBigInteger('resident_id')->nullable();
            });
        }

        if (! $this->hasUniqueOnResidentId()) {
            Schema::table('resident_accounts', function (Blueprint $table) {
                $table->unique('resident_id');
            });
        }

        $ownerKey = $this->residentsPrimaryKeyColumn();

        if ($this->hasForeignKeyOnResidentId($ownerKey)) {
            return;
        }

        if ($this->hasAnyForeignKeyOnResidentId()) {
            Schema::table('resident_accounts', function (Blueprint $table) {
                foreach (Schema::getForeignKeys('resident_accounts') as $foreignKey) {
                    if (($foreignKey['columns'] ?? []) === ['resident_id']) {
                        $table->dropForeign($foreignKey['name']);
                    }
                }
            });
        }

        Schema::table('resident_accounts', function (Blueprint $table) use ($ownerKey) {
            $table->foreign('resident_id')
                ->references($ownerKey)
                ->on('residents')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('resident_accounts') || ! Schema::hasColumn('resident_accounts', 'resident_id')) {
            return;
        }

        Schema::table('resident_accounts', function (Blueprint $table) {
            foreach (Schema::getForeignKeys('resident_accounts') as $foreignKey) {
                if (($foreignKey['columns'] ?? []) === ['resident_id']) {
                    $table->dropForeign($foreignKey['name']);
                }
            }

            foreach (Schema::getIndexes('resident_accounts') as $index) {
                if (! empty($index['unique']) && ($index['columns'] ?? []) === ['resident_id']) {
                    $table->dropUnique($index['name']);
                }
            }

            $table->dropColumn('resident_id');
        });
    }

    private function residentsPrimaryKeyColumn(): string
    {
        if (Schema::hasTable('residents') && Schema::hasColumn('residents', 'resident_id')) {
            return 'resident_id';
        }

        return 'id';
    }

    private function hasUniqueOnResidentId(): bool
    {
        foreach (Schema::getIndexes('resident_accounts') as $index) {
            if (! empty($index['unique']) && ($index['columns'] ?? []) === ['resident_id']) {
                return true;
            }
        }

        return false;
    }

    private function hasAnyForeignKeyOnResidentId(): bool
    {
        foreach (Schema::getForeignKeys('resident_accounts') as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === ['resident_id']) {
                return true;
            }
        }

        return false;
    }

    private function hasForeignKeyOnResidentId(string $ownerKey): bool
    {
        foreach (Schema::getForeignKeys('resident_accounts') as $foreignKey) {
            if (
                ($foreignKey['columns'] ?? []) === ['resident_id']
                && ($foreignKey['foreign_table'] ?? null) === 'residents'
                && ($foreignKey['foreign_columns'] ?? []) === [$ownerKey]
            ) {
                return true;
            }
        }

        return false;
    }
};
