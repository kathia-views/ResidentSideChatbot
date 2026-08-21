<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Maps Final SQL `user_management` into Laravel's canonical `users` table.
 * Keeps one staff credential store (email/username/password) for Auth.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('photo_path', 255)->nullable()->after('id');
            $table->string('first_name', 100)->nullable()->after('photo_path');
            $table->string('middle_name', 100)->nullable()->after('first_name');
            $table->string('last_name', 100)->nullable()->after('middle_name');
            $table->string('suffix', 20)->nullable()->after('last_name');
            $table->string('sex', 16)->nullable()->after('suffix');
            $table->date('date_of_birth')->nullable()->after('sex');
            $table->string('civil_status', 50)->nullable()->after('date_of_birth');
            $table->string('nationality', 50)->nullable()->after('civil_status');
            $table->string('mobile_number', 20)->nullable()->after('nationality');
            $table->string('house_no', 20)->nullable()->after('mobile_number');
            $table->string('street', 150)->nullable()->after('house_no');
            $table->string('purok_zone', 20)->nullable()->after('street');
            $table->string('barangay', 100)->nullable()->after('purok_zone');
            $table->string('municipality_city', 100)->nullable()->after('barangay');
            $table->string('province', 100)->nullable()->after('municipality_city');
            $table->string('zip_code', 10)->nullable()->after('province');
            $table->string('username', 100)->nullable()->unique()->after('email');
            $table->string('status', 16)->default('Active')->after('password');
            $table->boolean('must_change_password')->default(true)->after('status');
            $table->foreignId('created_by')
                ->nullable()
                ->after('must_change_password')
                ->constrained('users')
                ->restrictOnDelete();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropIndex(['status']);
            $table->dropUnique(['username']);
            $table->dropColumn([
                'photo_path',
                'first_name',
                'middle_name',
                'last_name',
                'suffix',
                'sex',
                'date_of_birth',
                'civil_status',
                'nationality',
                'mobile_number',
                'house_no',
                'street',
                'purok_zone',
                'barangay',
                'municipality_city',
                'province',
                'zip_code',
                'username',
                'status',
                'must_change_password',
            ]);
        });
    }
};
