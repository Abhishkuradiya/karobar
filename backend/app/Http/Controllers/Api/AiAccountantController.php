<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\InventoryController;
use App\Models\AiAccountantSetting;
use App\Models\AiAccountantConversation;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            'history.*' => ['required', 'array'],
            'history.*.role' => ['required', Rule::in(['user', 'assistant'])],
            'history.*.content' => ['nullable', 'string', 'max:4000'],
            'image' => ['nullable', 'string', 'max:12000000'],
            'conversation_id' => ['nullable', 'integer'],
        ]);
        if (! empty($data['image'])) {
            $this->validateImageData($data['image']);
        }
        $setting = $this->setting($request);
        if (! $setting) {
            return response()->json([
                'configured' => false,
                'message' => 'AI Accountant is ready. Add your Omega Plus API key below to ask questions or prepare entries.',
            ]);
        }

        $business = $request->user()->activeBusiness;
        $today = now()->toDateString();
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();
        $previousMonthStart = now()->subMonthNoOverflow()->startOfMonth()->toDateString();
        $previousMonthEnd = now()->subMonthNoOverflow()->endOfMonth()->toDateString();
        $userId = $request->user()->id;
        $salesQuery = fn (string $from, string $to) => Sale::where('user_id', $userId)->where('business_id', $business->id)->whereBetween('sale_date', [$from, $to]);
        $purchasesQuery = fn (string $from, string $to) => Purchase::where('user_id', $userId)->where('business_id', $business->id)->whereBetween('purchase_date', [$from, $to]);
        $expensesQuery = fn (string $from, string $to) => Expense::where('user_id', $userId)->where('business_id', $business->id)->whereBetween('expense_date', [$from, $to]);
        $context = [
            'business' => $business->name,
            'today' => $today,
            'today_sales' => (float) Sale::where('user_id', $userId)->where('business_id', $business->id)->whereDate('sale_date', $today)->sum('total'),
            'today_purchases' => (float) Purchase::where('user_id', $userId)->where('business_id', $business->id)->whereDate('purchase_date', $today)->sum('total'),
            'today_expenses' => (float) Expense::where('user_id', $userId)->where('business_id', $business->id)->whereDate('expense_date', $today)->sum('total'),
            'customers' => Customer::where('user_id', $userId)->where('business_id', $business->id)->count(),
            'customer_directory' => Customer::where('user_id', $userId)->where('business_id', $business->id)->withSum('sales as outstanding_balance', 'balance_due')->orderBy('name')->limit(50)->get(['id', 'name', 'mobile', 'credit_limit'])->toArray(),
            'recent_invoices' => Sale::where('user_id', $userId)->where('business_id', $business->id)->latest('id')->limit(10)->get(['id', 'invoice_number', 'customer_name_snapshot', 'sale_date', 'total'])->toArray(),
            'recent_purchases' => Purchase::where('user_id', $userId)->where('business_id', $business->id)->latest('id')->limit(8)->get(['id', 'purchase_number', 'supplier_name_snapshot', 'purchase_date', 'total', 'balance_due'])->toArray(),
            'recent_expenses' => Expense::where('user_id', $userId)->where('business_id', $business->id)->latest('id')->limit(8)->get(['id', 'expense_number', 'category', 'expense_date', 'total', 'balance_due'])->toArray(),
            'inventory_alerts' => Product::where('user_id', $userId)->where('business_id', $business->id)->where('track_stock', true)->whereNotNull('low_stock_threshold')->whereColumn('current_stock', '<=', 'low_stock_threshold')->orderBy('current_stock')->limit(15)->get(['id', 'name', 'current_stock', 'low_stock_threshold'])->toArray(),
            'outstanding_customers' => Sale::where('user_id', $userId)->where('business_id', $business->id)->where('balance_due', '>', 0)->latest('sale_date')->limit(10)->get(['customer_name_snapshot', 'invoice_number', 'balance_due', 'due_date'])->toArray(),
            'products' => Product::where('user_id', $userId)->where('business_id', $business->id)->orderBy('name')->limit(40)->get(['id', 'name', 'sku', 'current_stock', 'selling_price', 'purchase_price'])->toArray(),
            'periods' => [
                'current_month' => [
                    'from' => $monthStart,
                    'to' => $monthEnd,
                    'sales' => (float) $salesQuery($monthStart, $monthEnd)->sum('total'),
                    'purchases' => (float) $purchasesQuery($monthStart, $monthEnd)->sum('total'),
                    'expenses' => (float) $expensesQuery($monthStart, $monthEnd)->sum('total'),
                ],
                'previous_month' => [
                    'from' => $previousMonthStart,
                    'to' => $previousMonthEnd,
                    'sales' => (float) $salesQuery($previousMonthStart, $previousMonthEnd)->sum('total'),
                    'purchases' => (float) $purchasesQuery($previousMonthStart, $previousMonthEnd)->sum('total'),
                    'expenses' => (float) $expensesQuery($previousMonthStart, $previousMonthEnd)->sum('total'),
                ],
            ],
        ];
        $history = collect($data['history'] ?? [])->take(-10)->map(fn ($item) => [
            'role' => in_array($item['role'] ?? '', ['user', 'assistant'], true) ? $item['role'] : 'user',
            'content' => Str::limit((string) ($item['content'] ?? $item['text'] ?? ''), 4000),
        ])->values()->all();
        $messages = [...$history, ['role' => 'user', 'content' => $data['message']]];
        $localAnswer = $this->localFinancialAnswer($data['message'], $context);
        if ($localAnswer) {
            $suggestions = $this->suggestionsFor($data['message'], $context);
            $reply = $localAnswer['message'];
            return response()->json([...$this->saveConversation($request, $data, $reply, null), 'configured' => true, 'message' => $reply, 'kind' => 'answer', 'action' => null, 'suggestions' => $suggestions]);
        }
        if (! empty($data['image'])) {
            $messages[count($messages) - 1]['content'] = [
                ['type' => 'text', 'text' => $data['message']],
                ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $this->imageMime($data['image']), 'data' => $this->imageData($data['image'])]],
            ];
        }

        $system = <<<'PROMPT'
You are Karobar AI Accountant for an Indian small business. Understand English, Hindi, Hinglish, and normal speech-to-text errors.
Use the supplied business context to give grounded, practical answers. Never invent names, quantities, prices, balances, dates, invoice numbers, or stock levels. If the information is absent, say what is missing and ask one focused follow-up question. For a transaction, prepare a proposal and wait for explicit confirmation; never claim that an entry was posted. If the user asks to edit an existing invoice or expense, return operation "update" and the matching record id only when it is present in the supplied context.
For financial questions, calculate from the supplied figures, state the period used, and distinguish sales, purchases, expenses, profit, receivables, payables, and stock. Keep answers concise but include the important next step. Reply in the user's language when clear; otherwise use simple Hinglish.
Return ONLY valid JSON with this shape: {"kind":"answer" or "proposal","message":"short helpful response","action":null or {"type":"sale"|"purchase"|"expense"|"product","payload":{...}}}.
For proposals, payload must match Karobar APIs. Sale fields: sale_date YYYY-MM-DD, payment_method cash|upi|card|bank|credit, customer_id nullable, customer_name nullable (the name the user mentioned), prices_include_gst boolean, export_under_lut boolean, notes nullable, items [{description,quantity,unit_rate,gst_rate,discount}]. Purchase fields are analogous with purchase_date, supplier_id, supplier_name, supplier_bill_number, prices_include_gst, claim_input_tax_credit, reverse_charge, items. Expense fields: expense_date, category, payment_method cash|upi|bank|card|unpaid, amount, gst_rate, claim_input_tax_credit, supplier_id nullable, note. Use null for unknown IDs and ask a clarification instead of guessing. Never fill an unknown numeric field with 0 or 1 merely to complete a proposal; leave it null or empty. Amounts are in INR.
Product proposal fields are name, sku, barcode, hsn_sac, category_id, unit_code, gst_rate, purchase_price, selling_price, is_service, track_stock, batch_tracking, low_stock_threshold, opening_quantity, and opening_unit_cost. When the user asks to add/create a product and provides its name, return a product proposal immediately; leave unknown fields empty for the form instead of asking for all details in plain text.
PROMPT;
        $payload = [
            'model' => $setting->model,
            'max_tokens' => min((int) $setting->max_tokens, 900),
            'temperature' => min((float) $setting->temperature, 0.45),
            'system' => $system."\nBusiness context: ".json_encode($context),
            'messages' => $messages,
        ];

        try {
            $response = Http::timeout(30)->withHeaders([
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
            $action = $action ? $this->sanitizeAction($action, $data['message']) : null;
            $kind = $action ? 'proposal' : ($parsed['kind'] ?? 'answer');
            $reply = $parsed['message'] ?? $text;
            return response()->json([...$this->saveConversation($request, $data, $reply, $action), 'configured' => true, 'message' => $reply, 'kind' => $kind, 'action' => $action, 'suggestions' => $this->suggestionsFor($data['message'], $context)]);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'AI service is temporarily unavailable. Your books were not changed.'], 502);
        }
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'array'],
            'action.type' => ['required', 'in:sale,purchase,expense,product'],
            'action.operation' => ['nullable', Rule::in(['create', 'update'])],
            'action.id' => ['required_if:action.operation,update', 'nullable', 'integer', 'min:1'],
            'action.payload' => ['required', 'array'],
        ]);
        $type = $data['action']['type'];
        $payload = $data['action']['payload'];
        if ($type === 'product') {
            foreach (['category_id', 'opening_quantity', 'opening_unit_cost', 'low_stock_threshold', 'purchase_price', 'selling_price'] as $field) {
                if (($payload[$field] ?? '') === '') {
                    $payload[$field] = null;
                }
            }
            $child = Request::create('/api/inventory/products', ($data['action']['operation'] ?? 'create') === 'update' ? 'PATCH' : 'POST', $payload);
            $child->setUserResolver(fn () => $request->user());
            $controller = app(InventoryController::class);
            $response = ($data['action']['operation'] ?? 'create') === 'update'
                ? $controller->update($child, (int) ($data['action']['id'] ?? 0))
                : $controller->store($child);
            return response()->json(['message' => 'Product saved successfully.', 'data' => $response->getData(true)], $response->getStatusCode());
        }
        if (in_array($type, ['sale', 'purchase'], true)) {
            $payload['prices_include_gst'] = (bool) ($payload['prices_include_gst'] ?? false);
            $payload['items'] = collect($payload['items'] ?? [])->map(fn (array $item): array => [
                ...$item,
                'unit_rate' => $item['unit_rate'] ?? $item['unit_price'] ?? null,
                'discount' => $item['discount'] ?? null,
                'gst_rate' => $item['gst_rate'] ?? null,
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
    private function localFinancialAnswer(string $message, array $context): ?array
    {
        $lower = mb_strtolower($message);
        $isQuestion = str_contains($message, '?') || (bool) preg_match('/\b(kitna|kitni|kya|how much|what is|show|batao|bataiye|profit|sales|sale|purchase|expense|kharcha|p&l|loss)\b/u', $lower);
        $isTransaction = (bool) preg_match('/\b(becha|bechi|bech|sold|sell|sale|kharida|kharidi|kharide|kharid|purchase|purchased|bought|buy|expense|kharcha|paid|pay|add|record|entry)\b/u', $lower);
        if (! $isQuestion || $isTransaction) return null;

        $period = $context['periods']['current_month'];
        $periodLabel = 'this month';
        if (preg_match('/\b(last month|pichle mahine|pichhla mahina)\b/u', $lower)) {
            $period = $context['periods']['previous_month'];
            $periodLabel = 'last month';
        }
        $sales = (float) $period['sales'];
        $purchases = (float) $period['purchases'];
        $expenses = (float) $period['expenses'];
        $profit = $sales - $purchases - $expenses;
        $money = static fn (float $value): string => '₹'.number_format($value, 2, '.', ',');
        $previousProfit = (float) $context['periods']['previous_month']['sales'] - (float) $context['periods']['previous_month']['purchases'] - (float) $context['periods']['previous_month']['expenses'];

        if (preg_match('/\b(profit|p&l|p\s*and\s*l|munafa|munafaa|faayda|faida|loss|nuksaan)\b/u', $lower)) {
            $change = $previousProfit == 0.0 ? null : (($profit - $previousProfit) / abs($previousProfit)) * 100;
            $comparison = $change === null ? 'There is no comparable previous-month profit figure.' : sprintf('%s compared with last month.', ($change >= 0 ? '+' : '').number_format($change, 1).'%');
            return ['message' => ucfirst($periodLabel).' profit estimate is '.$money($profit).".\nSales: ".$money($sales).' · Purchases: '.$money($purchases).' · Expenses: '.$money($expenses).".\n".$comparison.' This is calculated from recorded entries for '.$period['from'].' to '.$period['to'].'.'];
        }
        if (preg_match('/\b(today|aaj)\b.*\b(sale|sales|bikri)\b|\b(sale|sales|bikri)\b.*\b(today|aaj)\b/u', $lower)) {
            return ['message' => "Today's recorded sales are ".$money((float) $context['today_sales']).'.'];
        }
        if (preg_match('/\b(sale|sales|bikri)\b/u', $lower)) return ['message' => ucfirst($periodLabel).' recorded sales are '.$money($sales).'.'];
        if (preg_match('/\b(purchase|purchases|kharidari)\b/u', $lower)) return ['message' => ucfirst($periodLabel).' recorded purchases are '.$money($purchases).'.'];
        if (preg_match('/\b(expense|expenses|kharcha|kharch)\b/u', $lower)) return ['message' => ucfirst($periodLabel).' recorded expenses are '.$money($expenses).'.'];
        return null;
    }
    private function suggestionsFor(string $message, array $context): array
    {
        $lower = mb_strtolower($message);
        if (preg_match('/\b(stock|inventory|product|maal|samaan|reorder|expiry)\b/u', $lower)) {
            return ['Which products are low in stock?', 'Show my stock value', 'Which batches expire soon?'];
        }
        if (preg_match('/\b(customer|customer ka|balance|udhaar|receivable|paise)\b/u', $lower)) {
            return ['Who owes me money?', 'Show Sharma customer ledger', 'Show overdue customer invoices'];
        }
        if (preg_match('/\b(profit|sales|sale|purchase|expense|kharcha|p&l)\b/u', $lower)) {
            return ['Compare this month with last month', 'Which customer owes the most?', 'Show low-stock products'];
        }
        return [
            'What is my profit this month?',
            'Who owes me money?',
            count($context['inventory_alerts'] ?? []) ? 'Show products I need to reorder' : 'Show today’s sales',
        ];
    }
    private function draftFromMessage(string $message): ?array
    {
        $lower = mb_strtolower($message);
        $isQuestion = (bool) preg_match('/\?|\b(kitni|kitna|today sales|aaj ki sale|report|show|update batao)\b/u', $lower);
        if ($isQuestion && ! preg_match('/\b(becha|bechi|bech|sold|sell|kharida|kharidi|kharide|kharid|purchase|purchased|bought|buy|kharcha|expense|rent|salary|bill paid|paid)\b/u', $lower)) return null;
        $isSale = (bool) preg_match('/\b(becha|bechi|bech|sale|sold|sell)\b/u', $lower);
        $isPurchase = (bool) preg_match('/\b(kharida|kharidi|kharide|kharid|purchase|purchased|bought|buy)\b/u', $lower);
        $isExpense = (bool) preg_match('/\b(kharcha|expense|rent|salary|bill paid|paid)\b/u', $lower);
        $isProduct = (bool) preg_match('/\b(product|inventory|item|maal|samaan)\b/u', $lower) && (bool) preg_match('/\b(add|create|new|ban[a-z]*|jod[a-z]*|daal[a-z]*)\b/u', $lower);
        if (! $isSale && ! $isPurchase && ! $isExpense && ! $isProduct) return null;
        if ($isProduct) return ['type' => 'product', 'payload' => [
            'name' => $this->productNameFromMessage($message), 'sku' => '', 'barcode' => '', 'hsn_sac' => '', 'category_id' => null,
            'unit_code' => 'pcs', 'gst_rate' => 0, 'purchase_price' => '', 'selling_price' => '', 'is_service' => false,
            'track_stock' => true, 'batch_tracking' => false, 'low_stock_threshold' => '', 'opening_quantity' => '', 'opening_unit_cost' => '',
        ]];
        $amount = $this->amountFromMessageV2($message);
        $quantity = $this->quantityFromMessage($message) ?? '';
        $description = $this->itemDescriptionFromMessage($message);
        $date = now()->toDateString();
        if ($isSale) return ['type' => 'sale', 'payload' => ['sale_date' => $date, 'payment_method' => $this->paymentFromMessage($lower) ?? 'cash', 'customer_id' => null, 'customer_name' => $this->customerNameFromMessage($message), 'prices_include_gst' => false, 'export_under_lut' => false, 'items' => [['description' => $description, 'quantity' => $quantity, 'unit_rate' => $amount ?? '', 'gst_rate' => 0, 'discount' => 0]]]];
        if ($isPurchase) return ['type' => 'purchase', 'payload' => ['purchase_date' => $date, 'payment_method' => $this->paymentFromMessage($lower) ?? 'cash', 'supplier_id' => null, 'prices_include_gst' => false, 'claim_input_tax_credit' => false, 'reverse_charge' => false, 'items' => [['description' => $description, 'quantity' => $quantity, 'unit_rate' => $amount ?? '', 'gst_rate' => 0, 'discount' => 0]]]];
        return ['type' => 'expense', 'payload' => ['expense_date' => $date, 'category' => 'other', 'payment_method' => $this->paymentFromMessage($lower) ?? 'cash', 'amount' => $amount ?? '', 'gst_rate' => 0, 'claim_input_tax_credit' => false, 'note' => $message]];
    }
    private function amountFromMessage(string $message): ?float
    {
        if (! preg_match('/(?:₹|rs\.?|inr\.?|price|rate|amount)\s*(?:is|of|ka|ki)?\s*(\d[\d,]*(?:\.\d+)?)\s*(k|thousand|hazaar|lakh|rupees|rupaye)?/iu', $message, $match)
            && ! preg_match('/(\d[\d,]*(?:\.\d+)?)\s*(k|thousand|hazaar|lakh)\s*(?:ka|ki)?/iu', $message, $match)
            && ! preg_match('/(\d[\d,]*(?:\.\d+)?)\s*(?:me|mein|में)\b/iu', $message, $match)
            && ! preg_match('/(?:total|bill|price|rate|amount)[^\d]{0,30}(\d[\d,]*(?:\.\d+)?)/iu', $message, $match)) return null;
        $value = (float) str_replace(',', '', $match[1]);
        return match (mb_strtolower($match[2] ?? '')) { 'k', 'thousand', 'hazaar' => $value * 1000, 'lakh' => $value * 100000, default => $value };
    }
    private function amountFromMessageV2(string $message): ?float
    {
        $patterns = [
            '/(?:\x{20B9}|rs\.?|inr\.?|price|rate|amount)\s*(?:is|of|ka|ki)?\s*(\d[\d,]*(?:\.\d+)?)\s*(k|thousand|hazaar|lakh|rupees|rupaye)?/iu',
            '/(\d[\d,]*(?:\.\d+)?)\s*(k|thousand|hazaar|lakh)\s*(?:ka|ki)?/iu',
            '/(\d[\d,]*(?:\.\d+)?)\s*(?:me|mein|\x{092E}\x{0947}\x{0902})\b/iu',
            '/(?:total|bill|price|rate|amount)[^\d]{0,30}(\d[\d,]*(?:\.\d+)?)/iu',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $message, $match)) {
                $value = (float) str_replace(',', '', $match[1]);
                return match (mb_strtolower($match[2] ?? '')) {
                    'k', 'thousand', 'hazaar' => $value * 1000,
                    'lakh' => $value * 100000,
                    default => $value,
                };
            }
        }
        return null;
    }
    private function quantityFromMessage(string $message): ?float
    {
        if (preg_match('/\b(\d+(?:\.\d+)?)\s*(?:x|units?|pcs?|pieces?|items?)\b/iu', $message, $match)) {
            return (float) $match[1];
        }
        if (preg_match('/\b(\d+(?:\.\d+)?)\s+([\p{L}][\p{L} -]{1,40}?)\s+(?:purchase|purchased|kharida|kharidi|kharide|kharid|bought|buy|becha|bechi|sold|sell)\b/iu', $message, $match)) {
            $middle = mb_strtolower(trim($match[2]));
            if (! preg_match('/\b(?:hazaar|thousand|lakh|rupees?|rupaye|ka maal|ki maal|ke maal|maal|samaan|item)\b/iu', $middle)) {
                return (float) $match[1];
            }
        }
        return null;
    }
    private function itemDescriptionFromMessage(string $message): string
    {
        $description = trim($message);
        $description = preg_replace('/\b(?:cash|upi|card|bank|credit)(?:\s+(?:liya|li|paid|payment))?\b.*$/iu', '', $description);
        $description = preg_replace('/\s+\b(?:purchase|purchased|kharida|kharidi|kharide|kharid|bought|buy|becha|bechi|sold|sell)\b.*$/iu', '', $description);
        if (preg_match('/\b(?:ko|to)\s+(.+)$/iu', $description, $match)) {
            $description = trim($match[1]);
        } else {
            $description = preg_replace('/^(?:(?:aaj|today)\s+)?(?:maine|i)\s+/iu', '', $description);
        }
        $description = preg_replace('/\b(?:₹|rs\.?|inr\.?)?\s*\d[\d,]*(?:\.\d+)?\s*(?:k|thousand|hazaar|lakh|rupees|rupaye)?\b/iu', '', $description);
        $description = preg_replace('/\b\d+(?:\.\d+)?\s*(?:x|units?|pcs?|pieces?|items?)?\b/iu', '', $description);
        $description = preg_replace('/\b(?:ka|ki|ke|maal|samaan|item|sale|purchase)\b/iu', '', $description);
        $description = trim(preg_replace('/\s+/', ' ', $description));
        return trim(\Illuminate\Support\Str::limit($description, 120));
    }
    private function sanitizeAction(array $action, string $message): array
    {
        if (($action['type'] ?? null) === 'product') {
            if (empty(trim((string) ($action['payload']['name'] ?? '')))) {
                $action['payload']['name'] = $this->productNameFromMessage($message);
            }
            return $action;
        }
        if (($action['type'] ?? null) === 'sale' && empty($action['payload']['customer_name'])) {
            $action['payload']['customer_name'] = $this->customerNameFromMessage($message);
        }
        $explicitAmount = $this->amountFromMessageV2($message);
        if (($action['type'] ?? null) === 'expense') {
            if ($explicitAmount !== null) {
                $action['payload']['amount'] = $explicitAmount;
            }
            return $action;
        }
        if (! in_array($action['type'] ?? null, ['sale', 'purchase'], true)) return $action;
        $quantity = $this->quantityFromMessage($message);
        $description = $this->itemDescriptionFromMessage($message);
        if (! empty($action['payload']['items'])) {
            $action['payload']['items'][0]['quantity'] = $quantity ?? '';
            if ($description === '') {
                $action['payload']['items'][0]['description'] = '';
                $action['payload']['items'][0]['product_id'] = null;
                $action['payload']['items'][0]['unit_rate'] = $explicitAmount ?? '';
            } else {
                if (empty($action['payload']['items'][0]['product_id'])) {
                    $action['payload']['items'][0]['description'] = $description;
                }
                if ($explicitAmount !== null) {
                    $action['payload']['items'][0]['unit_rate'] = $explicitAmount;
                }
            }
            if ($quantity !== null && $explicitAmount === null) {
                $action['payload']['items'][0]['unit_rate'] = '';
            }
        }
        return $action;
    }
    private function productNameFromMessage(string $message): string
    {
        $name = trim($message);
        $name = preg_replace('/\b(?:please|pls|sirf|just|mujhe|mujhko|i|want|need|add|create|new|product|inventory|item|maal|samaan|mein|me|to|in|karna|krna|hai|h|jodo|jodna|daalo|daalna|banao|banana)\b/iu', ' ', $name);
        $name = preg_replace('/\b(?:add|create|new)\s+(?:a\s+)?(?:product|item)\b/iu', ' ', $name);
        $name = trim(preg_replace('/\s+/', ' ', $name));
        return trim(\Illuminate\Support\Str::limit($name, 180));
    }
    private function customerNameFromMessage(string $message): ?string
    {
        if (preg_match('/\b([\p{L}][\p{L} .&\'-]{0,79}?)\s+(?:ko|to)\b/iu', $message, $match)) {
            $name = trim($match[1]);
            $name = preg_replace('/^(?:aaj|today|maine|i|sale|sell)\s+/iu', '', $name);
            return $name !== '' ? trim($name) : null;
        }
        if (preg_match('/\b(?:customer|client)\s*[:=-]?\s*([\p{L}][\p{L} .&\'-]{0,79})/iu', $message, $match)) {
            return trim($match[1]);
        }
        return null;
    }
    private function paymentFromMessage(string $message): ?string { foreach (['upi', 'bank', 'card', 'credit', 'cash'] as $method) if (str_contains($message, $method)) return $method; return null; }
    private function parseJson(string $text): array { $text = trim(preg_replace('/^```(?:json)?|```$/i', '', $text)); $parsed = json_decode($text, true); return is_array($parsed) ? $parsed : ['message' => $text, 'kind' => 'answer']; }
    private function imageMime(string $image): string { return str_starts_with($image, 'data:image/png') ? 'image/png' : (str_starts_with($image, 'data:image/webp') ? 'image/webp' : 'image/jpeg'); }
    private function imageData(string $image): string { return str_contains($image, ',') ? explode(',', $image, 2)[1] : $image; }
    private function validateImageData(string $image): void
    {
        if (! preg_match('/^data:(image\/(?:jpeg|png|webp));base64,([A-Za-z0-9+\/]+=*)$/', $image, $matches)) {
            throw ValidationException::withMessages(['image' => ['Upload a valid JPEG, PNG, or WebP image.']]);
        }
        $decoded = base64_decode($matches[2], true);
        if ($decoded === false || strlen($decoded) > 8 * 1024 * 1024 || @getimagesizefromstring($decoded) === false) {
            throw ValidationException::withMessages(['image' => ['The image is invalid or exceeds the 8 MB limit.']]);
        }
    }
}
