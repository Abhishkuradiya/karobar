<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ScanBill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ScanBillController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);
        $user = $request->user();
        $bills = ScanBill::query()
            ->where('user_id', $user->id)
            ->where('business_id', $user->activeBusiness->id)
            ->with('expense:id,scan_bill_id,expense_number,total')
            ->orderByDesc('created_at')
            ->paginate($validated['per_page'] ?? 15)
            ->withQueryString();

        return response()->json([
            'data' => $bills->items(),
            'meta' => [
                'current_page' => $bills->currentPage(),
                'last_page' => $bills->lastPage(),
                'per_page' => $bills->perPage(),
                'total' => $bills->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ]);
        $file = $validated['file'];
        $user = $request->user();
        $businessId = $user->activeBusiness->id;
        $path = $file->store("scan-bills/{$businessId}", 'local');

        if (! $path) {
            throw new RuntimeException('Could not store the uploaded bill file.');
        }

        $bill = $user->scanBills()->create([
            'business_id' => $businessId,
            'file_path' => $path,
            'original_name' => $this->safeOriginalName($file->getClientOriginalName()),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize(),
            'status' => 'needs_review',
        ]);

        return response()->json($bill, 201);
    }

    public function show(Request $request, int $scanBill): JsonResponse
    {
        return response()->json(
            $this->ownedBill($request, $scanBill)->load('expense:id,scan_bill_id,expense_number,total')
        );
    }

    public function file(Request $request, int $scanBill)
    {
        $bill = $this->ownedBill($request, $scanBill);
        $disk = Storage::disk('local');

        if (! $disk->exists($bill->file_path)) {
            abort(404, 'The uploaded bill file could not be found.');
        }

        return response()->file($disk->path($bill->file_path), [
            'Content-Type' => $bill->mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function destroy(Request $request, int $scanBill): JsonResponse
    {
        $bill = $this->ownedBill($request, $scanBill);
        $disk = Storage::disk('local');

        if ($disk->exists($bill->file_path) && ! $disk->delete($bill->file_path)) {
            throw new RuntimeException('Could not remove the uploaded bill file.');
        }

        $bill->delete();

        return response()->json(null, 204);
    }

    private function ownedBill(Request $request, int $id): ScanBill
    {
        return ScanBill::query()
            ->where('user_id', $request->user()->id)
            ->where('business_id', $request->user()->activeBusiness->id)
            ->findOrFail($id);
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'bill-upload';

        return mb_substr($name, 0, 255);
    }
}
