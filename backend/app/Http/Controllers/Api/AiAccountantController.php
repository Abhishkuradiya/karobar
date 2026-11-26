<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiAccountantSetting;
use App\Models\AiAccountantConversation;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AiAccountantController extends Controller
{
    public function conversations(Request $request): JsonResponse
    {
        $rows = $this->conversationQuery($request)->latest('updated_at')->get(['id', 'title', 'created_at', 'updated_at']);
        return response()->json(['data' => $rows]);
    }

    public function conversation(Request $request, int $conversation): JsonResponse
    {
        return response()->json($this->conversationQuery($request)->findOrFail($conversation));
    }

    public function deleteConversation(Request $request, int $conversation): JsonResponse
    {
        $this->conversationQuery($request)->findOrFail($conversation)->delete();
        return response()->json(null, 204);
    }

    public function settings(Request $request): JsonResponse
    {
        $setting = $this->setting($request);

        return response()->json([
            'configured' => (bool) $setting,
            'message' => $setting
                ? 'AI Accountant is securely configured for this business.'
                : 'AI Accountant is not configured yet. Ask your administrator to configure it on the server.',
        ]);
    }

    public function chat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:5000'],
            'history' => ['nullable', 'array', 'max:20'],
            'image' => ['nullable', 'string', 'max:12000000'],
            'conversation_id' => ['nullable', 'integer'],
        ]);
        $setting = $this->setting($request);
        if (! $setting) {
            return response()->json([
                'configured' => false,
                'message' => 'AI Accountant is ready. Add your Omega Plus API key below to ask questions or prepare entries.',
            ]);
        }

        $business = $request->user()->activeBusiness;
        $today = now()->toDateString();
        $context = [
            'business' => $business->name,
            'today' => $today,
            'today_sales' => (float) Sale::where('business_id', $business->id)->whereDate('sale_date', $today)->sum('total'),
            'today_purchases' => (float) Purchase::where('business_id', $business->id)->whereDate('purchase_date', $today)->sum('total'),
            'today_expenses' => (float) Expense::where('business_id', $business->id)->whereDate('expense_date', $today)->sum('total'),
            'customers' => Customer::where('business_id', $business->id)->count(),
            'recent_invoices' => Sale::where('business_id', $business->id)->latest('id')->limit(20)->get(['id', 'invoice_number', 'customer_name_snapshot', 'sale_date', 'total'])->toArray(),
        ];
        $history = collect($data['history'] ?? [])->take(-10)->map(fn ($item) => [
            'role' => in_array($item['role'] ?? '', ['user', 'assistant'], true) ? $item['role'] : 'user',
            'content' => Str::limit((string) ($item['content'] ?? $item['text'] ?? ''), 4000),
        ])->values()->all();
        $messages = [...$history, ['role' => 'user', 'content' => $data['message']]];
        if (! empty($data['image'])) {
            $messages[count($messages) - 1]['content'] = [
                ['type' => 'text', 'text' => $data['message']],
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $this->imageMime($data['image']), 'data' => $this->imageData($data['image'])]],
            ];
        }

        $system = <<<'PROMPT'
You are Karobar AI Accountant for an Indian small business. Understand English and Hinglish.
Use the supplied business context. Never claim that an entry was posted. For a transaction, prepare a proposal and wait for explicit confirmation. If the user asks to edit an existing invoice or expense, return operation "update" and the matching record id only when it is present in the supplied context.
Return ONLY valid JSON with this shape: {"kind":"answer" or "proposal","message":"short helpful response","action":null or {"type":"sale"|"purchase"|"expense","payload":{...}}}.
For proposals, payload must match Karobar APIs. Sale fields: sale_date YYYY-MM-DD, payment_method cash|upi|card|bank|credit, customer_id nullable, prices_include_gst boolean, export_under_lut boolean, notes nullable, items [{description,quantity,unit_rate,gst_rate,discount}]. Purchase fields are analogous with purchase_date, supplier_id, supplier_bill_number, prices_include_gst, claim_input_tax_credit, reverse_charge, items. Expense fields: expense_date, category, payment_method cash|upi|bank|card|unpaid, amount, gst_rate, claim_input_tax_credit, supplier_id nullable, note. Use null for unknown IDs and ask a clarification instead of guessing. Amounts are in INR.
PROMPT;
        $payload = [
            'model' => $setting->model,
            'max_tokens' => $setting->max_tokens,
            'temperature' => $setting->temperature,
            'system' => $system."\nBusiness context: ".json_encode($context),
            'messages' => $messages,
        ];

        try {
            $response = Http::timeout(45)->withHeaders([
                'x-api-key' => $setting->api_key,
                'Authorization' => 'Bearer '.$setting->api_key,
                'anthropic-version' => '2023-06-01',
            ])->post(rtrim($setting->base_url, '/').'/v1/messages', $payload);
            if (! $response->successful()) {
                return response()->json(['message' => 'Omega Plus could not answer right now. Check the API key or model settings.', 'provider_error' => $response->status()], 502);
            }
            $json = $response->json();
            $text = collect($json['content'] ?? [])->where('type', 'text')->pluck('text')->implode("\n") ?: ($json['choices'][0]['message']['content'] ?? 'I could not interpret that request.');
            $parsed = $this->parseJson($text);
            $action = $parsed['action'] ?? $this->draftFromMessage($data['message']);
            $kind = $action ? 'proposal' : ($parsed['kind'] ?? 'answer');
            $reply = $parsed['message'] ?? $text;
            return response()->json([...$this->saveConversation($request, $data, $reply, $action), 'configured' => true, 'message' => $reply, 'kind' => $kind, 'action' => $action]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'AI service is temporarily unavailable. Your books were not changed.'], 502);
        }
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['action' => ['required', 'array'], 'action.type' => ['required', 'in:sale,purchase,expense'], 'action.payload' => ['required', 'array']]);
        $type = $data['action']['type'];
        $payload = $data['action']['payload'];
        if (in_array($type, ['sale', 'purchase'], true)) {
            $payload['prices_include_gst'] = (bool) ($payload['prices_include_gst'] ?? false);
            $payload['items'] = collect($payload['items'] ?? [])->map(fn (array $item): array => [
                ...$item,
                'unit_rate' => $item['unit_rate'] ?? $item['unit_price'] ?? 0,
                'discount' => $item['discount'] ?? 0,
                'gst_rate' => $item['gst_rate'] ?? 0,
            ])->all();
            if ($type === 'sale') {
                $payload['export_under_lut'] = (bool) ($payload['export_under_lut'] ?? false);
            } else {
                $payload['claim_input_tax_credit'] = (bool) ($payload['claim_input_tax_credit'] ?? false);
                $payload['reverse_charge'] = (bool) ($payload['reverse_charge'] ?? false);
            }
        } else {
            $payload['gst_rate'] = $payload['gst_rate'] ?? 0;
            $payload['claim_input_tax_credit'] = (bool) ($payload['claim_input_tax_credit'] ?? false);
        }
        $child = Request::create('/api/'.$type.'s', 'POST', $payload);
        $child->setUserResolver(fn () => $request->user());
        $controller = match ($type) { 'sale' => app(SaleController::class), 'purchase' => app(PurchaseController::class), default => app(ExpenseController::class) };
        $response = ($data['action']['operation'] ?? 'create') === 'update'
            ? $controller->update($child, (int) ($data['action']['id'] ?? 0))
            : $controller->store($child);
        return response()->json(['message' => 'Entry posted successfully.', 'data' => $response->getData(true)], $response->getStatusCode());
    }

    private function setting(Request $request): ?AiAccountantSetting { return AiAccountantSetting::query()->first(); }
    private function conversationQuery(Request $request) { return AiAccountantConversation::where('user_id', $request->user()->id)->where('business_id', $request->user()->activeBusiness->id); }
    private function saveConversation(Request $request, array $data, string $reply, ?array $action): array
    {
        $messages = collect($data['history'] ?? [])->map(fn ($item) => ['role' => $item['role'] ?? 'user', 'content' => $item['content'] ?? $item['text'] ?? ''])->values()->all();
        $messages[] = ['role' => 'user', 'content' => $data['message']];
        $messages[] = ['role' => 'assistant', 'content' => $reply, 'action' => $action];
        $conversation = ! empty($data['conversation_id']) ? $this->conversationQuery($request)->find($data['conversation_id']) : null;
        if (! $conversation) {
            $conversation = new AiAccountantConversation(['user_id' => $request->user()->id, 'business_id' => $request->user()->activeBusiness->id, 'title' => \Illuminate\Support\Str::limit($data['message'], 180)]);
        }
        $conversation->messages = $messages;
        $conversation->save();
        return ['conversation_id' => $conversation->id];
    }
    private function draftFromMessage(string $message): ?array
    {
        $lower = mb_strtolower($message);
        $isQuestion = (bool) preg_match('/\?|\b(kitni|kitna|today sales|aaj ki sale|report|show|update batao)\b/u', $lower);
        if ($isQuestion && ! preg_match('/\b(becha|bechi|bech|sold|sell|kharida|kharidi|bought|buy|kharcha|expense|rent|salary|bill paid|paid)\b/u', $lower)) return null;
        $isSale = (bool) preg_match('/\b(becha|bechi|bech|sale|sold|sell)\b/u', $lower);
        $isPurchase = (bool) preg_match('/\b(kharida|kharidi|purchase|bought|buy)\b/u', $lower);
        $isExpense = (bool) preg_match('/\b(kharcha|expense|rent|salary|bill paid|paid)\b/u', $lower);
        if (! $isSale && ! $isPurchase && ! $isExpense) return null;
        $amount = $this->amountFromMessage($message);
        $date = now()->toDateString();
        if ($isSale) return ['type' => 'sale', 'payload' => ['sale_date' => $date, 'payment_method' => $this->paymentFromMessage($lower) ?? 'cash', 'customer_id' => null, 'prices_include_gst' => false, 'export_under_lut' => false, 'items' => [['description' => trim(\Illuminate\Support\Str::limit($message, 120)), 'quantity' => 1, 'unit_rate' => $amount ?? '', 'gst_rate' => 0, 'discount' => 0]]]];
        if ($isPurchase) return ['type' => 'purchase', 'payload' => ['purchase_date' => $date, 'payment_method' => $this->paymentFromMessage($lower) ?? 'cash', 'supplier_id' => null, 'prices_include_gst' => false, 'claim_input_tax_credit' => false, 'reverse_charge' => false, 'items' => [['description' => trim(\Illuminate\Support\Str::limit($message, 120)), 'quantity' => 1, 'unit_rate' => $amount ?? '', 'gst_rate' => 0, 'discount' => 0]]]];
        return ['type' => 'expense', 'payload' => ['expense_date' => $date, 'category' => 'other', 'payment_method' => $this->paymentFromMessage($lower) ?? 'cash', 'amount' => $amount ?? '', 'gst_rate' => 0, 'claim_input_tax_credit' => false, 'note' => $message]];
    }
    private function amountFromMessage(string $message): ?float { if (! preg_match('/(\d[\d,]*(?:\.\d+)?)\s*(k|thousand|hazaar|lakh)?/iu', $message, $match)) return null; $value = (float) str_replace(',', '', $match[1]); return match (mb_strtolower($match[2] ?? '')) { 'k', 'thousand', 'hazaar' => $value * 1000, 'lakh' => $value * 100000, default => $value }; }
    private function paymentFromMessage(string $message): ?string { foreach (['upi', 'bank', 'card', 'credit', 'cash'] as $method) if (str_contains($message, $method)) return $method; return null; }
    private function parseJson(string $text): array { $text = trim(preg_replace('/^```(?:json)?|```$/i', '', $text)); $parsed = json_decode($text, true); return is_array($parsed) ? $parsed : ['message' => $text, 'kind' => 'answer']; }
    private function imageMime(string $image): string { return str_starts_with($image, 'data:image/png') ? 'image/png' : (str_starts_with($image, 'data:image/webp') ? 'image/webp' : 'image/jpeg'); }
    private function imageData(string $image): string { return str_contains($image, ',') ? explode(',', $image, 2)[1] : $image; }
}
