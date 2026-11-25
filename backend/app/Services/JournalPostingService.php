<?php

namespace App\Services;

use App\Models\JournalEntry;
use Illuminate\Support\Facades\DB;
use LogicException;

class JournalPostingService
{
    public function post(
        int $userId,
        int $businessId,
        string $date,
        string $sourceType,
        string $description,
        array $lines,
        ?int $sourceId = null
    ): JournalEntry {
        $entry = JournalEntry::create([
            'user_id' => $userId,
            'business_id' => $businessId,
            'entry_date' => $date,
            'source_type' => $sourceType,
            'source_id' => $sourceId ?? 0,
            'description' => $description,
        ]);
        if ($sourceId === null) {
            $entry->update(['source_id' => $entry->id]);
        }

        $entry->lines()->createMany($lines);
        $totals = $entry->lines()->selectRaw('SUM(debit) as debits, SUM(credit) as credits')->first();
        if ((int) round((float) $totals->debits * 100) !== (int) round((float) $totals->credits * 100)) {
            throw new LogicException('Journal entry must balance.');
        }

        return $entry->fresh('lines');
    }

    public function balanceForAccount(int $businessId, string $accountCode, ?string $throughDate = null): float
    {
        $query = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.business_id', $businessId)
            ->where('journal_lines.account_code', $accountCode);
        if ($throughDate !== null) {
            $query->whereDate('journal_entries.entry_date', '<=', $throughDate);
        }

        return round((float) $query->selectRaw('COALESCE(SUM(journal_lines.debit - journal_lines.credit), 0) as balance')->value('balance'), 2);
    }
}
