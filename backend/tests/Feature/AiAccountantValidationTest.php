<?php

namespace Tests\Feature;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiAccountantValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_confirmation_requires_a_supported_operation_and_update_id(): void
    {
        $user = User::factory()->create([
            'username' => 'ai-validation-owner',
            'email' => 'ai-validation-owner@example.test',
        ]);
        $business = $user->businesses()->create([
            'name' => 'AI validation business',
            'gst_registration_type' => 'not_registered',
            'business_type' => 'retail_shop',
            'language' => 'en',
        ]);
        $user->forceFill(['active_business_id' => $business->id])->save();
        Sanctum::actingAs($user);

        $this->postJson('/api/ai-accountant/confirm', [
            'action' => [
                'type' => 'sale',
                'operation' => 'delete',
                'payload' => [],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('action.operation');

        $this->postJson('/api/ai-accountant/confirm', [
            'action' => [
                'type' => 'sale',
                'operation' => 'update',
                'payload' => [],
            ],
        ])->assertUnprocessable()->assertJsonValidationErrors('action.id');
    }
}
