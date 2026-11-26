<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SupportRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $requests = SupportRequest::query()
            ->with('business:id,name')
            ->where('user_id', $request->user()->id)
            ->latest()
            ->limit(100)
            ->get()
            ->map(fn (SupportRequest $supportRequest): array => $this->toArray($supportRequest));

        return response()->json(['data' => $requests]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:120'],
            'urgency' => ['required', 'in:low,normal,high,urgent'],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $supportRequest = SupportRequest::create([
            ...$validated,
            'user_id' => $request->user()->id,
            'business_id' => $request->user()->activeBusiness->id,
            'status' => 'open',
        ])->load('business:id,name');

        return response()->json([
            'message' => 'Your request has been saved.',
            'data' => $this->toArray($supportRequest),
        ], 201);
    }

    private function toArray(SupportRequest $supportRequest): array
    {
        return [
            'id' => $supportRequest->id,
            'reference' => 'KAR-'.str_pad((string) $supportRequest->id, 6, '0', STR_PAD_LEFT),
            'subject' => $supportRequest->subject,
            'urgency' => $supportRequest->urgency,
            'description' => $supportRequest->description,
            'status' => $supportRequest->status,
            'business' => $supportRequest->business?->name,
            'created_at' => $supportRequest->created_at?->toISOString(),
        ];
    }
}
