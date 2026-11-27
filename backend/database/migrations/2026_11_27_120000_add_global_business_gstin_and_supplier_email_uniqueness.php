<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table): void {
            $table->unique('gstin', 'businesses_gstin_unique');
        });

        Schema::table('suppliers', function (Blueprint $table): void {
            // deleted_at is included so a deleted supplier's email can be reused,
            // while only one active supplier per user may use an email address.
            $table->unique(['user_id', 'email', 'deleted_at'], 'suppliers_user_email_active_unique');
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->dropUnique('suppliers_user_email_active_unique');
        });
        Schema::table('businesses', function (Blueprint $table): void {
            $table->dropUnique('businesses_gstin_unique');
        });
    }
};
