<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_accountant_settings', function (Blueprint $table): void {
            $table->dropForeign(['business_id']);
            $table->dropUnique(['business_id']);
            $table->dropColumn('business_id');
        });
    }

    public function down(): void
    {
        Schema::table('ai_accountant_settings', function (Blueprint $table): void {
            $table->foreignId('business_id')->nullable()->constrained()->nullOnDelete();
            $table->unique('business_id');
        });
    }
};
