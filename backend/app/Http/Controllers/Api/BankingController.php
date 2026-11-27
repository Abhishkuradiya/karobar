<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BankAccount;
use App\Models\BankStatementTransaction;
use App\Models\JournalLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BankingController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = BankAccount::query()
            ->where('user_id', $request->user()->id)
            ->where('business_id', $request->user()->activeBusiness->id)
            ->where('is_active', true)
            ->withCount([
                'statementTransactions as unreconciled_count' => fn (Builder $query) => $query->whereNull('reconciled_at'),
            ])
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $accounts]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeIdentifiers($request);
        $validated = $request->validate($this->rules());
        $user = $request->user();
        $account = BankAccount::create([
            'user_id' => $user->id,
            'business_id' => $user->activeBusiness->id,
            'name' => trim($validated['name']),
            'bank_name' => trim($validated['bank_name']),
            'account_last4' => isset($validated['account_number'])
                ? substr(preg_replace('/\s+/', '', $validated['account_number']), -4)
                : null,
            'ifsc' => isset($validated['ifsc']) ? strtoupper($validated['ifsc']) : null,
            'upi_id' => $validated['upi_id'] ?? null,
        ]);

        return response()->json($account, 201);
    }

    public function update(Request $request, int $account): JsonResponse
    {
        $record = $this->ownedAccount($request, $account);
        $this->normalizeIdentifiers($request);
        $validated = $request->validate($this->rules());
        $record->update([
            'name' => trim($validated['name']),
            'bank_name' => trim($validated['bank_name']),
            'account_last4' => isset($validated['account_number'])
                ? substr(preg_replace('/\s+/', '', $validated['account_number']), -4)
                : $record->account_last4,
            'ifsc' => isset($validated['ifsc']) ? strtoupper($validated['ifsc']) : null,
            'upi_id' => $validated['upi_id'] ?? null,
        ]);

        return response()->json($record->fresh());
    }

    public function destroy(Request $request, int $account): JsonResponse
    {
        $record = $this->ownedAccount($request, $account);
        if ($record->statementTransactions()->exists()) {
            throw ValidationException::withMessages([
                'account' => ['This account has imported statement history and cannot be deleted.'],
            ]);
        }

        $record->update(['is_active' => false]);
        $record->delete();

        return response()->json(null, 204);
    }

    public function import(Request $request, int $account): JsonResponse
    {
        $record = $this->ownedAccount($request, $account);
        $validated = $request->validate([
            'statement' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);
        $rows = $this->readCsv($validated['statement']->getRealPath());
        $created = 0;
        $duplicates = 0;

        DB::transaction(function () use ($rows, $record, $request, &$created, &$duplicates): void {
            foreach ($rows as $row) {
                $fingerprint = hash('sha256', implode('|', [
                    $row['date'],
                    $row['line_number'],
                    mb_strtolower(trim($row['description'])),
                    mb_strtolower(trim($row['reference'] ?? '')),
                    number_format($row['amount'], 2, '.', ''),
                ]));
                $transaction = BankStatementTransaction::firstOrCreate(
                    [
                        'bank_account_id' => $record->id,
                        'fingerprint' => $fingerprint,
                    ],
                    [
                        'user_id' => $request->user()->id,
                        'business_id' => $record->business_id,
                        'transaction_date' => $row['date'],
                        'description' => $row['description'],
                        'reference' => $row['reference'] ?? null,
                        'amount' => $row['amount'],
                    ]
                );
                $transaction->wasRecentlyCreated ? $created++ : $duplicates++;
            }
        }, 3);

        return response()->json([
            'imported' => $created,
            'duplicates_skipped' => $duplicates,
            'total_rows' => count($rows),
        ], 201);
    }

    public function reconciliation(Request $request, int $account): JsonResponse
    {
        $record = $this->ownedAccount($request, $account);
        $activeAccountCount = BankAccount::query()
            ->where('user_id', $request->user()->id)
            ->where('business_id', $record->business_id)
            ->where('is_active', true)
            ->count();
        $transactions = BankStatementTransaction::query()
            ->where('business_id', $record->business_id)
            ->where('bank_account_id', $record->id)
            ->whereNull('reconciled_at')
            ->orderBy('transaction_date')
            ->orderBy('id')
            ->get();
        $matched = BankStatementTransaction::query()
            ->where('business_id', $record->business_id)
            ->where('bank_account_id', $record->id)
            ->whereNotNull('reconciled_at')
            ->with(['matchedJournalLine.journalEntry:id,description'])
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->limit(100)
            ->get();
        $journalLines = JournalLine::query()
            ->whereHas('journalEntry', fn (Builder $query) => $query
                ->where('business_id', $record->business_id))
            ->where('account_code', 'bank')
            ->whereNull('reconciled_at')
            ->where(function (Builder $query) use ($record, $activeAccountCount): void {
                $query->where('bank_account_id', $record->id);
                if ($activeAccountCount === 1) {
                    $query->orWhereNull('bank_account_id');
                }
            })
            ->with(['journalEntry:id,entry_date,description,source_type,source_id'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (JournalLine $line): array => [
                'id' => $line->id,
                'date' => $line->journalEntry->entry_date->format('Y-m-d'),
                'description' => $line->journalEntry->description,
                'source_type' => $line->journalEntry->source_type,
                'source_id' => $line->journalEntry->source_id,
                'amount' => round((float) $line->debit - (float) $line->credit, 2),
            ]);
        $legacyLines = JournalLine::query()
            ->whereHas('journalEntry', fn (Builder $query) => $query->where('business_id', $record->business_id))
            ->where('account_code', 'bank')
            ->whereNull('bank_account_id')
            ->whereNull('reconciled_at')
            ->with(['journalEntry:id,entry_date,description,source_type,source_id'])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (JournalLine $line): array => [
                'id' => $line->id,
                'date' => $line->journalEntry->entry_date->format('Y-m-d'),
                'description' => $line->journalEntry->description,
                'source_type' => $line->journalEntry->source_type,
                'amount' => round((float) $line->debit - (float) $line->credit, 2),
            ]);

        return response()->json([
            'account' => $record,
            'statement_transactions' => $transactions,
            'book_transactions' => $journalLines,
            'reconciled_transactions' => $matched,
            'legacy_unassigned_entries' => $activeAccountCount > 1 ? $legacyLines : [],
        ]);
    }

    public function assignLegacy(Request $request, int $account, int $journalLine): JsonResponse
    {
        $record = $this->ownedAccount($request, $account);
        DB::transaction(function () use ($record, $journalLine): void {
            $line = JournalLine::query()
                ->whereHas('journalEntry', fn (Builder $query) => $query->where('business_id', $record->business_id))
                ->where('account_code', 'bank')
                ->whereNull('bank_account_id')
                ->whereNull('reconciled_at')
                ->lockForUpdate()
                ->findOrFail($journalLine);
            $line->update(['bank_account_id' => $record->id]);
        }, 3);

        return response()->json(['message' => 'Book entry assigned to bank account.']);
    }

    public function match(Request $request, int $account): JsonResponse
    {
        $record = $this->ownedAccount($request, $account);
        $validated = $request->validate([
            'statement_transaction_id' => ['required', 'integer'],
            'journal_line_id' => ['required', 'integer'],
        ]);
        $result = DB::transaction(function () use ($record, $validated): BankStatementTransaction {
            $transaction = BankStatementTransaction::query()
                ->where('business_id', $record->business_id)
                ->where('bank_account_id', $record->id)
                ->lockForUpdate()
                ->findOrFail($validated['statement_transaction_id']);
            $line = JournalLine::query()
                ->whereHas('journalEntry', fn (Builder $query) => $query->where('business_id', $record->business_id))
                ->where('account_code', 'bank')
                ->lockForUpdate()
                ->findOrFail($validated['journal_line_id']);

            if ($transaction->reconciled_at || $transaction->matched_journal_line_id || $line->reconciled_at) {
                throw ValidationException::withMessages(['match' => ['One of these entries has already been reconciled.']]);
            }
            if ($line->bank_account_id && $line->bank_account_id !== $record->id) {
                throw ValidationException::withMessages(['journal_line_id' => ['This book entry belongs to a different bank account.']]);
            }
            if (! $line->bank_account_id && BankAccount::query()
                ->where('user_id', $record->user_id)
                ->where('business_id', $record->business_id)
                ->where('is_active', true)
                ->count() > 1) {
                throw ValidationException::withMessages([
                    'journal_line_id' => ['This older bank entry is not linked to an account. Assign it before reconciling multiple bank accounts.'],
                ]);
            }
            $statementAmount = round((float) $transaction->amount, 2);
            $bookAmount = round((float) $line->debit - (float) $line->credit, 2);
            if ((int) round($statementAmount * 100) !== (int) round($bookAmount * 100)) {
                throw ValidationException::withMessages(['journal_line_id' => ['The statement and book amounts must match, including direction.']]);
            }

            $line->update([
                'bank_account_id' => $record->id,
                'reconciled_at' => now(),
            ]);
            $transaction->update([
                'matched_journal_line_id' => $line->id,
                'reconciled_at' => now(),
            ]);

            return $transaction->fresh();
        }, 3);

        return response()->json($result);
    }

    public function unmatch(Request $request, int $account, int $transaction): JsonResponse
    {
        $record = $this->ownedAccount($request, $account);
        DB::transaction(function () use ($record, $transaction): void {
            $statement = BankStatementTransaction::query()
                ->where('business_id', $record->business_id)
                ->where('bank_account_id', $record->id)
                ->lockForUpdate()
                ->findOrFail($transaction);
            if ($statement->matched_journal_line_id) {
                JournalLine::query()
                    ->whereKey($statement->matched_journal_line_id)
                    ->update(['reconciled_at' => null]);
            }
            $statement->update([
                'matched_journal_line_id' => null,
                'reconciled_at' => null,
            ]);
        }, 3);

        return response()->json(null, 204);
    }

    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The uploaded statement could not be opened.');
        }

        try {
            $header = fgetcsv($handle);
            if (! is_array($header)) {
                throw ValidationException::withMessages(['statement' => ['The CSV file is empty.']]);
            }
            $header = array_map(fn ($value): string => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $value))), $header);
            $dateColumn = $this->findColumn($header, ['date', 'transaction date', 'value date']);
            $descriptionColumn = $this->findColumn($header, ['description', 'narration', 'details', 'particulars']);
            $debitColumn = $this->findColumn($header, ['debit', 'withdrawal', 'withdrawals']);
            $creditColumn = $this->findColumn($header, ['credit', 'deposit', 'deposits']);
            $amountColumn = $this->findColumn($header, ['amount', 'transaction amount']);
            $typeColumn = $this->findColumn($header, ['type', 'transaction type', 'dr/cr']);
            $referenceColumn = $this->findColumn($header, ['reference', 'reference number', 'ref no', 'cheque no']);

            if ($dateColumn === null || $descriptionColumn === null
                || ($amountColumn === null && ($debitColumn === null || $creditColumn === null))) {
                throw ValidationException::withMessages([
                    'statement' => ['CSV needs Date and Description columns, plus Amount or both Debit and Credit columns.'],
                ]);
            }

            $rows = [];
            $lineNumber = 1;
            while (($columns = fgetcsv($handle)) !== false) {
                $lineNumber++;
                if (count($columns) === 1 && trim((string) $columns[0]) === '') {
                    continue;
                }
                $date = $this->parseDate((string) ($columns[$dateColumn] ?? ''));
                $description = trim((string) ($columns[$descriptionColumn] ?? ''));
                if (! $date || $date > now()->toDateString() || $description === '') {
                    throw ValidationException::withMessages([
                        'statement' => ["Row {$lineNumber} must include a valid non-future date and description."],
                    ]);
                }
                if ($amountColumn !== null) {
                    $amount = $this->parseAmount((string) ($columns[$amountColumn] ?? ''));
                    $type = strtolower(trim((string) ($typeColumn !== null ? ($columns[$typeColumn] ?? '') : '')));
                    if ($type === 'dr' || str_contains($type, 'debit') || str_contains($type, 'withdraw')) {
                        $amount = -abs($amount);
                    } elseif ($type === 'cr' || str_contains($type, 'credit') || str_contains($type, 'deposit')) {
                        $amount = abs($amount);
                    }
                } else {
                    $debit = abs($this->parseAmount((string) ($columns[$debitColumn] ?? '')));
                    $credit = abs($this->parseAmount((string) ($columns[$creditColumn] ?? '')));
                    if ($debit > 0 && $credit > 0) {
                        throw ValidationException::withMessages([
                            'statement' => ["Row {$lineNumber} cannot have both debit and credit amounts."],
                        ]);
                    }
                    $amount = round($credit - $debit, 2);
                }
                if ($amount === 0.0) {
                    continue;
                }
                $rows[] = [
                    'date' => $date,
                    'line_number' => $lineNumber,
                    'description' => mb_substr($description, 0, 255),
                    'reference' => $referenceColumn === null ? null : mb_substr(trim((string) ($columns[$referenceColumn] ?? '')), 0, 120),
                    'amount' => $amount,
                ];
                if (count($rows) > 5000) {
                    throw ValidationException::withMessages(['statement' => ['A statement import can contain at most 5,000 transactions.']]);
                }
            }

            if ($rows === []) {
                throw ValidationException::withMessages(['statement' => ['No non-zero transactions were found in the CSV.']]);
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }

    private function findColumn(array $header, array $aliases): ?int
    {
        foreach ($aliases as $alias) {
            $position = array_search($alias, $header, true);
            if ($position !== false) {
                return $position;
            }
        }

        return null;
    }

    private function parseDate(string $value): ?string
    {
        foreach (['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y'] as $format) {
            $date = \DateTime::createFromFormat('!'.$format, trim($value));
            if ($date !== false && $date->format($format) === trim($value)) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function parseAmount(string $value): float
    {
        $clean = str_replace([',', '₹', '$', ' '], '', trim($value));
        if ($clean === '') {
            return 0.0;
        }
        if (! is_numeric($clean)) {
            throw ValidationException::withMessages(['statement' => ['A CSV amount is not a valid number.']]);
        }

        $amount = round((float) $clean, 2);
        if (abs($amount) > 999999999999.99) {
            throw ValidationException::withMessages(['statement' => ['A CSV amount exceeds the allowed limit.']]);
        }

        return $amount;
    }

    private function ownedAccount(Request $request, int $id): BankAccount
    {
        return BankAccount::query()
            ->where('user_id', $request->user()->id)
            ->where('business_id', $request->user()->activeBusiness->id)
            ->where('is_active', true)
            ->findOrFail($id);
    }

    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'bank_name' => ['required', 'string', 'max:100'],
            'account_number' => ['nullable', 'digits_between:4,40'],
            'ifsc' => ['nullable', 'string', 'size:11', 'regex:/^[A-Za-z]{4}0[A-Za-z0-9]{6}$/'],
            'upi_id' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+@[A-Za-z0-9.-]+$/'],
        ];
    }

    private function normalizeIdentifiers(Request $request): void
    {
        foreach (['name', 'bank_name', 'ifsc', 'upi_id'] as $field) {
            if (is_string($request->input($field))) {
                $value = trim($request->input($field));
                if ($field === 'ifsc') {
                    $value = strtoupper($value);
                }
                $request->merge([$field => $value !== '' ? $value : null]);
            }
        }

        if (is_string($request->input('account_number'))) {
            $accountNumber = preg_replace('/\s+/', '', trim($request->input('account_number')));
            $request->merge(['account_number' => $accountNumber !== '' ? $accountNumber : null]);
        }
    }
}
