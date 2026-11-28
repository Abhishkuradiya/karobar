<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BusinessApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_user_has_no_business_and_can_create_the_first_business(): void
    {
        $user = $this->authenticatedUser();

        $this->getJson('/api/businesses')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('active_business', null);
        $this->getJson('/api/dashboard/summary')
            ->assertStatus(409)
            ->assertJsonPath('code', 'business_required');

        $business = $this->postJson('/api/businesses', $this->businessPayload())
            ->assertCreated()
            ->assertJsonPath('business.name', 'First business')
            ->assertJsonPath('business.gst_registration_type', 'regular')
            ->json('business');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'active_business_id' => $business['id'],
        ]);
        $this->getJson('/api/businesses')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('active_business.id', $business['id']);
    }

    public function test_gst_fields_are_required_and_saved_for_applicable_registration_types(): void
    {
        $this->authenticatedUser();

        $this->postJson('/api/businesses', $this->businessPayload([
            'gstin' => null,
        ]))->assertUnprocessable()->assertJsonValidationErrors('gstin');

        $regularBusiness = $this->postJson('/api/businesses', $this->businessPayload([
            'gstin' => '27aapfu0939f1zv',
        ]))
            ->assertCreated()
            ->assertJsonPath('business.gstin', '27AAPFU0939F1ZV')
            ->assertJsonPath('business.gst_return_frequency', 'monthly')
            ->json('business');

        $this->assertDatabaseHas('businesses', [
            'id' => $regularBusiness['id'],
            'gstin' => '27AAPFU0939F1ZV',
            'gst_return_frequency' => 'monthly',
        ]);

        $compositionBusiness = $this->postJson('/api/businesses', $this->businessPayload([
            'name' => 'Composition business',
            'gst_registration_type' => 'composition',
            'gstin' => '27AAQFU0939F1ZU',
            'gst_return_frequency' => null,
        ]))
            ->assertCreated()
            ->assertJsonPath('business.gstin', '27AAQFU0939F1ZU')
            ->assertJsonPath('business.gst_return_frequency', null)
            ->json('business');

        $this->assertDatabaseHas('businesses', [
            'id' => $compositionBusiness['id'],
            'gstin' => '27AAQFU0939F1ZU',
            'gst_return_frequency' => null,
        ]);

        $this->postJson('/api/businesses', $this->businessPayload([
            'name' => 'Unregistered business',
            'gst_registration_type' => 'not_registered',
            'gstin' => null,
            'gst_return_frequency' => null,
        ]))
            ->assertCreated()
            ->assertJsonPath('business.gstin', null)
            ->assertJsonPath('business.gst_return_frequency', null);

        $this->postJson('/api/businesses', $this->businessPayload([
            'name' => 'Invalid unregistered business',
            'gst_registration_type' => 'not_registered',
        ]))->assertUnprocessable()->assertJsonValidationErrors('gstin');
    }

    public function test_switching_business_scopes_customers_sales_and_dashboard(): void
    {
        $this->authenticatedUser();
        $firstBusiness = $this->postJson('/api/businesses', $this->businessPayload())
            ->assertCreated()
            ->json('business');

        $customer = $this->postJson('/api/customers', [
            'name' => 'First business customer',
            'credit_days' => 0,
        ])->assertCreated()->json();
        $sale = $this->postJson('/api/sales', $this->salePayload($customer['id']))
            ->assertCreated()
            ->json();

        $secondBusiness = $this->postJson('/api/businesses', $this->businessPayload([
            'name' => 'Second business',
            'gstin' => '27AABCU9603R1ZV',
            'business_type' => 'services',
        ]))->assertCreated()->json('business');

        $this->getJson('/api/customers')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/sales')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('today_sales', 0)
            ->assertJsonPath('customer_count', 0);
        $this->getJson("/api/customers/{$customer['id']}")->assertNotFound();
        $this->getJson("/api/sales/{$sale['id']}")->assertNotFound();

        $this->patchJson('/api/businesses/active', [
            'business_id' => $firstBusiness['id'],
        ])->assertOk()->assertJsonPath('active_business.id', $firstBusiness['id']);

        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'First business customer');
        $this->getJson('/api/sales')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $sale['id']);
        $this->getJson('/api/dashboard/summary')
            ->assertOk()
            ->assertJsonPath('customer_count', 1)
            ->assertJsonPath('today_sales', 118);

        $otherUser = User::factory()->create([
            'username' => 'business-api-other',
            'email' => 'business-api-other@example.test',
        ]);
        $otherBusiness = $otherUser->businesses()->create($this->businessPayload([
            'name' => 'Private business',
            'gstin' => '27AABCU9603R1ZX',
        ]));
        Sanctum::actingAs($otherUser);
        $this->patchJson('/api/businesses/active', [
            'business_id' => $secondBusiness['id'],
        ])->assertUnprocessable()->assertJsonValidationErrors('business_id');
        $this->assertNotSame($firstBusiness['id'], $otherBusiness->id);
    }

    public function test_dashboard_sales_comparison_returns_numeric_percentage_for_new_sales(): void
    {
        $this->authenticatedUser();
        $this->postJson('/api/businesses', $this->businessPayload())->assertCreated();

        $this->postJson('/api/sales', $this->salePayload(null))->assertCreated();

        $this->getJson('/api/dashboard/today-net-sales')
            ->assertOk()
            ->assertJsonPath('comparison', 'new_sales')
            ->assertJsonPath('difference_percent', 100)
            ->assertJsonPath('comparison_label', '↑ 100.00%');
    }

    private function authenticatedUser(): User
    {
        $user = User::factory()->create([
            'username' => 'business-owner-'.fake()->unique()->numberBetween(1000, 9999),
            'email' => fake()->unique()->safeEmail(),
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    private function businessPayload(array $overrides = []): array
    {
        return array_replace([
            'name' => 'First business',
            'gst_registration_type' => 'regular',
            'gstin' => '27AAPFU0939F1ZV',
            'gst_return_frequency' => 'monthly',
            'business_type' => 'retail_shop',
            'language' => 'en',
            'state' => 'Maharashtra',
            'city' => 'Pune',
        ], $overrides);
    }

    private function salePayload(?int $customerId): array
    {
        return [
            'customer_id' => $customerId,
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
        ];
    }
}
