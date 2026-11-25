<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chart_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('display_code', 20);
            $table->string('account_code', 40);
            $table->string('name', 120);
            $table->string('account_type', 20);
            $table->string('group_name', 80);
            $table->timestamps();

            $table->unique(['business_id', 'display_code'], 'chart_accounts_biz_display_unique');
            $table->unique(['business_id', 'account_code'], 'chart_accounts_biz_code_unique');
        });

        Schema::create('cash_tallies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->date('counted_date');
            $table->decimal('book_amount', 14, 2);
            $table->decimal('counted_amount', 14, 2);
            $table->decimal('difference', 14, 2);
            $table->string('note', 255)->nullable();
            $table->foreignId('journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->unique(['business_id', 'counted_date'], 'cash_tallies_biz_date_unique');
        });

        Schema::create('data_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('import_type', 40);
            $table->string('original_name', 255);
            $table->string('status', 16)->default('previewed');
            $table->json('preview_data');
            $table->json('result_data')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['business_id', 'created_at'], 'data_imports_biz_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_imports');
        Schema::dropIfExists('cash_tallies');
        Schema::dropIfExists('chart_accounts');
    }
};
