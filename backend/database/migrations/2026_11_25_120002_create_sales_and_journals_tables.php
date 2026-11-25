<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->string('description', 255);
            $table->foreignId('reversal_of_id')->nullable()
                ->constrained('journal_entries')->restrictOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'entry_date']);
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()
                ->constrained()->nullOnDelete();
            $table->foreignId('current_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->string('invoice_number', 40)->unique();
            $table->string('customer_name_snapshot', 150);
            $table->date('sale_date');
            $table->string('payment_method', 16);
            $table->boolean('prices_include_gst')->default(false);
            $table->boolean('export_under_lut')->default(false);
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount_total', 14, 2);
            $table->decimal('taxable_amount', 14, 2);
            $table->decimal('tax_amount', 14, 2);
            $table->decimal('total', 14, 2);
            $table->decimal('amount_paid', 14, 2);
            $table->decimal('balance_due', 14, 2);
            $table->date('due_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'sale_date']);
            $table->index(['user_id', 'customer_id', 'sale_date']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->string('description', 255);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_rate', 14, 2);
            $table->decimal('gst_rate', 5, 2);
            $table->decimal('discount', 14, 2)->default(0);
            $table->string('hsn_sac', 20)->nullable();
            $table->decimal('taxable_amount', 14, 2);
            $table->decimal('tax_amount', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->string('account_code', 40);
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['journal_entry_id', 'account_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('journal_entries');
    }
};
