<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupplierApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_can_be_created_listed_updated_and_deleted(): void
    {
        $this->authenticatedUser();

        $supplier = $this->postJson('/api/suppliers', $this->supplierPayload())
            ->assertCreated()
            ->assertJsonPath('name', 'Sharma Suppliers')
            ->assertJsonPath('msme_type', 'not_known')
            ->json();

        $this->getJson('/api/suppliers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $supplier['id'])
            ->assertJsonPath('summary.count', 1);

        $this->putJson("/api/suppliers/{$supplier['id']}", $this->supplierPayload([
            'name' => 'Updated Supplier',
            'credit_days' => 15,
        ]))
            ->assertOk()
            ->assertJsonPath('name', 'Updated Supplier')
            ->assertJsonPath('credit_days', 15);

        $this->deleteJson("/api/suppliers/{$supplier['id']}")->assertNoContent();
        $this->assertSoftDeleted('suppliers', ['id' => $supplier['id']]);
        $this->getJson('/api/suppliers')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_supplier_gstin_is_unique_and_micro_or_small_credit_terms_are_limited_to_45_days(): void
    {
        $this->authenticatedUser();

        $payload = $this->supplierPayload([
            'msme_type' => 'micro',
            'credit_days' => 45,
            'udyam_number' => 'udyam-mh-12-0012345',
            'gstin' => '27aapfu0939f1zv',
        ]);
        $supplier = $this->postJson('/api/suppliers', $payload)
            ->assertCreated()
            ->assertJsonPath('gstin', '27AAPFU0939F1ZV')
            ->assertJsonPath('udyam_number', 'UDYAM-MH-12-0012345')
            ->json();

        $this->assertNotEmpty($supplier['id']);
        $this->postJson('/api/suppliers', $this->supplierPayload([
            'gstin' => '27AAPFU0939F1ZV',
        ]))->assertUnprocessable()->assertJsonValidationErrors('gstin');

        $this->postJson('/api/suppliers', $this->supplierPayload([
            'msme_type' => 'small',
            'credit_days' => 46,
        ]))->assertUnprocessable()->assertJsonValidationErrors('credit_days');
    }

    public function test_supplier_mobile_must_be_ten_digits_and_unique_in_the_active_business(): void
    {
        $this->authenticatedUser();
        $payload = $this->supplierPayload(['mobile' => '9876543210']);

        $this->postJson('/api/suppliers', $payload)->assertCreated();
        $this->postJson('/api/suppliers', [...$payload, 'name' => 'Duplicate supplier'])
            ->assertUnprocessable()->assertJsonValidationErrors('mobile');
        $this->postJson('/api/suppliers', [...$payload, 'name' => 'Long mobile', 'mobile' => '98765432101'])
            ->assertUnprocessable()->assertJsonValidationErrors('mobile');
    }

    public function test_supplier_email_must_be_unique_for_the_same_user(): void
    {
        $this->authenticatedUser();
        $payload = $this->supplierPayload(['email' => 'accounts@example.test']);

        $this->postJson('/api/suppliers', $payload)->assertCreated();
        $this->postJson('/api/suppliers', [...$payload, 'name' => 'Duplicate email supplier'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_supplier_udyam_number_must_be_unique_for_the_business(): void
    {
        $this->authenticatedUser();
        $payload = $this->supplierPayload(['udyam_number' => 'udyam-mh-12-0012345']);

        $this->postJson('/api/suppliers', $payload)->assertCreated();
        $this->postJson('/api/suppliers', [...$payload, 'name' => 'Duplicate Udyam supplier'])
            ->assertUnprocessable()->assertJsonValidationErrors('udyam_number');
    }

    public function test_supplier_records_are_scoped_to_the_active_business(): void
    {
        $this->authenticatedUser();
        $supplier = $this->postJson('/api/suppliers', $this->supplierPayload())
            ->assertCreated()
            ->json();

        $otherUser = User::factory()->create([
            'username' => 'supplier-other-owner',
            'email' => 'supplier-other-owner@example.test',
        ]);
        $this->createBusiness($otherUser);
        Sanctum::actingAs($otherUser);

        $this->getJson('/api/suppliers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/suppliers/{$supplier['id']}")->assertNotFound();
        $this->putJson("/api/suppliers/{$supplier['id']}", $this->supplierPayload())->assertNotFound();
        $this->deleteJson("/api/suppliers/{$supplier['id']}")->assertNotFound();
    }

    private function authenticatedUser(): User
    {
        $user = User::factory()->create([
            'username' => 'supplier-owner-'.fake()->unique()->numberBetween(1000, 9999),
            'email' => fake()->unique()->safeEmail(),
        ]);
        $this->createBusiness($user);
        Sanctum::actingAs($user);

        return $user;
    }

    private function createBusiness(User $user): Business
    {
        $business = $user->businesses()->create([
            'name' => 'Supplier test business',
            'gst_registration_type' => 'not_registered',
            'business_type' => 'retail_shop',
            'language' => 'en',
        ]);
        $user->forceFill(['active_business_id' => $business->id])->save();

        return $business;
    }

    private function supplierPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Sharma Suppliers',
            'mobile' => '9876543210',
            'credit_days' => 0,
            'msme_type' => 'not_known',
            'udyam_number' => null,
            'gstin' => null,
            'state' => null,
            'email' => null,
            'address' => null,
        ], $overrides);
    }
}
