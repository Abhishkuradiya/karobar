<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\ScanBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ScanBillApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_bill_upload_is_private_and_can_be_linked_to_an_expense(): void
    {
        Storage::fake('local');
        $user = $this->authenticatedUser();

        $bill = $this->postJson('/api/scan-bills', [
            'file' => UploadedFile::fake()->create('supplier-bill.png', 20, 'image/png'),
        ])
            ->assertCreated()
            ->assertJsonPath('original_name', 'supplier-bill.png')
            ->assertJsonPath('status', 'needs_review')
            ->assertJsonMissingPath('file_path')
            ->json();

        Storage::disk('local')->assertExists(ScanBill::query()->findOrFail($bill['id'])->file_path);
        $this->getJson("/api/scan-bills/{$bill['id']}/file")->assertOk();

        $this->postJson('/api/expenses', [
            'category' => 'office_expenses',
            'amount' => 300,
            'gst_rate' => 0,
            'claim_input_tax_credit' => false,
            'payment_method' => 'cash',
            'expense_date' => now()->toDateString(),
            'scan_bill_id' => $bill['id'],
        ])->assertCreated()->assertJsonPath('scan_bill.status', 'recorded');

        $this->getJson('/api/scan-bills')->assertOk()
            ->assertJsonPath('data.0.status', 'recorded');

        Sanctum::actingAs($user);
        $this->deleteJson("/api/scan-bills/{$bill['id']}")->assertNoContent();
        $this->assertDatabaseHas('expenses', [
            'scan_bill_id' => null,
            'expense_number' => $this->getJson('/api/expenses')->json('data.0.expense_number'),
        ]);

        $otherUser = User::factory()->create([
            'username' => 'scan-other-owner',
            'email' => 'scan-other-owner@example.test',
        ]);
        $this->createBusiness($otherUser);
        Sanctum::actingAs($otherUser);

        $this->getJson("/api/scan-bills/{$bill['id']}/file")->assertNotFound();
        $this->deleteJson("/api/scan-bills/{$bill['id']}")->assertNotFound();
    }

    public function test_deleting_a_bill_removes_its_private_file(): void
    {
        Storage::fake('local');
        $this->authenticatedUser();
        $bill = $this->postJson('/api/scan-bills', [
            'file' => UploadedFile::fake()->create('bill.png', 20, 'image/png'),
        ])->assertCreated()->json();
        $filePath = ScanBill::query()->findOrFail($bill['id'])->file_path;

        $this->deleteJson("/api/scan-bills/{$bill['id']}")->assertNoContent();
        Storage::disk('local')->assertMissing($filePath);
        $this->getJson('/api/scan-bills')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_upload_rejects_unsupported_document_types(): void
    {
        $this->authenticatedUser();

        $this->postJson('/api/scan-bills', [
            'file' => UploadedFile::fake()->create('script.txt', 10, 'text/plain'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    private function authenticatedUser(): User
    {
        $user = User::factory()->create([
            'username' => 'scan-owner-'.fake()->unique()->numberBetween(1000, 9999),
            'email' => fake()->unique()->safeEmail(),
        ]);
        $this->createBusiness($user);
        Sanctum::actingAs($user);

        return $user;
    }

    private function createBusiness(User $user): Business
    {
        $business = $user->businesses()->create([
            'name' => 'Scan test business',
            'gst_registration_type' => 'not_registered',
            'business_type' => 'retail_shop',
            'language' => 'en',
        ]);
        $user->forceFill(['active_business_id' => $business->id])->save();

        return $business;
    }
}
