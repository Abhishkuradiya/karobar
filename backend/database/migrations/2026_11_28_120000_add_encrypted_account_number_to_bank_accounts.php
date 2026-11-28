<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bank_accounts') && ! Schema::hasColumn('bank_accounts', 'account_number')) {
            Schema::table('bank_accounts', function (Blueprint $table): void {
                $table->text('account_number')->nullable()->after('bank_name');
            });
        }

        if (Schema::hasTable('bank_accounts') && ! Schema::hasColumn('bank_accounts', 'account_number_hash')) {
            Schema::table('bank_accounts', function (Blueprint $table): void {
                $table->string('account_number_hash', 64)->nullable()->after('account_number');
                $table->index(['business_id', 'account_number_hash']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('bank_accounts') && Schema::hasColumn('bank_accounts', 'account_number')) {
            Schema::table('bank_accounts', function (Blueprint $table): void {
                $table->dropColumn('account_number');
            });
        }
    }
};
