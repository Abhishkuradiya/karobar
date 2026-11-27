<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\InventoryBatch;
use App\Models\NotificationRead;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\UserNotificationPreference;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    private const DEFAULT_NOTIFICATION_PREFERENCES = [
        'invoice_payment_reminders' => true,
        'customer_outstanding' => true,
        'supplier_payment_reminders' => true,
        'low_stock' => true,
        'expiring_inventory' => true,
    ];

    public function updateBusiness(Request $request): JsonResponse
    {
        foreach (['name', 'legal_name', 'state', 'city', 'address', 'postal_code', 'phone'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim($request->input($field))]);
            }
        }
        if (is_string($request->input('gstin'))) {
            $request->merge(['gstin' => strtoupper(trim($request->input('gstin')))]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:150'],
            'gst_registration_type' => ['required', Rule::in(['regular', 'composition', 'not_registered'])],
            'gstin' => [
                'nullable',
                'required_if:gst_registration_type,regular,composition',
                'prohibited_if:gst_registration_type,not_registered',
                'string',
                'size:15',
                'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
                Rule::unique('businesses', 'gstin')->ignore($request->user()->activeBusiness->id),
            ],
            'gst_return_frequency' => [
                'nullable',
                'required_if:gst_registration_type,regular',
                'prohibited_unless:gst_registration_type,regular',
                Rule::in(['monthly', 'quarterly']),
            ],
            'business_type' => ['required', Rule::in(['retail_shop', 'wholesale_distribution', 'manufacturing', 'services', 'restaurant_food', 'other'])],
            'language' => ['required', Rule::in(['en', 'hi', 'bn', 'mr', 'te', 'ta'])],
            'state' => ['required', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:12', 'regex:/^[0-9]{6}$/'],
            'phone' => ['nullable', 'string', 'max:25', 'regex:/^[0-9+()\-\s]{7,25}$/'],
        ]);

        $business = $request->user()->activeBusiness;
        $business->update($validated);

        return response()->json(['business' => $business->fresh()]);
    }

    public function notificationPreferences(Request $request): JsonResponse
    {
        return response()->json([
            'preferences' => $this->preferencesFor($request->user()->id),
        ]);
    }

    public function updateNotificationPreferences(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'preferences' => [
                'required',
                'array:invoice_payment_reminders,customer_outstanding,supplier_payment_reminders,low_stock,expiring_inventory',
            ],
            'preferences.invoice_payment_reminders' => ['required', 'boolean'],
            'preferences.customer_outstanding' => ['required', 'boolean'],
            'preferences.supplier_payment_reminders' => ['required', 'boolean'],
            'preferences.low_stock' => ['required', 'boolean'],
            'preferences.expiring_inventory' => ['required', 'boolean'],
        ]);

        $record = UserNotificationPreference::updateOrCreate(
            ['user_id' => $request->user()->id],
            ['preferences' => $validated['preferences']]
        );

        return response()->json(['preferences' => $record->preferences]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();
        $business = $user->activeBusiness;
        $notifications = $this->buildNotifications($user->id, $business, $this->preferencesFor($user->id));
        $keys = $notifications->pluck('key');
        $readKeys = NotificationRead::query()
            ->where('user_id', $user->id)
            ->where('business_id', $business->id)
            ->whereIn('notification_key', $keys)
            ->pluck('notification_key')
            ->flip();
        $notifications = $notifications
            ->map(fn (array $notification): array => [
                ...$notification,
                'is_read' => $readKeys->has($notification['key']),
            ]);

        return response()->json([
            'data' => $notifications->take(20)->values(),
            'unread_count' => $notifications->where('is_read', false)->count(),
        ]);
    }

    public function markNotificationRead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notification_key' => ['required', 'string', 'max:100', 'regex:/^(invoice|supplier|stock|expiry):[0-9]+$/'],
        ]);
        $user = $request->user();
        $business = $user->activeBusiness;
        $activeKeys = $this->buildNotifications(
            $user->id,
            $business,
            $this->preferencesFor($user->id)
        )->pluck('key');
        abort_unless($activeKeys->contains($validated['notification_key']), 404);

        NotificationRead::updateOrCreate(
            [
                'user_id' => $user->id,
                'business_id' => $business->id,
                'notification_key' => $validated['notification_key'],
            ],
            ['read_at' => now()]
        );

        return response()->json(['message' => 'Notification marked as read.']);
    }

    public function markAllNotificationsRead(Request $request): JsonResponse
    {
        $user = $request->user();
        $business = $user->activeBusiness;
        $existing = NotificationRead::query()
            ->where('user_id', $user->id)
            ->where('business_id', $business->id)
            ->pluck('notification_key')
            ->flip();
        $timestamp = now();

        foreach ($this->buildNotifications($user->id, $business, $this->preferencesFor($user->id)) as $notification) {
            if ($existing->has($notification['key'])) {
                continue;
            }
            NotificationRead::create([
                'user_id' => $user->id,
                'business_id' => $business->id,
                'notification_key' => $notification['key'],
                'read_at' => $timestamp,
            ]);
        }

        return response()->json(['message' => 'Notifications marked as read.']);
    }

    private function preferencesFor(int $userId): array
    {
        $record = UserNotificationPreference::query()
            ->where('user_id', $userId)
            ->first();

        return array_replace(self::DEFAULT_NOTIFICATION_PREFERENCES, $record?->preferences ?? []);
    }

    private function buildNotifications(int $userId, Business $business, array $preferences): Collection
    {
        $today = now()->toDateString();
        $notifications = collect();

        $outstandingSales = Sale::query()
            ->where('user_id', $userId)
            ->where('business_id', $business->id)
            ->where('balance_due', '>', 0);

        if ($preferences['invoice_payment_reminders']) {
            (clone $outstandingSales)
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<=', $today)
                ->orderByDesc('updated_at')
                ->limit(100)
                ->get(['id', 'due_date', 'updated_at'])
                ->each(fn (Sale $sale) => $notifications->push([
                    'key' => 'invoice:'.$sale->id,
                    'category' => 'invoice_payment_reminders',
                    'title' => 'Invoice payment due',
                    'message' => 'An invoice is due for follow-up. Open invoices to review details.',
                    'url' => '/invoices',
                    'created_at' => $sale->due_date->toDateString(),
                ]));
        }

        if ($preferences['customer_outstanding']) {
            (clone $outstandingSales)
                ->where(function ($query) use ($today): void {
                    $query->whereNull('due_date')
                        ->orWhereDate('due_date', '>', $today);
                })
                ->orderByDesc('updated_at')
                ->limit(100)
                ->get(['id', 'due_date', 'updated_at'])
                ->each(fn (Sale $sale) => $notifications->push([
                    'key' => 'invoice:'.$sale->id,
                    'category' => 'customer_outstanding',
                    'title' => 'Customer balance outstanding',
                    'message' => 'A customer invoice still has a balance. Open invoices to review details.',
                    'url' => '/invoices',
                    'created_at' => $sale->due_date?->toDateString() ?? $sale->updated_at->toIso8601String(),
                ]));
        }

        if ($preferences['supplier_payment_reminders']) {
            Purchase::query()
                ->where('user_id', $userId)
                ->where('business_id', $business->id)
                ->where('balance_due', '>', 0)
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<=', $today)
                ->orderByDesc('updated_at')
                ->limit(100)
                ->get(['id', 'due_date', 'updated_at'])
                ->each(fn (Purchase $purchase) => $notifications->push([
                    'key' => 'supplier:'.$purchase->id,
                    'category' => 'supplier_payment_reminders',
                    'title' => 'Supplier payment due',
                    'message' => 'A purchase payment is due. Open purchases to review the balance.',
                    'url' => '/purchases',
                    'created_at' => $purchase->due_date->toDateString(),
                ]));
        }

        if ($preferences['low_stock']) {
            Product::query()
                ->where('user_id', $userId)
                ->where('business_id', $business->id)
                ->where('track_stock', true)
                ->whereNotNull('low_stock_threshold')
                ->whereColumn('current_stock', '<=', 'low_stock_threshold')
                ->orderByDesc('updated_at')
                ->limit(100)
                ->get(['id', 'updated_at'])
                ->each(fn (Product $product) => $notifications->push([
                    'key' => 'stock:'.$product->id,
                    'category' => 'low_stock',
                    'title' => 'Low stock',
                    'message' => 'A tracked item is at or below its reorder level. Review inventory.',
                    'url' => '/inventory',
                    'created_at' => $product->updated_at->toIso8601String(),
                ]));
        }

        if ($preferences['expiring_inventory']) {
            InventoryBatch::query()
                ->where('business_id', $business->id)
                ->where('quantity_remaining', '>', 0)
                ->whereNull('reversed_at')
                ->whereNotNull('expiry_date')
                ->whereDate('expiry_date', '<=', now()->addDays(30)->toDateString())
                ->orderBy('expiry_date')
                ->limit(100)
                ->get(['id', 'expiry_date'])
                ->each(function (InventoryBatch $batch) use ($notifications, $today): void {
                    $expired = $batch->expiry_date->toDateString() < $today;
                    $notifications->push([
                        'key' => 'expiry:'.$batch->id,
                        'category' => 'expiring_inventory',
                        'title' => $expired ? 'Expired inventory' : 'Inventory expiring soon',
                        'message' => 'A tracked batch needs attention. Review inventory expiry details.',
                        'url' => '/inventory',
                        'created_at' => $batch->expiry_date->toDateString(),
                    ]);
                });
        }

        return $notifications->sortByDesc('created_at')->take(100)->values();
    }
}
