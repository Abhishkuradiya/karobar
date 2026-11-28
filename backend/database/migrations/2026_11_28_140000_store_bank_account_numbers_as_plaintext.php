<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('bank_accounts')
            ->whereNotNull('account_number')
            ->select(['id', 'account_number'])
            ->orderBy('id')
            ->get()
            ->each(function (object $account): void {
                $value = (string) $account->account_number;

                try {
                    $decrypted = Crypt::decryptString($value);
                    if ($decrypted !== '') {
                        DB::table('bank_accounts')
                            ->where('id', $account->id)
                            ->update(['account_number' => $decrypted]);
                    }
                } catch (\Throwable) {
                    // Values already stored as plain account numbers need no conversion.
                }
            });
    }

    public function down(): void
    {
        DB::table('bank_accounts')
            ->whereNotNull('account_number')
            ->select(['id', 'account_number'])
            ->orderBy('id')
            ->get()
            ->each(function (object $account): void {
                DB::table('bank_accounts')
                    ->where('id', $account->id)
                    ->update(['account_number' => Crypt::encryptString((string) $account->account_number)]);
            });
    }
};
