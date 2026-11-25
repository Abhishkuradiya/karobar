<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expense;
use App\Models\Purchase;
use App\Models\ReturnNote;
use App\Models\Sale;
use App\Models\SaleItem;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GstReportController extends Controller
{
    public function report(Request $request): JsonResponse
    {
        return response()->json($this->makeReport($request));
    }

    public function export(Request $request): JsonResponse|StreamedResponse
    {
        $validated = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
            'format' => ['nullable', 'in:json,csv'],
        ]);
        $report = $this->makeReport($request, $validated['month'] ?? null);
        if (($validated['format'] ?? 'json') === 'json') {
            return response()->json($report);
        }

        return Response::streamDownload(function () use ($report): void {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Section', 'GST Rate', 'HSN/SAC', 'Invoice / Reference', 'Taxable Amount', 'IGST', 'CGST', 'SGST', 'Total Tax']);
            foreach ($report['rate_summary'] as $row) {
                fputcsv($output, [
                    'Rate summary',
                    $row['gst_rate'].'%',
                    '',
                    '',
                    $row['taxable_outward'],
                    $row['output_tax']['igst'],
                    $row['output_tax']['cgst'],
                    $row['output_tax']['sgst'],
                    $row['output_tax']['total'],
                ]);
            }
            foreach ($report['gstr1']['b2c'] as $row) {
                fputcsv($output, [
                    'B2C supply',
                    $row['gst_rate'].'%',
                    '',
                    $this->spreadsheetSafe($row['place_of_supply']),
                    $row['taxable_amount'],
                    $row['tax']['igst'],
                    $row['tax']['cgst'],
                    $row['tax']['sgst'],
                    $row['tax']['total'],
                ]);
            }
            foreach ($report['hsn_summary'] as $row) {
                fputcsv($output, [
                    'HSN/SAC',
                    $row['gst_rate'].'%',
                    $this->spreadsheetSafe($row['hsn_sac']),
                    '',
                    $row['taxable_amount'],
                    $row['tax']['igst'],
                    $row['tax']['cgst'],
                    $row['tax']['sgst'],
                    $row['tax']['total'],
                ]);
            }
            foreach ($report['gstr1']['credit_notes'] as $row) {
                fputcsv($output, [
                    'Credit note',
                    '',
                    '',
                    $this->spreadsheetSafe($row['note_number'].' / '.$row['customer_name']),
                    -$row['taxable_amount'],
                    '',
                    '',
                    '',
                    -$row['tax_amount'],
                ]);
            }
            foreach ($report['gstr1']['b2b'] as $row) {
                fputcsv($output, [
                    'B2B invoice',
                    '',
                    '',
                    $this->spreadsheetSafe($row['invoice_number'].' / '.$row['customer_name']),
                    $row['taxable_amount'],
                    $row['tax']['igst'],
                    $row['tax']['cgst'],
                    $row['tax']['sgst'],
                    $row['tax']['total'],
                ]);
            }
            fputcsv($output, ['Documents issued', '', '', 'Invoices', $report['gstr1']['documents_issued']['invoices']]);
            fputcsv($output, ['Documents issued', '', '', 'Credit notes', $report['gstr1']['documents_issued']['credit_notes']]);
            fputcsv($output, ['Documents issued', '', '', 'Debit notes', $report['gstr1']['documents_issued']['debit_notes']]);
            fclose($output);
        }, "gst-report-{$report['month']}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function makeReport(Request $request, ?string $requestedMonth = null): array
    {
        $validated = $request->validate(['month' => ['nullable', 'date_format:Y-m']]);
        $month = Carbon::createFromFormat('Y-m', $requestedMonth ?? $validated['month'] ?? now()->format('Y-m'))->startOfMonth();
        $start = $month->toDateString();
        $end = $month->copy()->endOfMonth()->toDateString();
        $business = $request->user()->activeBusiness;
        $businessState = $this->stateCode($business->gstin, $business->state);

        $sales = Sale::query()
            ->where('business_id', $business->id)
            ->whereBetween('sale_date', [$start, $end])
            ->with(['customer', 'items'])
            ->orderBy('sale_date')
            ->get();
        $creditNotes = ReturnNote::query()
            ->where('business_id', $business->id)
            ->where('note_type', 'credit')
            ->whereBetween('note_date', [$start, $end])
            ->with(['sale.customer', 'items'])
            ->get();
        $debitNotes = ReturnNote::query()
            ->where('business_id', $business->id)
            ->where('note_type', 'debit')
            ->whereBetween('note_date', [$start, $end])
            ->with(['purchase.supplier', 'items'])
            ->get();
        $purchases = Purchase::query()
            ->where('business_id', $business->id)
            ->whereBetween('purchase_date', [$start, $end])
            ->with(['supplier', 'items'])
            ->get();
        $expenses = Expense::query()
            ->where('business_id', $business->id)
            ->whereBetween('expense_date', [$start, $end])
            ->where('claim_input_tax_credit', true)
            ->with('supplier')
            ->get();

        $output = $this->emptyTaxBuckets();
        $input = $this->emptyTaxBuckets();
        $rates = [];
        $hsn = [];
        $b2b = [];
        $b2c = [];
        $reverseCharge = $this->emptyTaxBuckets();
        $taxableSales = 0.0;
        $taxablePurchases = 0.0;

        foreach ($sales as $sale) {
            $customer = $sale->customer;
            $customerGstin = strtoupper((string) ($customer?->gstin ?? ''));
            $customerState = $this->stateCode($customerGstin, $customer?->state);
            $isB2B = preg_match('/^[0-9]{2}[A-Z0-9]{13}$/', $customerGstin) === 1;
            $invoice = [
                'invoice_number' => $sale->invoice_number,
                'invoice_date' => $sale->sale_date?->format('Y-m-d'),
                'customer_gstin' => $isB2B ? $customerGstin : null,
                'customer_name' => $customer?->name ?? $sale->customer_name_snapshot,
                'place_of_supply' => $customerState,
                'taxable_amount' => 0.0,
                'tax' => $this->emptyTaxBuckets(),
            ];
            foreach ($sale->items as $item) {
                $taxable = (float) $item->taxable_amount;
                $taxAmount = $sale->export_under_lut ? 0.0 : (float) $item->tax_amount;
                $parts = $this->splitTax($taxAmount, $businessState, $customerState);
                $taxableSales += $taxable;
                $this->addBuckets($output, $parts);
                $invoice['taxable_amount'] += $taxable;
                $this->addBuckets($invoice['tax'], $parts);
                $this->addRate($rates, (float) $item->gst_rate, $taxable, $parts, 'output_tax');
                $this->addHsn($hsn, $item->hsn_sac ?: 'UNSPECIFIED', (float) $item->gst_rate, (float) $item->quantity, $taxable, $parts);
                if (! $isB2B) {
                    $place = $customerState ?: $businessState ?: 'UNSPECIFIED';
                    $key = $place.'|'.number_format((float) $item->gst_rate, 2, '.', '');
                    $b2c[$key] ??= [
                        'place_of_supply' => $place,
                        'gst_rate' => (float) $item->gst_rate,
                        'taxable_amount' => 0.0,
                        'tax' => $this->emptyTaxBuckets(),
                    ];
                    $b2c[$key]['taxable_amount'] += $taxable;
                    $this->addBuckets($b2c[$key]['tax'], $parts);
                }
            }
            if ($isB2B) {
                $invoice['taxable_amount'] = round($invoice['taxable_amount'], 2);
                $invoice['tax'] = $this->roundBuckets($invoice['tax']);
                $b2b[] = $invoice;
            }
        }

        $sourceSaleItems = $this->returnSourceSaleItems($creditNotes);
        foreach ($creditNotes as $note) {
            $sale = $note->sale;
            $state = $this->stateCode($sale?->customer?->gstin, $sale?->customer?->state);
            foreach ($note->items as $item) {
                $taxable = (float) $item->taxable_amount;
                $taxAmount = (float) $item->tax_amount;
                $parts = $this->negativeBuckets($this->splitTax($taxAmount, $businessState, $state));
                $taxableSales -= $taxable;
                $this->addBuckets($output, $parts);
                $this->addRate($rates, (float) $item->gst_rate, -$taxable, $parts, 'output_tax');
                $sourceItem = $sourceSaleItems->get($item->original_sale_item_id);
                $this->addHsn(
                    $hsn,
                    $sourceItem?->hsn_sac ?: 'UNSPECIFIED',
                    (float) $item->gst_rate,
                    -(float) $item->quantity,
                    -$taxable,
                    $parts
                );
            }
        }

        foreach ($purchases as $purchase) {
            $supplier = $purchase->supplier;
            $supplierState = $this->stateCode($supplier?->gstin, $supplier?->state);
            foreach ($purchase->items as $item) {
                $taxable = (float) $item->taxable_amount;
                $parts = $this->splitTax((float) $item->tax_amount, $businessState, $supplierState);
                $taxablePurchases += $taxable;
                if ($purchase->claim_input_tax_credit) {
                    $this->addBuckets($input, $parts);
                    $this->addRate($rates, (float) $item->gst_rate, 0, $parts, 'input_tax_credit');
                }
                if ($purchase->reverse_charge) {
                    $this->addBuckets($reverseCharge, $parts);
                    $this->addBuckets($output, $parts);
                    $this->addRate($rates, (float) $item->gst_rate, 0, $parts, 'output_tax');
                }
            }
        }

        foreach ($expenses as $expense) {
            $supplier = $expense->supplier;
            $supplierState = $this->stateCode($supplier?->gstin, $supplier?->state);
            $parts = $this->splitTax((float) $expense->tax_amount, $businessState, $supplierState);
            $taxablePurchases += (float) $expense->amount;
            $this->addBuckets($input, $parts);
            $this->addRate($rates, (float) $expense->gst_rate, 0, $parts, 'input_tax_credit');
        }

        foreach ($debitNotes as $note) {
            $purchase = $note->purchase;
            $supplierState = $this->stateCode($purchase?->supplier?->gstin, $purchase?->supplier?->state);
            foreach ($note->items as $item) {
                $parts = $this->negativeBuckets($this->splitTax((float) $item->tax_amount, $businessState, $supplierState));
                $taxablePurchases -= (float) $item->taxable_amount;
                if ($purchase?->claim_input_tax_credit) {
                    $this->addBuckets($input, $parts);
                    $this->addRate($rates, (float) $item->gst_rate, 0, $parts, 'input_tax_credit');
                }
            }
        }

        $rateSummary = collect($rates)->map(function (array $row): array {
            $row['gst_rate'] = (float) $row['gst_rate'];
            $row['taxable_outward'] = round($row['taxable_outward'], 2);
            $row['output_tax'] = $this->roundBuckets($row['output_tax']);
            $row['input_tax_credit'] = $this->roundBuckets($row['input_tax_credit']);

            return $row;
        })->sortBy('gst_rate')->values()->all();
        $hsnSummary = collect($hsn)->map(function (array $row): array {
            $row['quantity'] = round($row['quantity'], 3);
            $row['taxable_amount'] = round($row['taxable_amount'], 2);
            $row['tax'] = $this->roundBuckets($row['tax']);

            return $row;
        })->sortBy('hsn_sac')->values()->all();
        $setoff = $this->calculateSetoff($output, $input);
        $issuedCount = $sales->count() + Sale::onlyTrashed()
            ->where('business_id', $business->id)
            ->whereBetween('sale_date', [$start, $end])
            ->count();

        return [
            'month' => $month->format('Y-m'),
            'period' => ['from' => $start, 'to' => $end],
            'business' => [
                'name' => $business->name,
                'gstin' => $business->gstin,
                'registration_type' => $business->gst_registration_type,
                'state_code' => $businessState,
            ],
            'summary' => [
                'taxable_sales' => round($taxableSales, 2),
                'output_tax' => $this->roundBuckets($output),
                'taxable_purchases_and_expenses' => round($taxablePurchases, 2),
                'input_tax_credit' => $this->roundBuckets($input),
                'reverse_charge_tax' => $this->roundBuckets($reverseCharge),
                'liability_after_setoff' => $setoff['liability'],
                'setoff_used' => $setoff['used'],
                'input_tax_credit_remaining' => $setoff['remaining_credit'],
            ],
            'rate_summary' => $rateSummary,
            'gstr1' => [
                'b2b' => $b2b,
                'b2c' => collect($b2c)->map(function (array $row): array {
                    $row['taxable_amount'] = round($row['taxable_amount'], 2);
                    $row['tax'] = $this->roundBuckets($row['tax']);

                    return $row;
                })->values()->all(),
                'credit_notes' => $creditNotes->map(fn (ReturnNote $note): array => [
                    'note_number' => $note->note_number,
                    'note_date' => $note->note_date?->format('Y-m-d'),
                    'customer_name' => $note->counterparty_name_snapshot,
                    'invoice_number' => $note->sale?->invoice_number,
                    'taxable_amount' => round((float) $note->taxable_amount, 2),
                    'tax_amount' => round((float) $note->tax_amount, 2),
                ])->values(),
                'documents_issued' => [
                    'invoices' => $issuedCount,
                    'credit_notes' => $creditNotes->count(),
                    'debit_notes' => $debitNotes->count(),
                ],
            ],
            'hsn_summary' => $hsnSummary,
        ];
    }

    private function stateCode(?string $gstin, ?string $state): ?string
    {
        if ($gstin && preg_match('/^[0-9]{2}/', $gstin)) {
            return substr($gstin, 0, 2);
        }
        if (! $state) {
            return null;
        }

        $codes = [
            'jammu and kashmir' => '01', 'himachal pradesh' => '02', 'punjab' => '03', 'chandigarh' => '04',
            'uttarakhand' => '05', 'haryana' => '06', 'delhi' => '07', 'rajasthan' => '08',
            'uttar pradesh' => '09', 'bihar' => '10', 'sikkim' => '11', 'arunachal pradesh' => '12',
            'nagaland' => '13', 'manipur' => '14', 'mizoram' => '15', 'tripura' => '16',
            'meghalaya' => '17', 'assam' => '18', 'west bengal' => '19', 'jharkhand' => '20',
            'odisha' => '21', 'chhattisgarh' => '22', 'madhya pradesh' => '23', 'gujarat' => '24',
            'dadra and nagar haveli and daman and diu' => '26', 'maharashtra' => '27', 'andhra pradesh' => '37',
            'karnataka' => '29', 'goa' => '30', 'lakshadweep' => '31', 'kerala' => '32',
            'tamil nadu' => '33', 'puducherry' => '34', 'andaman and nicobar islands' => '35',
            'telangana' => '36', 'ladakh' => '38',
        ];
        $normalized = mb_strtolower(trim($state));

        return $codes[$normalized] ?? (preg_match('/^[0-9]{2}$/', $normalized) ? $normalized : null);
    }

    private function splitTax(float $amount, ?string $sellerState, ?string $buyerState): array
    {
        if ($sellerState && $buyerState && $sellerState !== $buyerState) {
            return ['igst' => round($amount, 2), 'cgst' => 0.0, 'sgst' => 0.0, 'total' => round($amount, 2)];
        }

        $cgst = round($amount / 2, 2);
        $sgst = round($amount - $cgst, 2);

        return ['igst' => 0.0, 'cgst' => $cgst, 'sgst' => $sgst, 'total' => round($amount, 2)];
    }

    private function emptyTaxBuckets(): array
    {
        return ['igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0, 'total' => 0.0];
    }

    private function addBuckets(array &$target, array $amounts): void
    {
        foreach (['igst', 'cgst', 'sgst', 'total'] as $key) {
            $target[$key] += (float) ($amounts[$key] ?? 0);
        }
    }

    private function negativeBuckets(array $amounts): array
    {
        return array_map(static fn (float $amount): float => -$amount, $amounts);
    }

    private function roundBuckets(array $buckets): array
    {
        foreach (['igst', 'cgst', 'sgst', 'total'] as $key) {
            $buckets[$key] = round((float) ($buckets[$key] ?? 0), 2);
        }

        return $buckets;
    }

    private function addRate(array &$rates, float $rate, float $taxable, array $tax, string $target): void
    {
        $key = number_format($rate, 2, '.', '');
        $rates[$key] ??= [
            'gst_rate' => $rate,
            'taxable_outward' => 0.0,
            'output_tax' => $this->emptyTaxBuckets(),
            'input_tax_credit' => $this->emptyTaxBuckets(),
        ];
        if ($target === 'taxable_outward') {
            $rates[$key]['taxable_outward'] += $taxable;
        } else {
            if ($target === 'output_tax' && $taxable !== 0.0) {
                $rates[$key]['taxable_outward'] += $taxable;
            }
            $this->addBuckets($rates[$key][$target], $tax);
        }
    }

    private function addHsn(array &$hsn, string $code, float $rate, float $quantity, float $taxable, array $tax): void
    {
        $key = $code.'|'.number_format($rate, 2, '.', '');
        $hsn[$key] ??= [
            'hsn_sac' => $code,
            'gst_rate' => $rate,
            'quantity' => 0.0,
            'taxable_amount' => 0.0,
            'tax' => $this->emptyTaxBuckets(),
        ];
        $hsn[$key]['quantity'] += $quantity;
        $hsn[$key]['taxable_amount'] += $taxable;
        $this->addBuckets($hsn[$key]['tax'], $tax);
    }

    private function calculateSetoff(array $output, array $input): array
    {
        $liability = ['igst' => max(0, $output['igst']), 'cgst' => max(0, $output['cgst']), 'sgst' => max(0, $output['sgst'])];
        $credit = ['igst' => max(0, $input['igst']), 'cgst' => max(0, $input['cgst']), 'sgst' => max(0, $input['sgst'])];
        $used = ['igst' => 0.0, 'cgst' => 0.0, 'sgst' => 0.0];
        $this->consumeCredit($liability, $credit, $used, 'igst', 'igst');
        $this->consumeCredit($liability, $credit, $used, 'igst', 'cgst');
        $this->consumeCredit($liability, $credit, $used, 'igst', 'sgst');
        $this->consumeCredit($liability, $credit, $used, 'cgst', 'cgst');
        $this->consumeCredit($liability, $credit, $used, 'cgst', 'igst');
        $this->consumeCredit($liability, $credit, $used, 'sgst', 'sgst');
        $this->consumeCredit($liability, $credit, $used, 'sgst', 'igst');

        $used['total'] = round(array_sum($used), 2);
        $liability = $this->roundBuckets([...$liability, 'total' => array_sum($liability)]);
        $remaining = $this->roundBuckets([...$credit, 'total' => array_sum($credit)]);

        return ['liability' => $liability, 'used' => $used, 'remaining_credit' => $remaining];
    }

    private function consumeCredit(array &$liability, array &$credit, array &$used, string $creditType, string $taxType): void
    {
        $amount = min($liability[$taxType], $credit[$creditType]);
        $liability[$taxType] -= $amount;
        $credit[$creditType] -= $amount;
        $used[$creditType] += $amount;
    }

    private function returnSourceSaleItems(Collection $notes): Collection
    {
        $ids = $notes->flatMap(fn (ReturnNote $note) => $note->items->pluck('original_sale_item_id'))->filter()->unique();

        return $ids->isEmpty() ? collect() : SaleItem::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    private function spreadsheetSafe(string $value): string
    {
        return preg_match('/^[\s]*[=+\-@]/', $value) ? "'".$value : $value;
    }
}
