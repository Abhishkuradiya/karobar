<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BusinessController extends Controller
{
    private const BUSINESS_TYPES = [
        'retail_shop',
        'wholesale_distribution',
        'manufacturing',
        'services',
        'restaurant_food',
        'other',
    ];

    private const LANGUAGES = ['en', 'hi', 'bn', 'mr', 'te', 'ta'];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $businesses = $user->businesses()->orderBy('id')->get();
        $activeBusiness = $businesses->firstWhere('id', $user->active_business_id);

        if (! $activeBusiness && $businesses->isNotEmpty()) {
            $activeBusiness = $businesses->first();
            $user->forceFill(['active_business_id' => $activeBusiness->id])->save();
        }

        return response()->json([
            'data' => $businesses,
            'active_business' => $activeBusiness,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        foreach (['name', 'state', 'city'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim($request->input($field))]);
            }
        }
        if (is_string($request->input('gstin'))) {
            $request->merge(['gstin' => strtoupper(trim($request->input('gstin')))]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'gst_registration_type' => [
                'required',
                Rule::in(['regular', 'composition', 'not_registered']),
            ],
            'gstin' => [
                'nullable',
                'required_if:gst_registration_type,regular,composition',
                'prohibited_if:gst_registration_type,not_registered',
                'string',
                'size:15',
                'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
                Rule::unique('businesses', 'gstin'),
            ],
            'gst_return_frequency' => [
                'nullable',
                'required_if:gst_registration_type,regular',
                'prohibited_unless:gst_registration_type,regular',
                Rule::in(['monthly', 'quarterly']),
            ],
            'business_type' => ['required', Rule::in(self::BUSINESS_TYPES)],
            'language' => ['required', Rule::in(self::LANGUAGES)],
            'state' => ['required', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        $business = DB::transaction(function () use ($user, $validated): Business {
            $business = $user->businesses()->create($validated);
            $user->forceFill(['active_business_id' => $business->id])->save();

            return $business;
        });

        return response()->json(['business' => $business], 201);
    }

    public function activate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_id' => [
                'required',
                'integer',
                Rule::exists('businesses', 'id')->where(
                    fn ($query) => $query->where('user_id', $request->user()->id)
                ),
            ],
        ]);

        $business = $request->user()->businesses()->findOrFail($validated['business_id']);
        $request->user()->forceFill(['active_business_id' => $business->id])->save();

        return response()->json(['active_business' => $business]);
    }
}
