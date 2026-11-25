<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountingWorkspaceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_chart_of_accounts_includes_system_accounts_and_creates_business_scoped_custom_accounts(): void
    {
        $user = $this->authenticatedUser();

        $this->getJson('/api/chart-of-accounts')
            ->assertOk()
            ->assertJsonPath('data.assets.0.account_type', 'assets');

        $customResponse = $this->postJson('/api/chart-of-accounts', [
            'display_code' => '9000',
            'name' => 'Equipment reserve',
            'account_type' => 'assets',
            'group_name' => 'Fixed Assets',
        ])->assertCreated();
        $custom = $customResponse->json();
        $this->assertStringStartsWith('custom_', $custom['account_code']);

        $this->assertDatabaseHas('chart_accounts', [
            'id' => $custom['id'],
            'user_id' => $user->id,
            'business_id' => $user->active_business_id,
            'display_code' => '9000',
        ]);
        $this->postJson('/api/chart-of-accounts', [
            'display_code' => '1000',
            'name' => 'Duplicate cash',
            'account_type' => 'assets',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('display_code');
    }

    public function test_manual_journal_requires_balanced_lines_and_lists_the_posted_entry(): void
    {
        $this->authenticatedUser();

        $this->postJson('/api/journal-entries', [
            'entry_date' => now()->toDateString(),
            'narration' => 'Opening correction',
            'lines' => [
                ['account_code' => 'cash', 'debit' => 100, 'credit' => 0],
                ['account_code' => 'owners_capital', 'debit' => 0, 'credit' => 100],
            ],
        ])->assertCreated()
            ->assertJsonPath('source_type', 'manual_adjustment');

        $this->postJson('/api/journal-entries', [
            'entry_date' => now()->toDateString(),
            'narration' => 'Unbalanced correction',
            'lines' => [
                ['account_code' => 'cash', 'debit' => 100, 'credit' => 0],
                ['account_code' => 'owners_capital', 'debit' => 0, 'credit' => 99],
            ],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('lines');

        $this->getJson('/api/journal-entries')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('summary.debits', 100)
            ->assertJsonPath('summary.credits', 100);
    }

    public function test_cash_tally_posts_the_difference_to_cash_and_disallows_duplicate_dates(): void
    {
        $this->authenticatedUser();
        $date = now()->toDateString();

        $tally = $this->postJson('/api/cash-tally', [
            'counted_date' => $date,
            'counted_amount' => 25.50,
            'note' => 'Till count',
        ])->assertCreated()
            ->assertJsonPath('data.book_amount', '0.00')
            ->assertJsonPath('data.difference', '25.50')
            ->json('data');

        $this->assertNotNull($tally['journal_entry_id']);
        $this->assertDatabaseHas('journal_lines', [
            'journal_entry_id' => $tally['journal_entry_id'],
            'account_code' => 'cash',
            'debit' => '25.50',
        ]);
        $this->getJson('/api/cash-tally?date='.$date)
            ->assertOk()
            ->assertJsonPath('book_amount', 25.5)
            ->assertJsonPath('tally.id', $tally['id']);
        $this->postJson('/api/cash-tally', [
            'counted_date' => $date,
            'counted_amount' => 25.50,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('counted_date');
    }

    public function test_gst_report_calculates_output_tax_and_month_close_returns_actionable_items(): void
    {
        $this->authenticatedUser();

        $this->postJson('/api/sales', [
            'customer_id' => null,
            'sale_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'prices_include_gst' => false,
            'export_under_lut' => false,
            'items' => [[
                'description' => 'Consulting service',
                'quantity' => 1,
                'unit_rate' => 100,
                'gst_rate' => 18,
                'discount' => 0,
                'hsn_sac' => '9983',
            ]],
        ])->assertCreated();

        $month = now()->format('Y-m');
        $this->getJson('/api/gst/report?month='.$month)
            ->assertOk()
            ->assertJsonPath('summary.output_tax.total', 18)
            ->assertJsonPath('summary.output_tax.cgst', 9)
            ->assertJsonPath('summary.output_tax.sgst', 9)
            ->assertJsonPath('summary.liability_after_setoff.total', 18)
            ->assertJsonPath('hsn_summary.0.hsn_sac', '9983');

        $this->getJson('/api/month-end-close?month='.$month)
            ->assertOk()
            ->assertJsonPath('month', $month)
            ->assertJsonPath('progress.total', 7);
        $this->get('/api/gst/export?month='.$month.'&format=csv')
            ->assertDownload("gst-report-{$month}.csv");
    }

    public function test_gst_report_uses_igst_for_a_known_interstate_supply(): void
    {
        $user = $this->authenticatedUser();
        $business = Business::findOrFail($user->active_business_id);
        $business->update(['gstin' => '27AABCU9603R1ZM', 'state' => 'Maharashtra']);
        $customer = $user->customers()->create([
            'business_id' => $business->id,
            'name' => 'Interstate customer',
            'gstin' => '29AABCU9603R1ZM',
            'state' => 'Karnataka',
        ]);

        $this->postJson('/api/sales', [
            'customer_id' => $customer->id,
            'sale_date' => now()->toDateString(),
            'payment_method' => 'cash',
            'prices_include_gst' => false,
            'export_under_lut' => false,
            'items' => [[
                'description' => 'Interstate service',
                'quantity' => 1,
                'unit_rate' => 100,
                'gst_rate' => 18,
                'discount' => 0,
            ]],
        ])->assertCreated();

        $this->getJson('/api/gst/report?month='.now()->format('Y-m'))
            ->assertOk()
            ->assertJsonPath('summary.output_tax.igst', 18)
            ->assertJsonPath('summary.output_tax.cgst', 0)
            ->assertJsonPath('summary.output_tax.sgst', 0);
    }

    public function test_tally_xml_opening_balances_are_staged_for_review_before_commit(): void
    {
        $this->authenticatedUser();
        $file = UploadedFile::fake()->createWithContent(
            'tally-export.xml',
            '<ENVELOPE><BODY><DATA><TALLYMESSAGE><LEDGER NAME="Cash"><NAME>Cash</NAME><OPENINGBALANCE>100.00</OPENINGBALANCE></LEDGER></TALLYMESSAGE></DATA></BODY></ENVELOPE>'
        );

        $this->post('/api/imports/tally_xml/preview', [
            'file' => $file,
            'opening_date' => now()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('summary.entries', 1)
            ->assertJsonPath('summary.errors', 0)
            ->assertJsonPath('data.preview_data.entries.0.lines.0.account_code', 'cash');

        $this->assertDatabaseCount('journal_entries', 0);
    }

    public function test_opening_balance_import_is_previewed_before_commit_and_cannot_be_committed_twice(): void
    {
        $this->authenticatedUser();
        $file = UploadedFile::fake()->createWithContent(
            'opening-balances.csv',
            "entry_date,account_code,debit,credit\n".now()->toDateString().",cash,100,0\n".now()->toDateString().",bank,0,100\n"
        );

        $preview = $this->post('/api/imports/opening_balances_csv/preview', [
            'file' => $file,
            'opening_date' => now()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('summary.entries', 1)
            ->assertJsonPath('summary.errors', 0)
            ->json('data');

        $this->assertDatabaseCount('journal_entries', 0);
        $this->postJson('/api/imports/'.$preview['id'].'/commit')
            ->assertOk()
            ->assertJsonPath('result.entries_posted', 1);
        $this->assertDatabaseCount('journal_entries', 1);
        $this->postJson('/api/imports/'.$preview['id'].'/commit')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('import');
    }

    private function authenticatedUser(): User
    {
        $user = User::factory()->create([
            'username' => 'accounting-owner-'.fake()->unique()->numberBetween(1000, 9999),
            'email' => fake()->unique()->safeEmail(),
        ]);
        $business = $user->businesses()->create([
            'name' => 'Accounting test business',
            'gst_registration_type' => 'not_registered',
            'business_type' => 'retail_shop',
            'language' => 'en',
        ]);
        $user->forceFill(['active_business_id' => $business->id])->save();
        Sanctum::actingAs($user);

        return $user;
    }
}
