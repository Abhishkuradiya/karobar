<?php

use App\Models\BankAccount;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bank_accounts') && ! Schema::hasColumn('bank_accounts', 'account_number_hash')) {
            Schema::table('bank_accounts', function (Blueprint $table): void {
                $table->string('account_number_hash', 64)->nullable()->after('account_number');
                $table->index(['business_id', 'account_number_hash']);
            });
        }

        BankAccount::query()
            ->whereNotNull('account_number')
            ->whereNull('account_number_hash')
            ->get()
            ->each(function (BankAccount $account): void {
                $number = $account->account_number;
                if ($number !== null && $number !== '') {
                    $account->forceFill(['account_number_hash' => hash('sha256', $number)])->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('bank_accounts') && Schema::hasColumn('bank_accounts', 'account_number_hash')) {
            Schema::table('bank_accounts', function (Blueprint $table): void {
                $table->dropIndex(['business_id', 'account_number_hash']);
                $table->dropColumn('account_number_hash');
            });
        }
    }
};
