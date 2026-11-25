<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DataImport;
use App\Services\JournalPostingService;
use App\Support\ChartAccountCatalog;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DataImportController extends Controller
{
    private const IMPORT_TYPES = ['tally_xml', 'opening_balances_csv', 'journal_vouchers_csv'];

    public function index(Request $request): JsonResponse
    {
        $imports = DataImport::query()
            ->where('user_id', $request->user()->id)
            ->where('business_id', $request->user()->activeBusiness->id)
            ->latest()
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'data' => $imports->items(),
            'meta' => [
                'current_page' => $imports->currentPage(),
                'last_page' => $imports->lastPage(),
                'per_page' => $imports->perPage(),
                'total' => $imports->total(),
            ],
        ]);
    }

    public function preview(Request $request, string $type): JsonResponse
    {
        $request->validate([
            'type' => ['nullable', Rule::in(self::IMPORT_TYPES)],
            'file' => ['required', 'file', 'max:10240'],
            'opening_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        if (! in_array($type, self::IMPORT_TYPES, true)) {
            throw ValidationException::withMessages(['type' => ['Select a supported import type.']]);
        }

        $file = $request->file('file');
        $contents = file_get_contents($file->getRealPath());
        if ($contents === false || $contents === '') {
            throw ValidationException::withMessages(['file' => ['The selected file is empty or could not be read.']]);
        }

        $businessId = $request->user()->activeBusiness->id;
        $parsed = match ($type) {
            'tally_xml' => $this->parseTallyXml($contents, $businessId, $request->input('opening_date')),
            'opening_balances_csv' => $this->parseOpeningCsv($contents, $businessId, $request->input('opening_date')),
            'journal_vouchers_csv' => $this->parseJournalCsv($contents, $businessId),
        };
        $import = DataImport::create([
            'user_id' => $request->user()->id,
            'business_id' => $businessId,
            'import_type' => $type,
            'original_name' => $this->safeOriginalName($file->getClientOriginalName()),
            'status' => 'previewed',
            'preview_data' => $parsed,
        ]);

        return response()->json([
            'data' => $import,
            'summary' => [
                'entries' => count($parsed['entries']),
                'lines' => array_sum(array_map(static fn (array $entry): int => count($entry['lines']), $parsed['entries'])),
                'errors' => count($parsed['errors']),
            ],
        ], 201);
    }

    public function commit(Request $request, int $dataImport, JournalPostingService $posting): JsonResponse
    {
        $import = DataImport::query()
            ->where('user_id', $request->user()->id)
            ->where('business_id', $request->user()->activeBusiness->id)
            ->findOrFail($dataImport);
        if ($import->status !== 'previewed') {
            throw ValidationException::withMessages(['import' => ['This import has already been committed or is no longer available.']]);
        }
        $preview = $import->preview_data;
        if (! empty($preview['errors'])) {
            throw ValidationException::withMessages(['import' => ['Resolve all preview errors and upload the corrected file before committing.']]);
        }
        if (empty($preview['entries'])) {
            throw ValidationException::withMessages(['import' => ['There are no valid entries to commit.']]);
        }

        $result = DB::transaction(function () use ($request, $import, $preview, $posting): array {
            $entryIds = [];
            foreach ($preview['entries'] as $entry) {
                $posted = $posting->post(
                    $request->user()->id,
                    $import->business_id,
                    $entry['entry_date'],
                    $entry['source_type'],
                    $entry['description'],
                    $entry['lines'],
                    $import->id
                );
                $entryIds[] = $posted->id;
            }
            $resultData = ['journal_entry_ids' => $entryIds, 'entries_posted' => count($entryIds)];
            $import->update([
                'status' => 'committed',
                'result_data' => $resultData,
                'committed_at' => now(),
            ]);

            return $resultData;
        }, 3);

        return response()->json(['data' => $import->fresh(), 'result' => $result]);
    }

    private function parseOpeningCsv(string $contents, int $businessId, ?string $openingDate): array
    {
        [$headers, $rows] = $this->readCsv($contents);
        $errors = [];
        $lines = [];
        $dates = [];
        foreach ($rows as $offset => $row) {
            $lineNumber = $offset + 2;
            $account = ChartAccountCatalog::resolve($this->cell($row, $headers, ['account_code', 'account', 'account_name']), $businessId);
            if (! $account) {
                $errors[] = ['row' => $lineNumber, 'message' => 'Account code or name does not match this business chart of accounts.'];
                continue;
            }
            $date = $this->cell($row, $headers, ['entry_date', 'date']) ?: $openingDate ?: now()->startOfMonth()->toDateString();
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! strtotime($date)) {
                $errors[] = ['row' => $lineNumber, 'message' => 'Date must use YYYY-MM-DD.'];
                continue;
            }
            $dates[$date] = true;
            $debit = $this->amount($this->cell($row, $headers, ['debit']));
            $credit = $this->amount($this->cell($row, $headers, ['credit']));
            $opening = $this->cell($row, $headers, ['opening_balance', 'balance']);
            if ($opening !== '' && $debit === null && $credit === null) {
                $value = $this->amount($opening);
                if ($value === null) {
                    $errors[] = ['row' => $lineNumber, 'message' => 'Opening balance must be a valid signed number.'];
                    continue;
                }
                $debit = max(0, $value);
                $credit = max(0, -$value);
            }
            if ($debit === null || $credit === null || $debit < 0 || $credit < 0 || ($debit > 0 && $credit > 0) || ($debit === 0.0 && $credit === 0.0)) {
                $errors[] = ['row' => $lineNumber, 'message' => 'Provide a positive debit or credit amount, but not both.'];
                continue;
            }
            $lines[] = ['account_code' => $account['account_code'], 'debit' => $debit, 'credit' => $credit];
        }
        if (count($dates) > 1) {
            $errors[] = ['row' => null, 'message' => 'All opening balance rows must use the same date.'];
        }
        $entries = [];
        if ($lines && ! $errors) {
            $net = round(array_sum(array_column($lines, 'debit')) - array_sum(array_column($lines, 'credit')), 2);
            if ($net > 0) {
                $lines[] = ['account_code' => 'opening_balance_equity', 'debit' => 0, 'credit' => $net];
            } elseif ($net < 0) {
                $lines[] = ['account_code' => 'opening_balance_equity', 'debit' => abs($net), 'credit' => 0];
            }
            $entries[] = [
                'entry_date' => array_key_first($dates) ?? $openingDate ?? now()->startOfMonth()->toDateString(),
                'source_type' => 'opening_balance_import',
                'description' => 'Imported opening balances',
                'lines' => $lines,
            ];
        }

        return ['entries' => $entries, 'errors' => $errors];
    }

    private function parseJournalCsv(string $contents, int $businessId): array
    {
        [$headers, $rows] = $this->readCsv($contents);
        $groups = [];
        $errors = [];
        foreach ($rows as $offset => $row) {
            $lineNumber = $offset + 2;
            $date = $this->cell($row, $headers, ['entry_date', 'date']);
            $voucher = $this->cell($row, $headers, ['voucher_number', 'voucher', 'reference']) ?: 'row_'.$lineNumber;
            $narration = $this->cell($row, $headers, ['narration', 'description']) ?: 'Imported journal voucher '.$voucher;
            $account = ChartAccountCatalog::resolve($this->cell($row, $headers, ['account_code', 'account', 'account_name']), $businessId);
            $debit = $this->amount($this->cell($row, $headers, ['debit']));
            $credit = $this->amount($this->cell($row, $headers, ['credit']));
            $rowError = null;
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! strtotime($date)) {
                $rowError = 'Date must use YYYY-MM-DD.';
            } elseif (! $account) {
                $rowError = 'Account code or name does not match this business chart of accounts.';
            } elseif ($debit === null || $credit === null || $debit < 0 || $credit < 0 || ($debit > 0 && $credit > 0) || ($debit === 0.0 && $credit === 0.0)) {
                $rowError = 'Provide a positive debit or credit amount, but not both.';
            }
            if ($rowError) {
                $errors[] = ['row' => $lineNumber, 'message' => $rowError];
                continue;
            }
            $groups[$voucher] ??= [
                'entry_date' => $date,
                'description' => $narration,
                'lines' => [],
                'debits' => 0.0,
                'credits' => 0.0,
                'line_number' => $lineNumber,
            ];
            if ($groups[$voucher]['entry_date'] !== $date) {
                $errors[] = ['row' => $lineNumber, 'message' => 'A voucher cannot contain multiple entry dates.'];
            }
            $groups[$voucher]['lines'][] = ['account_code' => $account['account_code'], 'debit' => $debit, 'credit' => $credit];
            $groups[$voucher]['debits'] += $debit;
            $groups[$voucher]['credits'] += $credit;
        }

        $entries = [];
        foreach ($groups as $voucher => $group) {
            if ((int) round($group['debits'] * 100) !== (int) round($group['credits'] * 100)) {
                $errors[] = ['row' => $group['line_number'], 'message' => "Voucher {$voucher} does not balance; debits must equal credits."];
                continue;
            }
            $entries[] = [
                'entry_date' => $group['entry_date'],
                'source_type' => 'journal_voucher_import',
                'description' => mb_substr($group['description'], 0, 255),
                'lines' => $group['lines'],
            ];
        }

        return ['entries' => $entries, 'errors' => $errors];
    }

    private function parseTallyXml(string $contents, int $businessId, ?string $openingDate): array
    {
        if (stripos($contents, '<!DOCTYPE') !== false) {
            return ['entries' => [], 'errors' => [['row' => null, 'message' => 'XML documents with a document type declaration are not accepted.']]];
        }

        $previousErrorMode = libxml_use_internal_errors(true);
        $document = new DOMDocument();
        $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_NOCDATA);
        $parseErrors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);
        if (! $loaded) {
            return ['entries' => [], 'errors' => [['row' => null, 'message' => $parseErrors[0]->message ?? 'Could not parse the XML file.']]];
        }

        $xpath = new DOMXPath($document);
        $errors = [];
        $entries = [];
        $openingLines = [];
        $openingDate = $openingDate ?: now()->startOfMonth()->toDateString();
        $ledgerNodes = $xpath->query('//*[local-name()="LEDGER"]');
        if ($ledgerNodes) {
            foreach ($ledgerNodes as $ledgerIndex => $node) {
                $name = $node->attributes?->getNamedItem('NAME')?->nodeValue
                    ?: $xpath->evaluate('string(./*[local-name()="NAME"][1])', $node);
                $balanceText = $xpath->evaluate('string(./*[local-name()="OPENINGBALANCE"][1])', $node);
                if (trim($balanceText) === '') {
                    continue;
                }
                $balance = $this->amount($balanceText);
                if ($balance === null) {
                    $errors[] = ['row' => $ledgerIndex + 1, 'message' => "Opening balance for {$name} is invalid."];
                    continue;
                }
                if ($balance == 0.0) {
                    continue;
                }
                $account = ChartAccountCatalog::resolve((string) $name, $businessId);
                if (! $account) {
                    $errors[] = ['row' => $ledgerIndex + 1, 'message' => "Tally ledger '{$name}' is not in this business chart of accounts."];
                    continue;
                }
                $openingLines[] = [
                    'account_code' => $account['account_code'],
                    'debit' => max(0, $balance),
                    'credit' => max(0, -$balance),
                ];
            }
        }
        if ($openingLines && ! $errors) {
            $net = round(array_sum(array_column($openingLines, 'debit')) - array_sum(array_column($openingLines, 'credit')), 2);
            if ($net > 0) {
                $openingLines[] = ['account_code' => 'opening_balance_equity', 'debit' => 0, 'credit' => $net];
            } elseif ($net < 0) {
                $openingLines[] = ['account_code' => 'opening_balance_equity', 'debit' => abs($net), 'credit' => 0];
            }
            $entries[] = [
                'entry_date' => $openingDate,
                'source_type' => 'opening_balance_import',
                'description' => 'Tally opening balances',
                'lines' => $openingLines,
            ];
        }

        $voucherNodes = $xpath->query('//*[local-name()="VOUCHER"]');
        if ($voucherNodes) {
            foreach ($voucherNodes as $voucherIndex => $voucher) {
                $dateRaw = trim($xpath->evaluate('string(./*[local-name()="DATE"][1])', $voucher));
                $date = $this->tallyDate($dateRaw);
                $number = trim($xpath->evaluate('string(./*[local-name()="VOUCHERNUMBER"][1])', $voucher));
                $description = trim($xpath->evaluate('string(./*[local-name()="NARRATION"][1])', $voucher));
                $voucherLines = [];
                $debits = 0.0;
                $credits = 0.0;
                $ledgerEntries = $xpath->query('.//*[local-name()="ALLLEDGERENTRIES.LIST" or local-name()="LEDGERENTRIES.LIST"]', $voucher);
                if ($date === null) {
                    $errors[] = ['row' => $voucherIndex + 1, 'message' => "Voucher {$number} has an invalid date."];
                    continue;
                }
                if ($ledgerEntries) {
                    foreach ($ledgerEntries as $ledgerEntry) {
                        $ledgerName = trim($xpath->evaluate('string(./*[local-name()="LEDGERNAME"][1])', $ledgerEntry));
                        $rawAmount = $this->amount($xpath->evaluate('string(./*[local-name()="AMOUNT"][1])', $ledgerEntry));
                        $account = ChartAccountCatalog::resolve($ledgerName, $businessId);
                        if (! $account || $rawAmount === null || $rawAmount == 0.0) {
                            $errors[] = ['row' => $voucherIndex + 1, 'message' => "Voucher {$number} contains an unmapped account or invalid amount ({$ledgerName})."];
                            continue 2;
                        }
                        $debit = max(0, $rawAmount);
                        $credit = max(0, -$rawAmount);
                        $debits += $debit;
                        $credits += $credit;
                        $voucherLines[] = ['account_code' => $account['account_code'], 'debit' => $debit, 'credit' => $credit];
                    }
                }
                if (count($voucherLines) < 2 || (int) round($debits * 100) !== (int) round($credits * 100)) {
                    $errors[] = ['row' => $voucherIndex + 1, 'message' => "Voucher {$number} must contain at least two lines and balance exactly."];
                    continue;
                }
                $entries[] = [
                    'entry_date' => $date,
                    'source_type' => 'journal_voucher_import',
                    'description' => mb_substr($description ?: "Tally voucher {$number}", 0, 255),
                    'lines' => $voucherLines,
                ];
            }
        }

        if (! $entries && ! $errors) {
            $errors[] = ['row' => null, 'message' => 'No opening balances or journal vouchers were found in this XML file.'];
        }

        return ['entries' => $entries, 'errors' => $errors];
    }

    private function readCsv(string $contents): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $contents);
        rewind($handle);
        $rawHeaders = fgetcsv($handle);
        if (! is_array($rawHeaders)) {
            fclose($handle);

            return [[], []];
        }
        $headers = array_map(static function ($header): string {
            $header = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header);

            return strtolower(preg_replace('/[^a-z0-9]+/i', '_', trim($header)) ?? '');
        }, $rawHeaders);
        $rows = [];
        while (($cells = fgetcsv($handle)) !== false) {
            if ($cells === [null] || count(array_filter($cells, static fn ($value) => trim((string) $value) !== '')) === 0) {
                continue;
            }
            $rows[] = array_slice(array_pad($cells, count($headers), ''), 0, count($headers));
        }
        fclose($handle);

        return [$headers, $rows];
    }

    private function cell(array $row, array $headers, array $names): string
    {
        foreach ($names as $name) {
            $index = array_search($name, $headers, true);
            if ($index !== false) {
                return trim((string) ($row[$index] ?? ''));
            }
        }

        return '';
    }

    private function amount(string $value): ?float
    {
        $value = trim(str_replace([',', '₹', ' '], '', $value));
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }

        return round((float) $value, 2);
    }

    private function tallyDate(string $value): ?string
    {
        if (! preg_match('/^\d{8}$/', $value)) {
            return null;
        }
        $date = \DateTime::createFromFormat('!Ymd', $value);
        if (! $date || $date->format('Ymd') !== $value) {
            return null;
        }

        return $date->format('Y-m-d');
    }

    private function safeOriginalName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?: 'import-file';

        return mb_substr($name, 0, 255);
    }
}
