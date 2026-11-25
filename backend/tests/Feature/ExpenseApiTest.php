<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ExpenseApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_expense_calculates_gst_and_posts_a_balanced_journal(): void
    {
        $this->authenticatedUser();

        $expense = $this->postJson('/api/expenses', $this->expensePayload([
            'amount' => 1000,
            'gst_rate' => 18,
            'claim_input_tax_credit' => true,
        ]))
            ->assertCreated()
            ->assertJsonPath('amount', '1000.00')
            ->assertJsonPath('tax_amount', '180.00')
            ->assertJsonPath('total', '1180.00')
            ->assertJsonPath('amount_paid', '1180.00')
            ->assertJsonPath('balance_due', '0.00')
            ->json();

        $entryId = $expense['current_journal_entry_id'];
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entryId,
            'account_code' => 'expense_rent',
            'debit' => '1000.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entryId,
            'account_code' => 'input_gst',
            'debit' => '180.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $entryId,
            'account_code' => 'cash',
            'credit' => '1180.00',
        ]);

        $dashboard = $this->getJson('/api/dashboard/summary')->assertOk()->json();
        $this->assertEquals(1180, (float) $dashboard['today_expenses']);
        $this->assertSame(1, $dashboard['today_expense_count']);
    }

    public function test_unpaid_expense_updates_supplier_payables_and_supplier_balance(): void
    {
        $user = $this->authenticatedUser();
        $supplier = $user->suppliers()->create([
            'business_id' => $user->active_business_id,
            'name' => 'Expense Supplier',
            'credit_days' => 0,
            'msme_type' => 'not_known',
        ]);

        $expense = $this->postJson('/api/expenses', $this->expensePayload([
            'supplier_id' => $supplier->id,
            'category' => 'electricity',
            'amount' => 200,
            'gst_rate' => 5,
            'claim_input_tax_credit' => false,
            'payment_method' => 'unpaid',
        ]))
            ->assertCreated()
            ->assertJsonPath('total', '210.00')
            ->assertJsonPath('amount_paid', '0.00')
            ->assertJsonPath('balance_due', '210.00')
            ->json();

        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $expense['current_journal_entry_id'],
            'account_code' => 'expense_electricity',
            'debit' => '210.00',
        ]);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $expense['current_journal_entry_id'],
            'account_code' => 'accounts_payable',
            'credit' => '210.00',
        ]);
        $this->assertEquals(210, (float) $this->getJson('/api/suppliers')->json('data.0.outstanding_balance'));
        $this->assertEquals(210, (float) $this->getJson('/api/dashboard/summary')->json('supplier_payables'));
    }

    public function test_editing_and_deleting_expense_reverse_journal_entries(): void
    {
        $this->authenticatedUser();
        $expense = $this->postJson('/api/expenses', $this->expensePayload())->assertCreated()->json();
        $originalEntryId = $expense['current_journal_entry_id'];

        $updated = $this->putJson("/api/expenses/{$expense['id']}", $this->expensePayload([
            'amount' => 250,
            'gst_rate' => 0,
        ]))->assertOk()->assertJsonPath('total', '250.00')->json();

        $this->assertDatabaseHas('journal_entries', [
            'reversal_of_id' => $originalEntryId,
            'source_type' => 'expense_reversal',
        ]);

        $this->deleteJson("/api/expenses/{$expense['id']}")->assertNoContent();
        $this->assertSoftDeleted('expenses', ['id' => $expense['id']]);
        $this->assertDatabaseHas('journal_entries', [
            'reversal_of_id' => $updated['current_journal_entry_id'],
            'source_type' => 'expense_reversal',
        ]);
        $this->assertSame(2, JournalEntry::query()->where('source_type', 'expense')->count());
        $this->assertSame(2, JournalEntry::query()->where('source_type', 'expense_reversal')->count());
    }

    public function test_expense_requires_a_valid_tax_credit_selection_and_is_business_scoped(): void
    {
        $this->authenticatedUser();

        $this->postJson('/api/expenses', $this->expensePayload([
            'gst_rate' => 0,
            'claim_input_tax_credit' => true,
        ]))->assertUnprocessable()->assertJsonValidationErrors('claim_input_tax_credit');

        $expense = $this->postJson('/api/expenses', $this->expensePayload())->assertCreated()->json();
        $otherUser = User::factory()->create([
            'username' => 'expense-other-owner',
            'email' => 'expense-other-owner@example.test',
        ]);
        $this->createBusiness($otherUser);
        Sanctum::actingAs($otherUser);

        $this->getJson('/api/expenses')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/expenses/{$expense['id']}")->assertNotFound();
        $this->deleteJson("/api/expenses/{$expense['id']}")->assertNotFound();
    }

    private function authenticatedUser(): User
    {
        $user = User::factory()->create([
            'username' => 'expense-owner-'.fake()->unique()->numberBetween(1000, 9999),
            'email' => fake()->unique()->safeEmail(),
        ]);
        $this->createBusiness($user);
        Sanctum::actingAs($user);

        return $user;
    }

    private function createBusiness(User $user): Business
    {
        $business = $user->businesses()->create([
            'name' => 'Expense test business',
            'gst_registration_type' => 'not_registered',
            'business_type' => 'retail_shop',
            'language' => 'en',
        ]);
        $user->forceFill(['active_business_id' => $business->id])->save();

        return $business;
    }

    private function expensePayload(array $overrides = []): array
    {
        return array_replace([
            'category' => 'rent',
            'amount' => 100,
            'gst_rate' => 0,
            'claim_input_tax_credit' => false,
            'payment_method' => 'cash',
            'expense_date' => now()->toDateString(),
            'supplier_id' => null,
            'scan_bill_id' => null,
            'note' => null,
        ], $overrides);
    }
}
