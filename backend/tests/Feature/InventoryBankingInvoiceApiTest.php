<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\InventoryBatch;
use App\Models\JournalEntry;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryBankingInvoiceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_inventory_loads_with_zero_summary_and_expiry_filter_accepts_query_days(): void
    {
        $this->authenticatedUser();

        $this->getJson('/api/inventory/products')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('summary.products', 0)
            ->assertJsonPath('summary.low_stock', 0)
            ->assertJsonPath('summary.stock_value', 0);

        $this->getJson('/api/inventory/expiring?days=60')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('days', 60);
    }

    public function test_product_sku_is_required_to_be_unique_within_the_active_business(): void
    {
        $this->authenticatedUser();
        $payload = [
            'name' => 'First SKU product',
            'sku' => 'sku-001',
            'gst_rate' => 0,
            'purchase_price' => 10,
            'selling_price' => 15,
            'is_service' => false,
            'track_stock' => true,
            'batch_tracking' => false,
        ];

        $this->postJson('/api/inventory/products', $payload)
            ->assertCreated()
            ->assertJsonPath('sku', 'SKU-001');

        $this->postJson('/api/inventory/products', [...$payload, 'name' => 'Duplicate SKU product'])
            ->assertUnprocessable()->assertJsonValidationErrors('sku');

        $this->postJson('/api/inventory/products', [...$payload, 'name' => 'Case variant SKU', 'sku' => 'Sku-001'])
            ->assertUnprocessable()->assertJsonValidationErrors('sku');
    }

    public function test_product_categories_are_unique_case_insensitively(): void
    {
        $this->authenticatedUser();

        $this->postJson('/api/inventory/categories', ['name' => 'Electronics'])->assertCreated();
        $this->postJson('/api/inventory/categories', ['name' => 'electronics'])
            ->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_opening_batch_details_require_batch_tracking(): void
    {
        $this->authenticatedUser();

        $this->postJson('/api/inventory/products', [
            'name' => 'Untracked batch product',
            'gst_rate' => 0,
            'track_stock' => true,
            'batch_tracking' => false,
            'opening_quantity' => 2,
            'opening_unit_cost' => 10,
            'opening_batch_number' => 'BATCH-1',
        ])->assertUnprocessable()->assertJsonValidationErrors('batch_tracking');
    }

    public function test_purchases_receive_stock_sales_consume_it_and_credit_notes_return_it(): void
    {
        $this->authenticatedUser();
        $product = $this->createProduct();

        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => null,
            'purchase_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'prices_include_gst' => false,
            'claim_input_tax_credit' => true,
            'reverse_charge' => false,
            'items' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 5,
                'unit_rate' => 10,
                'gst_rate' => 0,
                'discount' => 0,
            ]],
        ])->assertCreated()->json();

        $this->assertSame(5.0, (float) Product::findOrFail($product->id)->current_stock);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $purchase['current_journal_entry_id'],
            'account_code' => 'inventory',
            'debit' => '50.00',
        ]);
        $this->assertSame(1, InventoryBatch::query()->where('purchase_id', $purchase['id'])->count());

        $sale = $this->postJson('/api/sales', [
            'customer_id' => null,
            'sale_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'prices_include_gst' => false,
            'export_under_lut' => false,
            'items' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 3,
                'unit_rate' => 20,
                'gst_rate' => 0,
                'discount' => 0,
            ]],
        ])->assertCreated()->json();

        $this->assertSame(2.0, (float) Product::findOrFail($product->id)->current_stock);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $sale['current_journal_entry_id'],
            'account_code' => 'cost_of_goods_sold',
            'debit' => '30.00',
        ]);

        $this->postJson('/api/sales', [
            'customer_id' => null,
            'sale_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'prices_include_gst' => false,
            'export_under_lut' => false,
            'items' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 3,
                'unit_rate' => 20,
                'gst_rate' => 0,
                'discount' => 0,
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame(2.0, (float) Product::findOrFail($product->id)->current_stock);

        $note = $this->postJson('/api/credit-notes', [
            'sale_id' => $sale['id'],
            'note_date' => now()->toDateString(),
            'settlement_method' => 'cash',
            'items' => [['item_id' => $sale['items'][0]['id'], 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('note_type', 'credit')
            ->assertJsonPath('total', '20.00')
            ->json();

        $this->assertSame(3.0, (float) Product::findOrFail($product->id)->current_stock);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $note['current_journal_entry_id'],
            'account_code' => 'inventory',
            'debit' => '10.00',
        ]);
        $this->getJson('/api/invoices')->assertOk()->assertJsonPath('data.0.invoice_number', $sale['invoice_number']);
        $this->getJson('/api/credit-notes')->assertOk()->assertJsonPath('data.0.note_number', $note['note_number']);
        $this->deleteJson("/api/sales/{$sale['id']}")->assertUnprocessable()->assertJsonValidationErrors('sale');
        $this->assertSame(3, JournalEntry::query()->count());
    }

    public function test_purchase_debit_note_reduces_stock_and_reverses_input_tax(): void
    {
        $this->authenticatedUser();
        $product = $this->createProduct();
        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => null,
            'purchase_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'prices_include_gst' => false,
            'claim_input_tax_credit' => true,
            'reverse_charge' => false,
            'items' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 5,
                'unit_rate' => 10,
                'gst_rate' => 18,
                'discount' => 0,
            ]],
        ])->assertCreated()->json();

        $note = $this->postJson('/api/debit-notes', [
            'purchase_id' => $purchase['id'],
            'note_date' => now()->toDateString(),
            'settlement_method' => 'cash',
            'items' => [['item_id' => $purchase['items'][0]['id'], 'quantity' => 1]],
        ])->assertCreated()
            ->assertJsonPath('note_type', 'debit')
            ->assertJsonPath('total', '11.80')
            ->json();

        $this->assertSame(4.0, (float) Product::findOrFail($product->id)->current_stock);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $note['current_journal_entry_id'],
            'account_code' => 'inventory',
            'credit' => '10.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $note['current_journal_entry_id'],
            'account_code' => 'input_gst',
            'credit' => '1.80',
        ]);
        $this->putJson("/api/purchases/{$purchase['id']}", [])->assertUnprocessable()->assertJsonValidationErrors('purchase');
    }

    public function test_credit_note_can_be_edited_and_deleted_with_stock_and_journals_reversed(): void
    {
        $this->authenticatedUser();
        $product = $this->createProduct();
        $this->postJson('/api/purchases', [
            'supplier_id' => null,
            'purchase_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'prices_include_gst' => false,
            'claim_input_tax_credit' => true,
            'reverse_charge' => false,
            'items' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 5,
                'unit_rate' => 10,
                'gst_rate' => 0,
                'discount' => 0,
            ]],
        ])->assertCreated()->json();
        $sale = $this->postJson('/api/sales', [
            'customer_id' => null,
            'sale_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'prices_include_gst' => false,
            'export_under_lut' => false,
            'items' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 3,
                'unit_rate' => 20,
                'gst_rate' => 0,
                'discount' => 0,
            ]],
        ])->assertCreated()->json();
        $note = $this->postJson('/api/credit-notes', [
            'sale_id' => $sale['id'],
            'note_date' => today()->toDateString(),
            'settlement_method' => 'cash',
            'items' => [['item_id' => $sale['items'][0]['id'], 'quantity' => 1]],
        ])->assertCreated()->json();
        $originalJournalId = $note['current_journal_entry_id'];

        $updated = $this->putJson("/api/credit-notes/{$note['id']}", [
            'sale_id' => $sale['id'],
            'note_date' => today()->toDateString(),
            'settlement_method' => 'cash',
            'reason' => 'Updated return quantity',
            'items' => [['item_id' => $sale['items'][0]['id'], 'quantity' => 2]],
        ])->assertOk()
            ->assertJsonPath('id', $note['id'])
            ->assertJsonPath('total', '40.00')
            ->json();

        $this->assertSame(4.0, (float) Product::findOrFail($product->id)->current_stock);
        $this->assertDatabaseHas('journal_entries', [
            'reversal_of_id' => $originalJournalId,
            'source_type' => 'credit_note_reversal',
        ]);
        $this->deleteJson("/api/credit-notes/{$note['id']}")->assertOk();
        $this->assertSame(2.0, (float) Product::findOrFail($product->id)->current_stock);
        $this->getJson('/api/credit-notes')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('journal_entries', [
            'reversal_of_id' => $updated['current_journal_entry_id'],
            'source_type' => 'credit_note_reversal',
        ]);
    }

    public function test_debit_note_can_be_edited_and_deleted_and_is_not_accessible_from_another_business(): void
    {
        $user = $this->authenticatedUser();
        $product = $this->createProduct();
        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => null,
            'purchase_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'prices_include_gst' => false,
            'claim_input_tax_credit' => true,
            'reverse_charge' => false,
            'items' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 5,
                'unit_rate' => 10,
                'gst_rate' => 0,
                'discount' => 0,
            ]],
        ])->assertCreated()->json();
        $note = $this->postJson('/api/debit-notes', [
            'purchase_id' => $purchase['id'],
            'note_date' => today()->toDateString(),
            'settlement_method' => 'cash',
            'items' => [['item_id' => $purchase['items'][0]['id'], 'quantity' => 1]],
        ])->assertCreated()->json();
        $originalJournalId = $note['current_journal_entry_id'];

        $updated = $this->putJson("/api/debit-notes/{$note['id']}", [
            'purchase_id' => $purchase['id'],
            'note_date' => today()->toDateString(),
            'settlement_method' => 'cash',
            'items' => [['item_id' => $purchase['items'][0]['id'], 'quantity' => 2]],
        ])->assertOk()->assertJsonPath('total', '20.00')->json();
        $this->assertSame(3.0, (float) Product::findOrFail($product->id)->current_stock);
        $this->assertDatabaseHas('journal_entries', [
            'reversal_of_id' => $originalJournalId,
            'source_type' => 'debit_note_reversal',
        ]);

        $otherUser = User::factory()->create([
            'username' => 'other-owner-'.fake()->unique()->numberBetween(1000, 9999),
            'email' => fake()->unique()->safeEmail(),
        ]);
        $otherBusiness = $otherUser->businesses()->create([
            'name' => 'Other business',
            'gst_registration_type' => 'not_registered',
            'business_type' => 'retail_shop',
            'language' => 'en',
        ]);
        $otherUser->forceFill(['active_business_id' => $otherBusiness->id])->save();
        Sanctum::actingAs($otherUser);
        $this->deleteJson("/api/debit-notes/{$note['id']}")->assertNotFound();

        Sanctum::actingAs($user);
        $this->deleteJson("/api/debit-notes/{$note['id']}")->assertOk();
        $this->assertSame(5.0, (float) Product::findOrFail($product->id)->current_stock);
        $this->getJson('/api/debit-notes')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('journal_entries', [
            'reversal_of_id' => $updated['current_journal_entry_id'],
            'source_type' => 'debit_note_reversal',
        ]);
    }

    public function test_sale_uses_the_batch_with_the_nearest_expiry_first(): void
    {
        $this->authenticatedUser();
        $product = $this->createProduct();
        $purchase = $this->postJson('/api/purchases', [
            'supplier_id' => null,
            'purchase_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'prices_include_gst' => false,
            'claim_input_tax_credit' => true,
            'reverse_charge' => false,
            'items' => [
                [
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'quantity' => 1,
                    'unit_rate' => 20,
                    'gst_rate' => 0,
                    'discount' => 0,
                    'batch_number' => 'EARLIER',
                    'expiry_date' => now()->addDays(30)->toDateString(),
                ],
                [
                    'product_id' => $product->id,
                    'description' => $product->name,
                    'quantity' => 1,
                    'unit_rate' => 10,
                    'gst_rate' => 0,
                    'discount' => 0,
                    'batch_number' => 'LATER',
                    'expiry_date' => now()->addDays(60)->toDateString(),
                ],
            ],
        ])->assertCreated()->json();

        $sale = $this->postJson('/api/sales', [
            'customer_id' => null,
            'sale_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'prices_include_gst' => false,
            'export_under_lut' => false,
            'items' => [[
                'product_id' => $product->id,
                'description' => $product->name,
                'quantity' => 1,
                'unit_rate' => 30,
                'gst_rate' => 0,
                'discount' => 0,
            ]],
        ])->assertCreated()->json();

        $this->assertSame(
            0.0,
            (float) InventoryBatch::query()->where('purchase_id', $purchase['id'])->where('batch_number', 'EARLIER')->value('quantity_remaining')
        );
        $this->assertSame(
            1.0,
            (float) InventoryBatch::query()->where('purchase_id', $purchase['id'])->where('batch_number', 'LATER')->value('quantity_remaining')
        );
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $sale['current_journal_entry_id'],
            'account_code' => 'cost_of_goods_sold',
            'debit' => '20.00',
        ]);
    }

    public function test_bank_statement_import_masks_account_number_and_reconciles_matching_ledger_line(): void
    {
        $this->authenticatedUser();
        $account = $this->postJson('/api/banking/accounts', [
            'name' => 'Operating account',
            'bank_name' => 'Example Bank',
            'account_number' => '123456789012',
        ])->assertCreated()
            ->assertJsonPath('account_last4', '9012')
            ->assertJsonMissingPath('account_number')
            ->json();

        $this->postJson('/api/sales', [
            'customer_id' => null,
            'sale_date' => now()->toDateString(),
            'payment_method' => 'bank',
            'bank_account_id' => $account['id'],
            'prices_include_gst' => false,
            'export_under_lut' => false,
            'items' => [[
                'description' => 'Bank-settled sale',
                'quantity' => 1,
                'unit_rate' => 100,
                'gst_rate' => 0,
                'discount' => 0,
            ]],
        ])->assertCreated();

        $file = UploadedFile::fake()->createWithContent(
            'statement.csv',
            "Date,Description,Debit,Credit,Reference\n".now()->toDateString().",Customer deposit,,100,REF-1\n"
        );
        $this->post("/api/banking/accounts/{$account['id']}/statements", ['statement' => $file], [
            'Accept' => 'application/json',
        ])->assertCreated()->assertJsonPath('imported', 1);

        $reconciliation = $this->getJson("/api/banking/accounts/{$account['id']}/reconciliation")->assertOk()->json();
        $statement = $reconciliation['statement_transactions'][0];
        $bookLine = $reconciliation['book_transactions'][0];
        $matched = $this->postJson("/api/banking/accounts/{$account['id']}/reconciliation", [
            'statement_transaction_id' => $statement['id'],
            'journal_line_id' => $bookLine['id'],
        ])->assertOk()->json();
        $this->assertNotNull($matched['reconciled_at']);

        $this->getJson("/api/banking/accounts/{$account['id']}/reconciliation")
            ->assertOk()
            ->assertJsonCount(0, 'statement_transactions')
            ->assertJsonCount(1, 'reconciled_transactions');
    }

    private function authenticatedUser(): User
    {
        $user = User::factory()->create([
            'username' => 'inventory-owner-'.fake()->unique()->numberBetween(1000, 9999),
            'email' => fake()->unique()->safeEmail(),
        ]);
        $business = $user->businesses()->create([
            'name' => 'Inventory test business',
            'gst_registration_type' => 'not_registered',
            'business_type' => 'retail_shop',
            'language' => 'en',
        ]);
        $user->forceFill(['active_business_id' => $business->id])->save();
        Sanctum::actingAs($user);

        return $user;
    }

    private function createProduct(): Product
    {
        $response = $this->postJson('/api/inventory/products', [
            'name' => 'Test stock item',
            'sku' => 'INV-001',
            'unit_code' => 'pcs',
            'gst_rate' => 0,
            'purchase_price' => 10,
            'selling_price' => 20,
            'track_stock' => true,
            'is_service' => false,
            'batch_tracking' => true,
            'low_stock_threshold' => 1,
            'opening_quantity' => 0,
            'opening_unit_cost' => 0,
        ])->assertCreated();

        return Product::findOrFail($response->json('id'));
    }
}
