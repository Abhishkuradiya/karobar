<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SupportRequestApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_submit_and_track_a_support_request_for_the_active_business(): void
    {
        $user = $this->authenticatedUser();

        $response = $this->postJson('/api/support-requests', [
            'subject' => 'Invoice total looks incorrect',
            'urgency' => 'high',
            'description' => 'The total on the invoice does not match the saved line items.',
        ])->assertCreated()
            ->assertJsonPath('data.reference', 'KAR-000001')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.business', 'Support test business');

        $requestId = $response->json('data.id');
        $this->assertDatabaseHas('support_requests', [
            'id' => $requestId,
            'user_id' => $user->id,
            'business_id' => $user->active_business_id,
            'urgency' => 'high',
            'status' => 'open',
        ]);

        $this->getJson('/api/support-requests')
            ->assertOk()
            ->assertJsonPath('data.0.id', $requestId)
            ->assertJsonPath('data.0.subject', 'Invoice total looks incorrect');
    }

    public function test_support_request_validation_and_user_scoping_are_enforced(): void
    {
        $this->authenticatedUser();
        $this->postJson('/api/support-requests', [
            'subject' => '',
            'urgency' => 'critical',
            'description' => 'short',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['subject', 'urgency', 'description']);

        $this->postJson('/api/support-requests', [
            'subject' => 'Private request',
            'urgency' => 'normal',
            'description' => 'A valid description for my own support request.',
        ])->assertCreated();

        $otherUser = User::factory()->create([
            'username' => 'other-support-user-'.fake()->unique()->numberBetween(1000, 9999),
        ]);
        $otherBusiness = $otherUser->businesses()->create([
            'name' => 'Other business',
            'gst_registration_type' => 'not_registered',
            'business_type' => 'retail_shop',
            'language' => 'en',
        ]);
        $otherUser->forceFill(['active_business_id' => $otherBusiness->id])->save();
        Sanctum::actingAs($otherUser);

        $this->getJson('/api/support-requests')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function authenticatedUser(): User
    {
        $user = User::factory()->create([
            'username' => 'support-user-'.fake()->unique()->numberBetween(1000, 9999),
        ]);
        $business = $user->businesses()->create([
            'name' => 'Support test business',
            'gst_registration_type' => 'not_registered',
            'business_type' => 'retail_shop',
            'language' => 'en',
        ]);
        $user->forceFill(['active_business_id' => $business->id])->save();
        Sanctum::actingAs($user);

        return $user;
    }
}
