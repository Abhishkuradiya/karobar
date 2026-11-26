<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReportsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_report_types_handle_an_empty_business_without_errors(): void
    {
        $this->authenticatedUser();
        $reports = [
            'income-tax-compliance',
            'business-checkup',
            'cash-forecast',
            'profit-by-product',
            'profit-and-loss',
            'balance-sheet',
            'cash-flow',
            'sales',
            'purchases',
            'expenses',
            'receivables',
            'payables',
            'customer-ledgers',
            'vendor-ledgers',
            'inventory',
            'trial-balance',
            'day-book-journal',
            'account-ledgers',
        ];

        foreach ($reports as $report) {
            $this->getJson('/api/reports/'.$report)
                ->assertOk()
                ->assertJsonPath('report', $report)
                ->assertJsonStructure(['title', 'period' => ['from', 'to'], 'columns', 'rows', 'summary']);
        }
    }

    public function test_financial_reports_use_active_business_journal_entries_and_balance(): void
    {
        $user = $this->authenticatedUser();
        $entry = JournalEntry::create([
            'user_id' => $user->id,
            'business_id' => $user->active_business_id,
            'entry_date' => today()->toDateString(),
            'source_type' => 'manual_adjustment',
            'source_id' => 1,
            'description' => 'Initial cash sale',
        ]);
        $entry->lines()->createMany([
            ['account_code' => 'cash', 'debit' => 100, 'credit' => 0],
            ['account_code' => 'sales_revenue', 'debit' => 0, 'credit' => 100],
        ]);

        $this->getJson('/api/reports/trial-balance?from='.today()->toDateString().'&to='.today()->toDateString())
            ->assertOk()
            ->assertJsonPath('summary.debits', 100)
            ->assertJsonPath('summary.credits', 100);
        $this->getJson('/api/reports/profit-and-loss?from='.today()->toDateString().'&to='.today()->toDateString())
            ->assertOk()
            ->assertJsonPath('summary.income', 100)
            ->assertJsonPath('summary.net_profit', 100);
        $this->getJson('/api/reports/balance-sheet?to='.today()->toDateString())
            ->assertOk()
            ->assertJsonPath('summary.assets', 100)
            ->assertJsonPath('summary.equity', 100)
            ->assertJsonPath('summary.difference', 0);
        $this->getJson('/api/reports/account-ledgers?account_code=cash&from='.today()->toDateString().'&to='.today()->toDateString())
            ->assertOk()
            ->assertJsonPath('summary.closing_balance', 100)
            ->assertJsonPath('rows.0.debit', 100);
    }

    public function test_transaction_reports_are_scoped_and_invalid_report_periods_are_rejected(): void
    {
        $user = $this->authenticatedUser();
        $this->postJson('/api/sales', [
            'customer_id' => null,
            'sale_date' => today()->toDateString(),
            'payment_method' => 'cash',
            'prices_include_gst' => false,
            'export_under_lut' => false,
            'items' => [[
                'description' => 'Report test item',
                'quantity' => 1,
                'unit_rate' => 250,
                'gst_rate' => 0,
                'discount' => 0,
            ]],
        ])->assertCreated();

        $this->getJson('/api/reports/sales?from='.today()->toDateString().'&to='.today()->toDateString())
            ->assertOk()
            ->assertJsonPath('summary.documents', 1)
            ->assertJsonPath('summary.sales_total', 250)
            ->assertJsonPath('rows.0.party', 'Walk-in customer (paid now)');
        $this->getJson('/api/reports/sales?from='.today()->addDay()->toDateString().'&to='.today()->toDateString())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
        $this->getJson('/api/reports/not-a-report')
            ->assertNotFound();

        $this->assertSame($user->active_business_id, $user->fresh()->active_business_id);
    }

    private function authenticatedUser(): User
    {
        $user = User::factory()->create([
            'username' => 'report-user-'.fake()->unique()->numberBetween(1000, 9999),
        ]);
        $business = $user->businesses()->create([
            'name' => 'Reports test business',
            'gst_registration_type' => 'not_registered',
            'business_type' => 'retail_shop',
            'language' => 'en',
        ]);
        $user->forceFill(['active_business_id' => $business->id])->save();
        Sanctum::actingAs($user);

        return $user;
    }
}
