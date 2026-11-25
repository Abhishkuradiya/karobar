<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('mobile', 30)->nullable();
            $table->unsignedSmallInteger('credit_days')->default(0);
            $table->string('msme_type', 16)->default('not_known');
            $table->string('udyam_number', 30)->nullable();
            $table->string('gstin', 15)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('email', 254)->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'name']);
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('current_journal_entry_id')->nullable()
                ->constrained('journal_entries')->nullOnDelete();
            $table->string('purchase_number', 40)->unique();
            $table->string('supplier_bill_number', 60)->nullable();
            $table->string('supplier_name_snapshot', 150);
            $table->date('purchase_date');
            $table->date('supplier_bill_date')->nullable();
            $table->string('payment_method', 16);
            $table->boolean('prices_include_gst')->default(false);
            $table->boolean('claim_input_tax_credit')->default(true);
            $table->boolean('reverse_charge')->default(false);
            $table->text('notes')->nullable();
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount_total', 14, 2);
            $table->decimal('taxable_amount', 14, 2);
            $table->decimal('tax_amount', 14, 2);
            $table->decimal('total', 14, 2);
            $table->decimal('supplier_amount', 14, 2);
            $table->decimal('amount_paid', 14, 2);
            $table->decimal('balance_due', 14, 2);
            $table->date('due_date')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(
                ['business_id', 'supplier_id', 'supplier_bill_number'],
                'purchases_supplier_bill_unique'
            );
            $table->index(['business_id', 'purchase_date']);
            $table->index(['business_id', 'supplier_id', 'purchase_date']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
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
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
    }
};
