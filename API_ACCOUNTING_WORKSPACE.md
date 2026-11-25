# Accounting Workspace API

These endpoints support the GST, cash tally, activity, month-end close, journal,
chart of accounts, and data import pages.

## Base URL and access

Use your backend host, for example:

```text
http://<server-ip>:8000/api
```

Send these headers for JSON requests:

```http
Authorization: Bearer <token>
Accept: application/json
Content-Type: application/json
```

File previews use `multipart/form-data`. Every endpoint below requires a valid
Sanctum Bearer token and an active business. The API derives the user and
business from the authenticated session; do not send `user_id` or `business_id`
in request bodies.

## GST report

### Get a month report

```http
GET /gst/report?month=2026-11
```

`month` is optional and uses `YYYY-MM`; it defaults to the current month.

```json
{
  "month": "2026-11",
  "period": { "from": "2026-11-01", "to": "2026-11-30" },
  "business": {
    "name": "Example Traders",
    "gstin": "27AAPFU0939F1ZV",
    "registration_type": "regular",
    "state_code": "27"
  },
  "summary": {
    "taxable_sales": 1000,
    "output_tax": { "igst": 0, "cgst": 90, "sgst": 90, "total": 180 },
    "taxable_purchases_and_expenses": 300,
    "input_tax_credit": { "igst": 0, "cgst": 18, "sgst": 18, "total": 36 },
    "reverse_charge_tax": { "igst": 0, "cgst": 0, "sgst": 0, "total": 0 },
    "liability_after_setoff": { "igst": 0, "cgst": 72, "sgst": 72, "total": 144 },
    "setoff_used": { "igst": 0, "cgst": 18, "sgst": 18, "total": 36 },
    "input_tax_credit_remaining": { "igst": 0, "cgst": 0, "sgst": 0, "total": 0 }
  },
  "rate_summary": [],
  "gstr1": {
    "b2b": [],
    "b2c": [],
    "credit_notes": [],
    "documents_issued": { "invoices": 0, "credit_notes": 0, "debit_notes": 0 }
  },
  "hsn_summary": []
}
```

The report also contains:

- `rate_summary`: outward taxable amount, output tax, and eligible input tax
  credit grouped by GST rate.
- `gstr1.b2b`: registered-customer invoice data.
- `gstr1.b2c`: consumer supply totals grouped by place of supply and GST rate.
- `gstr1.credit_notes`: sales credit notes for the period.
- `hsn_summary`: sales item quantities and tax grouped by HSN/SAC and rate.

Tax is split using the business and counterparty GSTIN/state. If a counterparty
state cannot be determined, the report currently treats the supply as
intra-state. Review the source records and report with a tax professional
before filing.

### Export a report

```http
GET /gst/export?month=2026-11&format=json
GET /gst/export?month=2026-11&format=csv
```

`format` is `json` (default) or `csv`. The CSV contains rate, HSN/SAC, and B2B
invoice summaries and can be opened in spreadsheet software.

## Cash tally

### Get a date and recent tallies

```http
GET /cash-tally?date=2026-11-25
```

`date` is optional and defaults to today.

```json
{
  "date": "2026-11-25",
  "book_amount": 1250,
  "tally": null,
  "recent": []
}
```

### Save a physical count

```http
POST /cash-tally
```

```json
{
  "counted_date": "2026-11-25",
  "counted_amount": 1240,
  "note": "Till counted after closing"
}
```

Success response (`201 Created`):

```json
{
  "data": {
    "id": 14,
    "counted_date": "2026-11-25",
    "book_amount": "1250.00",
    "counted_amount": "1240.00",
    "difference": "-10.00",
    "note": "Till counted after closing",
    "journal_entry_id": 82
  }
}
```

The server calculates the book amount and difference. A non-zero difference
creates a balanced journal adjustment. Only one tally per business/date is
allowed; future dates and dates before a later saved tally are rejected.

## Activity

```http
GET /activity?from=2026-11-01&to=2026-11-30&per_page=50
```

All parameters are optional. `per_page` defaults to 50 and is capped at 100.

```json
{
  "data": [
    {
      "id": 82,
      "title": "Journal adjustment",
      "source_type": "manual_adjustment",
      "source_id": 82,
      "description": "Till shortage",
      "entry_date": "2026-11-25",
      "created_at": "2026-11-25T10:15:00.000000Z",
      "user": "Sam",
      "amount": 10,
      "is_reversal": false
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 50, "total": 1 }
}
```

## Month-end close

```http
GET /month-end-close?month=2026-11
```

`month` is optional and defaults to the current month.

```json
{
  "month": "2026-11",
  "period": { "from": "2026-11-01", "to": "2026-11-30" },
  "progress": { "completed": 3, "total": 7, "percentage": 43 },
  "data": [
    {
      "key": "bank_reconciliation",
      "title": "Match bank transactions",
      "description": "2 imported transactions are still unmatched.",
      "status": "needs_attention",
      "count": 2,
      "href": "/banking"
    }
  ]
}
```

Checklist statuses are `done`, `needs_attention`, `action_required`, and
`not_started`. The checklist covers bank reconciliation, cash tally, scanned
bills, GST review, stock balances, overdue customers, and overdue MSME
suppliers.

## Journal entries

### List/filter entries

```http
GET /journal-entries?from=2026-11-01&to=2026-11-30&account_code=cash&per_page=50
```

Filters are optional. The response includes entry lines, pagination metadata,
and matching debit/credit totals.

### Post a manual adjustment

```http
POST /journal-entries
```

```json
{
  "entry_date": "2026-11-25",
  "narration": "Correct opening cash balance",
  "lines": [
    { "account_code": "cash", "debit": 100, "credit": 0 },
    { "account_code": "owners_capital", "debit": 0, "credit": 100 }
  ]
}
```

The endpoint returns the created journal entry (`201 Created`). Provide 2–50
lines; every line must have either a positive debit or a positive credit, and
the total debits must equal total credits. `account_code` must exist in the
active business chart of accounts.

## Chart of accounts

### List accounts and balances

```http
GET /chart-of-accounts
```

Accounts are grouped under `data` by account type (`assets`, `liabilities`,
`equity`, `income`, `expenses`). Each account includes its code, name, group,
normal balance, debit/credit totals, current balance, and `is_system` flag.

### Add a custom account

```http
POST /chart-of-accounts
```

```json
{
  "display_code": "9000",
  "name": "Equipment reserve",
  "account_type": "assets",
  "group_name": "Fixed Assets"
}
```

`group_name` is optional. `account_type` must be `assets`, `liabilities`,
`equity`, `income`, or `expenses`. Codes already used by system or custom
accounts in the active business are rejected. The response includes the
generated internal `account_code` and `is_system: false` (`201 Created`).

## Data imports

### List import history

```http
GET /imports
```

Returns paginated import records for the active business. Each record includes
its status, filename, preview data, and commit result, if any.

### Upload and preview

```http
POST /imports/{type}/preview
Content-Type: multipart/form-data
```

Supported `type` values:

- `tally_xml`
- `opening_balances_csv`
- `journal_vouchers_csv`

Multipart fields: `file` (required; maximum 10 MB) and optional `opening_date`
(`YYYY-MM-DD`) for Tally or opening-balance imports. The response (`201
Created`) includes the staged import, its preview, and counts:

```json
{
  "data": {
    "id": 21,
    "import_type": "journal_vouchers_csv",
    "original_name": "journal.csv",
    "status": "previewed",
    "preview_data": {
      "entries": [
        {
          "entry_date": "2026-11-25",
          "source_type": "journal_voucher_import",
          "description": "Adjustment",
          "lines": [
            { "account_code": "cash", "debit": 100, "credit": 0 },
            { "account_code": "owners_capital", "debit": 0, "credit": 100 }
          ]
        }
      ],
      "errors": []
    }
  },
  "summary": { "entries": 1, "lines": 2, "errors": 0 }
}
```

Opening-balance CSV columns: `entry_date` (optional if `opening_date` is
provided), `account_code` (or `account` / `account_name`), `debit`, and
`credit`. Alternatively, provide `opening_balance` as a signed amount. Rows
must use one date; any difference is balanced to `opening_balance_equity`.

Journal-voucher CSV columns: `entry_date`, `voucher_number`,
`account_code` (or `account` / `account_name`), `debit`, `credit`, and
optional `narration` / `description`. Each voucher number must balance.

Tally XML previews mapped ledger opening balances and balanced vouchers.
Ledger names must match a system or custom account name in the active
business.

### Commit a preview

```http
POST /imports/{import_id}/commit
```

No request body is required. A successful response contains the committed
import and posted journal entry IDs:

```json
{
  "data": {
    "id": 21,
    "status": "committed",
    "result_data": { "journal_entry_ids": [82], "entries_posted": 1 }
  },
  "result": { "journal_entry_ids": [82], "entries_posted": 1 }
}
```

Imports with preview errors, empty previews, or a status other than
`previewed` cannot be committed. A committed import cannot be posted again.

## Common errors

- `401 Unauthorized`: token missing, expired, or invalid.
- `409 Conflict`: authenticated user has no active business.
- `422 Unprocessable Content`: invalid fields, unbalanced journal, invalid
  import preview, or duplicate cash tally. Laravel returns a `message` and an
  `errors` object keyed by field.

