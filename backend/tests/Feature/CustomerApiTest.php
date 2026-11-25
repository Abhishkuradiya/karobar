<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_and_list_only_their_customers(): void
    {
        $user = User::factory()->create([
            'username' => 'owner-one',
            'email' => 'owner-one@example.test',
        ]);
        $anotherUser = User::factory()->create([
            'username' => 'owner-two',
            'email' => 'owner-two@example.test',
        ]);
        $this->createBusiness($user);
        $anotherBusiness = $this->createBusiness($anotherUser);
        $anotherUser->customers()->create([
            'business_id' => $anotherBusiness->id,
            'name' => 'Private customer',
            'credit_days' => 0,
        ]);
        Sanctum::actingAs($user);

        $this->postJson('/api/customers', [
            'name' => 'Sharma Traders',
            'mobile' => '9876543210',
            'credit_days' => 15,
            'credit_limit' => 5000,
            'gstin' => '27ABCDE1234F1Z5',
            'state' => 'Maharashtra',
            'email' => 'accounts@example.test',
            'address' => 'Pune',
        ])
            ->assertCreated()
            ->assertJsonPath('name', 'Sharma Traders')
            ->assertJsonPath('gstin', '27ABCDE1234F1Z5');

        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Sharma Traders');
    }

    public function test_customer_can_be_updated_and_soft_deleted(): void
    {
        $user = User::factory()->create([
            'username' => 'customer-owner',
            'email' => 'customer-owner@example.test',
        ]);
        $business = $this->createBusiness($user);
        Sanctum::actingAs($user);
        $customer = $user->customers()->create([
            'business_id' => $business->id,
            'name' => 'Old name',
            'credit_days' => 0,
        ]);

        $this->putJson("/api/customers/{$customer->id}", [
            'name' => 'Updated name',
            'mobile' => null,
            'credit_days' => 0,
            'credit_limit' => null,
            'gstin' => null,
            'state' => null,
            'email' => null,
            'address' => null,
        ])->assertOk()->assertJsonPath('name', 'Updated name');

        $this->deleteJson("/api/customers/{$customer->id}")->assertNoContent();
        $this->assertSoftDeleted('customers', ['id' => $customer->id]);
    }

    public function test_customers_are_not_accessible_to_other_users(): void
    {
        $owner = User::factory()->create([
            'username' => 'data-owner',
            'email' => 'data-owner@example.test',
        ]);
        $otherUser = User::factory()->create([
            'username' => 'other-owner',
            'email' => 'other-owner@example.test',
        ]);
        $business = $this->createBusiness($owner);
        $this->createBusiness($otherUser);
        $customer = $owner->customers()->create([
            'business_id' => $business->id,
            'name' => 'Owner private customer',
            'credit_days' => 0,
        ]);
        Sanctum::actingAs($otherUser);

        $this->getJson("/api/customers/{$customer->id}")->assertNotFound();
        $this->putJson("/api/customers/{$customer->id}", [
            'name' => 'Changed by another user',
            'credit_days' => 0,
        ])->assertNotFound();
    }

    private function createBusiness(User $user): Business
    {
        $business = $user->businesses()->create([
            'name' => 'Test business',
            'gst_registration_type' => 'not_registered',
            'business_type' => 'retail_shop',
            'language' => 'en',
        ]);
        $user->forceFill(['active_business_id' => $business->id])->save();

        return $business;
    }
}
