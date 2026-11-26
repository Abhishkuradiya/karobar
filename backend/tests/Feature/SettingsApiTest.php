<?php

namespace Tests\Feature;

use App\Models\InventoryBatch;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_business_profile_can_be_updated_with_gst_validation(): void
    {
        [$user] = $this->authenticatedBusiness();

        $this->patchJson('/api/businesses/active/profile', [
            'name' => 'Updated Shop',
            'legal_name' => 'Updated Shop Private Limited',
            'gst_registration_type' => 'regular',
            'gstin' => '27aapfu0939f1zv',
            'gst_return_frequency' => 'quarterly',
            'business_type' => 'retail_shop',
            'language' => 'en',
            'state' => 'Maharashtra',
            'city' => 'Pune',
            'address' => '12 Market Road',
            'postal_code' => '411001',
            'phone' => '+91 9876543210',
        ])->assertOk()
            ->assertJsonPath('business.name', 'Updated Shop')
            ->assertJsonPath('business.gstin', '27AAPFU0939F1ZV')
            ->assertJsonPath('business.postal_code', '411001');

        $this->assertDatabaseHas('businesses', [
            'id' => $user->active_business_id,
            'legal_name' => 'Updated Shop Private Limited',
            'address' => '12 Market Road',
            'phone' => '+91 9876543210',
        ]);

        $this->patchJson('/api/businesses/active/profile', [
            'name' => 'Updated Shop',
            'gst_registration_type' => 'regular',
            'gstin' => 'INVALID',
            'gst_return_frequency' => 'monthly',
            'business_type' => 'retail_shop',
            'language' => 'en',
            'state' => 'Maharashtra',
            'postal_code' => '123',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['gstin', 'postal_code']);
    }

    public function test_account_profile_and_password_can_be_updated_safely(): void
    {
        [$user] = $this->authenticatedBusiness();

        $this->patchJson('/api/account/profile', [
            'name' => 'Profile Owner',
            'email' => 'profile-owner@example.test',
        ])->assertOk()
            ->assertJsonPath('user.name', 'Profile Owner')
            ->assertJsonPath('user.email', 'profile-owner@example.test');

        $this->patchJson('/api/account/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->patchJson('/api/account/password', [
            'current_password' => 'password',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])->assertOk();

        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }

    public function test_notification_preferences_filter_business_alerts_and_read_state_persists(): void
    {
        [$user, $business] = $this->authenticatedBusiness();
        $sale = $user->sales()->create([
            'business_id' => $business->id,
            'invoice_number' => 'TEST-OVERDUE-1',
            'customer_name_snapshot' => 'Private customer name',
            'sale_date' => now()->subDays(10)->toDateString(),
            'payment_method' => 'credit',
            'prices_include_gst' => false,
            'export_under_lut' => false,
            'subtotal' => 100,
            'discount_total' => 0,
            'taxable_amount' => 100,
            'tax_amount' => 18,
            'total' => 118,
            'amount_paid' => 0,
            'balance_due' => 118,
            'due_date' => now()->subDay()->toDateString(),
        ]);

        $this->getJson('/api/notification-preferences')
            ->assertOk()
            ->assertJsonPath('preferences.invoice_payment_reminders', true);

        $preferences = [
            'invoice_payment_reminders' => false,
            'customer_outstanding' => true,
            'supplier_payment_reminders' => true,
            'low_stock' => true,
            'expiring_inventory' => true,
        ];
        $this->putJson('/api/notification-preferences', ['preferences' => $preferences])
            ->assertOk()
            ->assertJsonPath('preferences.invoice_payment_reminders', false);
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('unread_count', 0);

        $preferences['invoice_payment_reminders'] = true;
        $this->putJson('/api/notification-preferences', ['preferences' => $preferences])->assertOk();
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.key', 'invoice:'.$sale->id)
            ->assertJsonPath('data.0.category', 'invoice_payment_reminders')
            ->assertJsonPath('unread_count', 1)
            ->assertJsonMissing(['customer_name_snapshot' => 'Private customer name']);

        $this->postJson('/api/notifications/read', ['notification_key' => 'invoice:'.$sale->id])
            ->assertOk();
        $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.is_read', true)
            ->assertJsonPath('unread_count', 0);

        $this->postJson('/api/notifications/read', ['notification_key' => 'invoice:999999'])
            ->assertNotFound();
    }

    public function test_notifications_include_due_supplier_payments_low_stock_and_expiring_batches(): void
    {
        [$user, $business] = $this->authenticatedBusiness();
        $user->purchases()->create([
            'business_id' => $business->id,
            'purchase_number' => 'PUR-SETTINGS-1',
            'supplier_name_snapshot' => 'Private supplier name',
            'purchase_date' => now()->subDays(20)->toDateString(),
            'payment_method' => 'credit',
            'subtotal' => 100,
            'discount_total' => 0,
            'taxable_amount' => 100,
            'tax_amount' => 18,
            'total' => 118,
            'supplier_amount' => 118,
            'amount_paid' => 0,
            'balance_due' => 118,
            'due_date' => now()->subDay()->toDateString(),
        ]);
        $product = Product::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'name' => 'Private product name',
            'unit_code' => 'pcs',
            'track_stock' => true,
            'low_stock_threshold' => 5,
            'current_stock' => 2,
        ]);
        InventoryBatch::create([
            'user_id' => $user->id,
            'business_id' => $business->id,
            'product_id' => $product->id,
            'batch_number' => 'BATCH-SETTINGS-1',
            'received_date' => now()->subDays(30)->toDateString(),
            'expiry_date' => now()->addDays(5)->toDateString(),
            'quantity_received' => 10,
            'quantity_remaining' => 10,
            'unit_cost' => 1,
        ]);

        $response = $this->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread_count', 3);

        $categories = collect($response->json('data'))->pluck('category')->all();
        $this->assertContains('supplier_payment_reminders', $categories);
        $this->assertContains('low_stock', $categories);
        $this->assertContains('expiring_inventory', $categories);
        $this->assertStringNotContainsString('Private supplier name', $response->getContent());
        $this->assertStringNotContainsString('Private product name', $response->getContent());
    }

    private function authenticatedBusiness(): array
    {
        $user = User::factory()->create([
            'username' => 'settings-owner-'.fake()->unique()->numberBetween(1000, 9999),
            'email' => fake()->unique()->safeEmail(),
        ]);
        $business = $user->businesses()->create([
            'name' => 'Settings test business',
            'gst_registration_type' => 'regular',
            'gstin' => '27AAPFU0939F1ZV',
            'gst_return_frequency' => 'monthly',
            'business_type' => 'retail_shop',
            'language' => 'en',
            'state' => 'Maharashtra',
            'city' => 'Pune',
        ]);
        $user->forceFill(['active_business_id' => $business->id])->save();
        Sanctum::actingAs($user);

        return [$user, $business];
    }
}
