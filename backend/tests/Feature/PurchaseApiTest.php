<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PurchaseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_purchase_totals_are_calculated_and_posted_to_balanced_journal(): void
    {
        $this->authenticatedUser();

        $purchase = $this->postJson('/api/purchases', $this->purchasePayload([
            'items' => [[
                'description' => 'Basmati rice 25kg',
                'quantity' => 2,
                'unit_rate' => 100,
                'gst_rate' => 18,
                'discount' => 10,
                'hsn_sac' => '1006',
            ]],
        ]))
            ->assertCreated()
            ->assertJsonPath('subtotal', '200.00')
            ->assertJsonPath('discount_total', '10.00')
            ->assertJsonPath('taxable_amount', '190.00')
            ->assertJsonPath('tax_amount', '34.20')
            ->assertJsonPath('total', '224.20')
            ->assertJsonPath('supplier_amount', '224.20')
            ->assertJsonPath('amount_paid', '224.20')
            ->assertJsonPath('balance_due', '0.00')
            ->json();

        $entryId = $purchase['current_journal_entry_id'];
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entryId,
            'account_code' => 'purchase_expense',
            'debit' => '190.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entryId,
            'account_code' => 'input_gst',
            'debit' => '34.20',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entryId,
            'account_code' => 'cash',
            'credit' => '224.20',
        ]);

        $dashboard = $this->getJson('/api/dashboard/summary')->assertOk()->json();
        $this->assertEquals(224.2, (float) $dashboard['today_purchases']);
        $this->assertSame(1, $dashboard['today_purchase_count']);
    }

    public function test_credit_purchase_and_reverse_charge_track_supplier_payable_and_gst_separately(): void
    {
        $user = $this->authenticatedUser();
        $supplier = $this->createSupplier($user, ['credit_days' => 30]);

        $purchase = $this->postJson('/api/purchases', $this->purchasePayload([
            'supplier_id' => $supplier->id,
            'purchase_date' => '2026-11-01',
            'payment_method' => 'credit',
            'reverse_charge' => true,
        ]))
            ->assertCreated()
            ->assertJsonPath('total', '118.00')
            ->assertJsonPath('supplier_amount', '100.00')
            ->assertJsonPath('amount_paid', '0.00')
            ->assertJsonPath('balance_due', '100.00')
            ->assertJsonPath('due_date', '2026-12-01')
            ->json();

        $entryId = $purchase['current_journal_entry_id'];
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entryId,
            'account_code' => 'accounts_payable',
            'credit' => '100.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entryId,
            'account_code' => 'input_gst',
            'debit' => '18.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entryId,
            'account_code' => 'reverse_charge_gst_payable',
            'credit' => '18.00',
        ]);
        $this->assertEquals(
            100,
            (float) $this->getJson('/api/suppliers')->json('data.0.outstanding_balance')
        );
    }

    public function test_editing_and_voiding_purchases_reverse_the_original_journal_entries(): void
    {
        $this->authenticatedUser();
        $purchase = $this->postJson('/api/purchases', $this->purchasePayload())
            ->assertCreated()
            ->json();
        $originalEntryId = $purchase['current_journal_entry_id'];

        $updated = $this->putJson("/api/purchases/{$purchase['id']}", $this->purchasePayload([
            'items' => [[
                'description' => 'Packaging',
                'quantity' => 1,
                'unit_rate' => 200,
                'gst_rate' => 0,
                'discount' => 0,
                'hsn_sac' => null,
            ]],
        ]))
            ->assertOk()
            ->assertJsonPath('total', '200.00')
            ->json();

        $this->assertDatabaseHas('journal_entries', [
            'reversal_of_id' => $originalEntryId,
            'source_type' => 'purchase_reversal',
        ]);

        $currentEntryId = $updated['current_journal_entry_id'];
        $this->deleteJson("/api/purchases/{$purchase['id']}")->assertNoContent();
        $this->assertSoftDeleted('purchases', ['id' => $purchase['id']]);
        $this->assertDatabaseHas('journal_entries', [
            'reversal_of_id' => $currentEntryId,
            'source_type' => 'purchase_reversal',
        ]);
        $this->assertSame(2, JournalEntry::query()->where('source_type', 'purchase')->count());
        $this->assertSame(2, JournalEntry::query()->where('source_type', 'purchase_reversal')->count());
    }

    public function test_credit_requires_supplier_and_rcm_requires_exclusive_pricing(): void
    {
        $this->authenticatedUser();

        $this->postJson('/api/purchases', $this->purchasePayload([
            'payment_method' => 'credit',
        ]))->assertUnprocessable()->assertJsonValidationErrors('supplier_id');

        $this->postJson('/api/purchases', $this->purchasePayload([
            'reverse_charge' => true,
            'prices_include_gst' => true,
        ]))->assertUnprocessable()->assertJsonValidationErrors('prices_include_gst');
    }

    public function test_purchase_records_are_isolated_by_active_business(): void
    {
        $owner = $this->authenticatedUser();
        $supplier = $this->createSupplier($owner);
        $purchase = $this->postJson('/api/purchases', $this->purchasePayload([
            'supplier_id' => $supplier->id,
        ]))->assertCreated()->json();

        $otherUser = User::factory()->create([
            'username' => 'purchase-other-owner',
            'email' => 'purchase-other-owner@example.test',
        ]);
        $this->createBusiness($otherUser);
        Sanctum::actingAs($otherUser);

        $this->getJson('/api/purchases')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/purchases/{$purchase['id']}")->assertNotFound();
        $this->deleteJson("/api/purchases/{$purchase['id']}")->assertNotFound();
        $this->postJson('/api/purchases', $this->purchasePayload([
            'supplier_id' => $supplier->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('supplier_id');
    }

    private function authenticatedUser(): User
    {
        $user = User::factory()->create([
            'username' => 'purchase-owner-'.fake()->unique()->numberBetween(1000, 9999),
            'email' => fake()->unique()->safeEmail(),
        ]);
        $this->createBusiness($user);
        Sanctum::actingAs($user);

        return $user;
    }

    private function createBusiness(User $user): Business
    {
        $business = $user->businesses()->create([
            'name' => 'Purchase test business',
            'gst_registration_type' => 'not_registered',
            'business_type' => 'retail_shop',
            'language' => 'en',
        ]);
        $user->forceFill(['active_business_id' => $business->id])->save();

        return $business;
    }

    private function createSupplier(User $user, array $overrides = []): Supplier
    {
        return $user->suppliers()->create(array_replace([
            'business_id' => $user->active_business_id,
            'name' => 'Test Supplier',
            'credit_days' => 0,
            'msme_type' => 'not_known',
        ], $overrides));
    }

    private function purchasePayload(array $overrides = []): array
    {
        return array_replace([
            'supplier_id' => null,
            'purchase_date' => now()->toDateString(),
            'supplier_bill_number' => null,
            'supplier_bill_date' => null,
            'payment_method' => 'cash',
            'prices_include_gst' => false,
            'claim_input_tax_credit' => true,
            'reverse_charge' => false,
            'notes' => null,
            'items' => [[
                'description' => 'Test product',
                'quantity' => 1,
                'unit_rate' => 100,
                'gst_rate' => 18,
                'discount' => 0,
                'hsn_sac' => null,
            ]],
        ], $overrides);
    }
}
