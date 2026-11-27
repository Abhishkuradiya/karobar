<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            // Allow reuse after soft deletion, but prevent duplicate active emails per user.
            $table->unique(['user_id', 'email', 'deleted_at'], 'customers_user_email_active_unique');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table): void {
            $table->dropUnique('customers_user_email_active_unique');
        });
    }
};
