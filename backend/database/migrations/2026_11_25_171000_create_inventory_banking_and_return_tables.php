<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_categories')) {
            Schema::create('product_categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('name', 80);
                $table->timestamps();

                $table->unique(['business_id', 'name']);
            });
        }

        if (! Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
                $table->string('name', 180);
                $table->string('sku', 60)->nullable();
                $table->string('barcode', 80)->nullable();
                $table->string('hsn_sac', 20)->nullable();
                $table->string('unit_code', 8)->nullable();
                $table->decimal('gst_rate', 5, 2)->default(0);
                $table->decimal('purchase_price', 14, 2)->default(0);
                $table->decimal('selling_price', 14, 2)->default(0);
                $table->boolean('is_service')->default(false);
                $table->boolean('track_stock')->default(true);
                $table->boolean('batch_tracking')->default(false);
                $table->decimal('low_stock_threshold', 12, 3)->nullable();
                $table->decimal('current_stock', 12, 3)->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['business_id', 'sku']);
                $table->unique(['business_id', 'barcode']);
                $table->index(['business_id', 'name']);
                $table->index(['business_id', 'current_stock']);
            });
        }

        if (! Schema::hasTable('inventory_batches')) {
            Schema::create('inventory_batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained()->restrictOnDelete();
                $table->foreignId('purchase_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('purchase_item_id')->nullable()->constrained()->nullOnDelete();
                $table->string('batch_number', 80)->nullable();
                $table->date('received_date');
                $table->date('expiry_date')->nullable();
                $table->decimal('quantity_received', 12, 3);
                $table->decimal('quantity_remaining', 12, 3);
                $table->decimal('unit_cost', 14, 4);
                $table->timestamp('reversed_at')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'product_id', 'expiry_date']);
            });
        }

        if (! Schema::hasTable('inventory_movements')) {
            Schema::create('inventory_movements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
                $table->foreignId('sale_item_id')->nullable()->constrained('sale_items')->nullOnDelete();
                $table->foreignId('purchase_item_id')->nullable()->constrained('purchase_items')->nullOnDelete();
                $table->string('movement_type', 30);
                $table->string('source_type', 40)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->decimal('quantity_delta', 12, 3);
                $table->decimal('unit_cost', 14, 4)->default(0);
                $table->string('notes', 255)->nullable();
                $table->timestamp('reversed_at')->nullable();
                $table->timestamps();

                $table->index(['business_id', 'product_id', 'created_at']);
                $table->index(['source_type', 'source_id']);
            });
        }

        if (! Schema::hasTable('bank_accounts')) {
            Schema::create('bank_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->string('name', 100);
                $table->string('bank_name', 100);
                $table->string('account_last4', 4)->nullable();
                $table->string('ifsc', 11)->nullable();
                $table->string('upi_id', 100)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['business_id', 'is_active']);
            });
        }

        if (! Schema::hasColumn('sales', 'bank_account_id')) {
            Schema::table('sales', function (Blueprint $table) {
                $table->foreignId('bank_account_id')->nullable()->after('customer_id')
                    ->constrained('bank_accounts')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('purchases', 'bank_account_id')) {
            Schema::table('purchases', function (Blueprint $table) {
                $table->foreignId('bank_account_id')->nullable()->after('supplier_id')
                    ->constrained('bank_accounts')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('expenses', 'bank_account_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->foreignId('bank_account_id')->nullable()->after('supplier_id')
                    ->constrained('bank_accounts')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('journal_lines', 'bank_account_id')) {
            Schema::table('journal_lines', function (Blueprint $table) {
                $table->foreignId('bank_account_id')->nullable()
                    ->after('account_code')
                    ->constrained('bank_accounts')
                    ->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('journal_lines', 'reconciled_at')) {
            Schema::table('journal_lines', function (Blueprint $table) {
                $table->timestamp('reconciled_at')->nullable()->after('credit');
            });
        }

        if (! Schema::hasTable('bank_statement_transactions')) {
            Schema::create('bank_statement_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('bank_account_id')->constrained()->cascadeOnDelete();
                $table->foreignId('matched_journal_line_id')->nullable()->unique()
                    ->constrained('journal_lines')
                    ->nullOnDelete();
                $table->date('transaction_date');
                $table->string('description', 255);
                $table->string('reference', 120)->nullable();
                $table->decimal('amount', 14, 2);
                $table->string('fingerprint', 64);
                $table->timestamp('reconciled_at')->nullable();
                $table->timestamps();

                $table->unique(['bank_account_id', 'fingerprint']);
            });
        }
        if (! Schema::hasIndex('bank_statement_transactions', 'bank_stmt_biz_acct_date_idx')) {
            Schema::table('bank_statement_transactions', function (Blueprint $table) {
                $table->index(['business_id', 'bank_account_id', 'transaction_date'], 'bank_stmt_biz_acct_date_idx');
            });
        }
        if (! Schema::hasIndex('bank_statement_transactions', 'bank_stmt_biz_reconciled_idx')) {
            Schema::table('bank_statement_transactions', function (Blueprint $table) {
                $table->index(['business_id', 'reconciled_at'], 'bank_stmt_biz_reconciled_idx');
            });
        }

        if (! Schema::hasColumn('sale_items', 'product_id')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->foreignId('product_id')->nullable()->after('sale_id')
                    ->constrained()->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('sale_items', 'inventory_cost')) {
            Schema::table('sale_items', function (Blueprint $table) {
                $table->decimal('inventory_cost', 14, 2)->default(0)->after('line_total');
            });
        }

        if (! Schema::hasColumn('purchase_items', 'product_id')) {
            Schema::table('purchase_items', function (Blueprint $table) {
                $table->foreignId('product_id')->nullable()->after('purchase_id')
                    ->constrained()->nullOnDelete();
            });
        }
        if (! Schema::hasColumn('purchase_items', 'batch_number')) {
            Schema::table('purchase_items', function (Blueprint $table) {
                $table->string('batch_number', 80)->nullable()->after('hsn_sac');
            });
        }
        if (! Schema::hasColumn('purchase_items', 'expiry_date')) {
            Schema::table('purchase_items', function (Blueprint $table) {
                $table->date('expiry_date')->nullable()->after('batch_number');
            });
        }

        if (! Schema::hasTable('return_notes')) {
            Schema::create('return_notes', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('business_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sale_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('purchase_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId('current_journal_entry_id')->nullable()
                    ->constrained('journal_entries')->nullOnDelete();
                $table->string('note_type', 12);
                $table->string('note_number', 40)->unique();
                $table->string('counterparty_name_snapshot', 150);
                $table->date('note_date');
                $table->string('settlement_method', 16);
                $table->string('reason', 255)->nullable();
                $table->decimal('taxable_amount', 14, 2);
                $table->decimal('tax_amount', 14, 2);
                $table->decimal('total', 14, 2);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['business_id', 'note_type', 'note_date']);
                $table->index(['sale_id', 'note_type']);
                $table->index(['purchase_id', 'note_type']);
            });
        }

        if (! Schema::hasTable('return_note_items')) {
            Schema::create('return_note_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('return_note_id')->constrained()->cascadeOnDelete();
                $table->foreignId('original_sale_item_id')->nullable()->constrained('sale_items')->restrictOnDelete();
                $table->foreignId('original_purchase_item_id')->nullable()->constrained('purchase_items')->restrictOnDelete();
                $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
                $table->string('description', 255);
                $table->decimal('quantity', 12, 3);
                $table->decimal('unit_rate', 14, 2);
                $table->decimal('gst_rate', 5, 2);
                $table->decimal('taxable_amount', 14, 2);
                $table->decimal('tax_amount', 14, 2);
                $table->decimal('line_total', 14, 2);
                $table->decimal('inventory_cost', 14, 2)->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('return_note_items');
        Schema::dropIfExists('return_notes');
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn(['batch_number', 'expiry_date']);
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_id');
            $table->dropColumn('inventory_cost');
        });
        Schema::dropIfExists('bank_statement_transactions');
        Schema::table('journal_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
            $table->dropColumn('reconciled_at');
        });
        Schema::table('purchases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
        });
        Schema::table('expenses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
        });
        Schema::dropIfExists('bank_accounts');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_batches');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
