<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scan_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('file_path', 500);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('status', 20)->default('needs_review');
            $table->timestamps();

            $table->index(['business_id', 'created_at']);
            $table->index(['user_id', 'business_id', 'status']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('scan_bill_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('current_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->string('expense_number', 40)->unique();
            $table->string('category', 40);
            $table->string('supplier_name_snapshot', 150)->nullable();
            $table->date('expense_date');
            $table->string('payment_method', 16);
            $table->decimal('amount', 14, 2);
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->boolean('claim_input_tax_credit')->default(false);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->decimal('amount_paid', 14, 2);
            $table->decimal('balance_due', 14, 2);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'expense_date']);
            $table->index(['business_id', 'supplier_id', 'expense_date']);
            $table->index(['business_id', 'category', 'expense_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('scan_bills');
    }
};
