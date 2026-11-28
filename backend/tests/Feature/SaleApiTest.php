<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SaleApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_totals_are_calculated_server_side_and_post_as_balanced_journal(): void
    {
        $user = $this->authenticatedUser();

        $response = $this->postJson('/api/sales', $this->salePayload([
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
            ->assertJsonPath('amount_paid', '224.20')
            ->assertJsonPath('balance_due', '0.00')
            ->assertJsonPath('invoice_number', 'INV0001');

        $entryId = $response->json('current_journal_entry_id');
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entryId,
            'account_code' => 'cash',
            'debit' => '224.20',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entryId,
            'account_code' => 'sales_revenue',
            'credit' => '190.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entryId,
            'account_code' => 'gst_payable',
            'credit' => '34.20',
        ]);
    }

    public function test_credit_sale_requires_a_customer_and_respects_the_credit_limit(): void
    {
        $user = $this->authenticatedUser();

        $this->postJson('/api/sales', $this->salePayload([
            'payment_method' => 'credit',
        ]))->assertUnprocessable()->assertJsonValidationErrors('customer_id');

        $customer = $user->customers()->create([
            'business_id' => $user->active_business_id,
            'name' => 'Credit customer',
            'credit_days' => 10,
            'credit_limit' => 100,
        ]);

        $this->postJson('/api/sales', $this->salePayload([
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
        ]))->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        $this->assertDatabaseCount('sales', 0);
    }

    public function test_credit_sale_records_receivable_and_customer_due_date(): void
    {
        $user = $this->authenticatedUser();
        $customer = $user->customers()->create([
            'business_id' => $user->active_business_id,
            'name' => 'Trusted credit customer',
            'credit_days' => 10,
            'credit_limit' => 500,
        ]);

        $response = $this->postJson('/api/sales', $this->salePayload([
            'customer_id' => $customer->id,
            'payment_method' => 'credit',
        ]))
            ->assertCreated()
            ->assertJsonPath('payment_method', 'credit')
            ->assertJsonPath('amount_paid', '0.00')
            ->assertJsonPath('balance_due', '118.00')
            ->assertJsonPath('due_date', now()->addDays(10)->toDateString());

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $response->json('current_journal_entry_id'),
            'account_code' => 'accounts_receivable',
            'debit' => '118.00',
        ]);
    }

    public function test_sales_and_customer_references_are_scoped_to_the_owner(): void
    {
        $owner = $this->authenticatedUser();
        $customer = $owner->customers()->create([
            'business_id' => $owner->active_business_id,
            'name' => 'Owner customer',
            'credit_days' => 0,
        ]);
        $sale = $this->postJson('/api/sales', $this->salePayload([
            'customer_id' => $customer->id,
        ]))->assertCreated()->json();

        $otherUser = User::factory()->create([
            'username' => 'another-sales-owner',
            'email' => 'another-sales-owner@example.test',
        ]);
        $this->createBusiness($otherUser);
        Sanctum::actingAs($otherUser);

        $this->getJson('/api/sales')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/sales/{$sale['id']}")->assertNotFound();
        $this->deleteJson("/api/sales/{$sale['id']}")->assertNotFound();
        $this->postJson('/api/sales', $this->salePayload([
            'customer_id' => $customer->id,
        ]))->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    }

    public function test_updating_and_voiding_sales_reverse_the_previous_journal_entries(): void
    {
        $this->authenticatedUser();
        $sale = $this->postJson('/api/sales', $this->salePayload())
            ->assertCreated()
            ->json();
        $originalEntryId = $sale['current_journal_entry_id'];

        $updated = $this->putJson("/api/sales/{$sale['id']}", $this->salePayload([
            'items' => [[
                'description' => 'Basmati rice 25kg',
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
            'id' => $originalEntryId,
        ]);
        $this->assertDatabaseHas('journal_entries', [
            'reversal_of_id' => $originalEntryId,
            'source_type' => 'sale_reversal',
        ]);

        $currentEntryId = $updated['current_journal_entry_id'];
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $currentEntryId,
            'account_code' => 'cash',
            'debit' => '200.00',
        ]);
        $this->assertSame(
            0,
            JournalEntry::query()->findOrFail($currentEntryId)->lines()
                ->selectRaw('SUM(debit) as debit_total, SUM(credit) as credit_total')
                ->get()
                ->filter(fn ($totals) => round((float) $totals->debit_total, 2) !== round((float) $totals->credit_total, 2))
                ->count()
        );
        $this->deleteJson("/api/sales/{$sale['id']}")->assertNoContent();
        $this->assertSoftDeleted('sales', ['id' => $sale['id']]);
        $this->assertDatabaseHas('journal_entries', [
            'reversal_of_id' => $currentEntryId,
            'source_type' => 'sale_reversal',
        ]);
        $this->assertSame(0, Sale::query()->count());
    }

    public function test_prices_including_gst_and_lut_are_calculated_correctly(): void
    {
        $this->authenticatedUser();

        $this->postJson('/api/sales', $this->salePayload([
            'prices_include_gst' => true,
            'items' => [[
                'description' => 'Tax inclusive item',
                'quantity' => 1,
                'unit_rate' => 118,
                'gst_rate' => 18,
                'discount' => 0,
                'hsn_sac' => null,
            ]],
        ]))
            ->assertCreated()
            ->assertJsonPath('taxable_amount', '100.00')
            ->assertJsonPath('tax_amount', '18.00')
            ->assertJsonPath('total', '118.00');

        $this->postJson('/api/sales', $this->salePayload([
            'export_under_lut' => true,
            'items' => [[
                'description' => 'LUT export item',
                'quantity' => 1,
                'unit_rate' => 118,
                'gst_rate' => 18,
                'discount' => 0,
                'hsn_sac' => null,
            ]],
        ]))
            ->assertCreated()
            ->assertJsonPath('taxable_amount', '118.00')
            ->assertJsonPath('tax_amount', '0.00')
            ->assertJsonPath('total', '118.00');

        $this->assertSame(2, JournalEntry::query()->count());
    }

    private function authenticatedUser(): User
    {
        $user = User::factory()->create([
            'username' => 'sales-owner-'.fake()->unique()->numberBetween(1000, 9999),
            'email' => fake()->unique()->safeEmail(),
        ]);
        $this->createBusiness($user);
        Sanctum::actingAs($user);

        return $user;
    }

    private function createBusiness(User $user): Business
    {
        $business = $user->businesses()->create([
            'name' => 'Sales test business',
            'gst_registration_type' => 'not_registered',
            'business_type' => 'retail_shop',
            'language' => 'en',
        ]);
        $user->forceFill(['active_business_id' => $business->id])->save();

        return $business;
    }

    private function salePayload(array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => null,
            'sale_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'prices_include_gst' => false,
            'export_under_lut' => false,
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
