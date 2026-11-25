<?php

namespace App\Support;

use App\Models\ChartAccount;

class ChartAccountCatalog
{
    private const SYSTEM_ACCOUNTS = [
        ['1000', 'cash', 'Cash', 'assets', 'Cash-in-Hand', 'debit'],
        ['1100', 'bank', 'Bank', 'assets', 'Bank Accounts', 'debit'],
        ['1110', 'upi_clearing', 'UPI Clearing', 'assets', 'Current Assets', 'debit'],
        ['1120', 'card_clearing', 'Card Clearing', 'assets', 'Current Assets', 'debit'],
        ['1200', 'accounts_receivable', 'Accounts Receivable', 'assets', 'Sundry Debtors', 'debit'],
        ['1300', 'inventory', 'Inventory', 'assets', 'Stock-in-Hand', 'debit'],
        ['1400', 'input_gst', 'Input GST', 'assets', 'Input GST', 'debit'],
        ['1430', 'itc_reversed', 'ITC Reversed (re-claimable)', 'assets', 'Input GST', 'debit'],
        ['1450', 'cash_difference', 'Cash Difference (Suspense)', 'assets', 'Current Assets', 'debit'],
        ['1460', 'inter_branch_transfers', 'Inter-branch Transfers', 'assets', 'Current Assets', 'debit'],
        ['1500', 'fixed_assets', 'Fixed Assets', 'assets', 'Fixed Assets', 'debit'],
        ['2000', 'accounts_payable', 'Accounts Payable', 'liabilities', 'Sundry Creditors', 'credit'],
        ['2100', 'gst_payable', 'Output GST', 'liabilities', 'Duties & Taxes', 'credit'],
        ['2110', 'output_sgst', 'Output SGST', 'liabilities', 'Duties & Taxes', 'credit'],
        ['2120', 'output_igst', 'Output IGST', 'liabilities', 'Duties & Taxes', 'credit'],
        ['2130', 'reverse_charge_gst_payable', 'Reverse-charge GST Payable', 'liabilities', 'Duties & Taxes', 'credit'],
        ['2200', 'tds_payable', 'TDS Payable', 'liabilities', 'Duties & Taxes', 'credit'],
        ['2500', 'loans', 'Loans', 'liabilities', 'Loans (Liability)', 'credit'],
        ['3000', 'owners_capital', "Owner's Capital", 'equity', 'Capital Account', 'credit'],
        ['3050', 'drawings', 'Drawings', 'equity', 'Capital Account', 'debit'],
        ['3100', 'opening_balance_equity', 'Opening Balance Equity', 'equity', 'Capital Account', 'credit'],
        ['3200', 'retained_earnings', 'Retained Earnings', 'equity', 'Reserves & Surplus', 'credit'],
        ['4000', 'sales_revenue', 'Sales', 'income', 'Sales Accounts', 'credit'],
        ['4010', 'sales_returns', 'Sales Returns', 'income', 'Sales Accounts', 'debit'],
        ['4100', 'other_income', 'Other Income', 'income', 'Indirect Income', 'credit'],
        ['5000', 'purchase_expense', 'Purchases / COGS', 'expenses', 'Direct Expenses', 'debit'],
        ['5010', 'purchase_returns', 'Purchase Returns', 'expenses', 'Direct Expenses', 'credit'],
        ['5020', 'cost_of_goods_sold', 'Cost of Goods Sold', 'expenses', 'Direct Expenses', 'debit'],
        ['5030', 'purchase_return_adjustment', 'Purchase Return Adjustment', 'expenses', 'Direct Expenses', 'debit'],
        ['5100', 'expense_rent', 'Rent', 'expenses', 'Indirect Expenses', 'debit'],
        ['5110', 'expense_electricity', 'Electricity', 'expenses', 'Indirect Expenses', 'debit'],
        ['5120', 'expense_salary', 'Salary', 'expenses', 'Indirect Expenses', 'debit'],
        ['5130', 'expense_advertising', 'Advertising', 'expenses', 'Indirect Expenses', 'debit'],
        ['5140', 'expense_fuel', 'Fuel', 'expenses', 'Indirect Expenses', 'debit'],
        ['5150', 'expense_internet', 'Internet', 'expenses', 'Indirect Expenses', 'debit'],
        ['5160', 'expense_transport', 'Transport', 'expenses', 'Indirect Expenses', 'debit'],
        ['5170', 'expense_repairs', 'Repairs', 'expenses', 'Indirect Expenses', 'debit'],
        ['5180', 'expense_office_expenses', 'Office Expenses', 'expenses', 'Indirect Expenses', 'debit'],
        ['5190', 'expense_travel', 'Travel', 'expenses', 'Indirect Expenses', 'debit'],
        ['5200', 'expense_bank_charges', 'Bank Charges', 'expenses', 'Indirect Expenses', 'debit'],
        ['5210', 'expense_professional_fees', 'Legal & Professional Fees', 'expenses', 'Indirect Expenses', 'debit'],
        ['5220', 'expense_staff_welfare', 'Staff Welfare', 'expenses', 'Indirect Expenses', 'debit'],
        ['5230', 'expense_telephone', 'Telephone', 'expenses', 'Indirect Expenses', 'debit'],
        ['5240', 'expense_insurance', 'Insurance', 'expenses', 'Indirect Expenses', 'debit'],
        ['5250', 'expense_maintenance', 'Maintenance', 'expenses', 'Indirect Expenses', 'debit'],
        ['5260', 'expense_packaging', 'Packaging', 'expenses', 'Direct Expenses', 'debit'],
        ['5270', 'expense_taxes_licenses', 'Taxes & Licences', 'expenses', 'Indirect Expenses', 'debit'],
        ['5280', 'expense_other', 'Other Expense', 'expenses', 'Indirect Expenses', 'debit'],
        ['5300', 'expense_depreciation', 'Depreciation', 'expenses', 'Indirect Expenses', 'debit'],
        ['5310', 'expense_interest_late_fee_gst', 'Interest & Late Fee on GST', 'expenses', 'Indirect Expenses', 'debit'],
        ['5900', 'expense_miscellaneous', 'Miscellaneous', 'expenses', 'Indirect Expenses', 'debit'],
        ['5950', 'round_off', 'Round Off', 'expenses', 'Indirect Expenses', 'debit'],
        ['5960', 'inventory_adjustment_gain', 'Inventory Adjustment Gain', 'income', 'Indirect Income', 'credit'],
        ['5970', 'inventory_adjustment_loss', 'Inventory Adjustment Loss', 'expenses', 'Indirect Expenses', 'debit'],
    ];

    public static function systemAccounts(): array
    {
        return array_map(static fn (array $account): array => [
            'display_code' => $account[0],
            'account_code' => $account[1],
            'name' => $account[2],
            'account_type' => $account[3],
            'group_name' => $account[4],
            'normal_balance' => $account[5],
            'is_system' => true,
        ], self::SYSTEM_ACCOUNTS);
    }

    public static function forBusiness(int $businessId): array
    {
        $custom = ChartAccount::query()
            ->where('business_id', $businessId)
            ->get()
            ->map(static fn (ChartAccount $account): array => [
                'id' => $account->id,
                'display_code' => $account->display_code,
                'account_code' => $account->account_code,
                'name' => $account->name,
                'account_type' => $account->account_type,
                'group_name' => $account->group_name,
                'normal_balance' => in_array($account->account_type, ['assets', 'expenses'], true) ? 'debit' : 'credit',
                'is_system' => false,
            ])->all();

        $accounts = [...self::systemAccounts(), ...$custom];
        usort($accounts, static fn (array $left, array $right): int => strnatcasecmp($left['display_code'], $right['display_code']));

        return $accounts;
    }

    public static function resolve(string $identifier, int $businessId): ?array
    {
        $needle = trim($identifier);
        foreach (self::systemAccounts() as $account) {
            if ($account['account_code'] === $needle
                || $account['display_code'] === $needle
                || strcasecmp($account['name'], $needle) === 0) {
                return $account;
            }
        }

        $custom = ChartAccount::query()
            ->where('business_id', $businessId)
            ->where(function ($query) use ($needle): void {
                $query->where('account_code', $needle)
                    ->orWhere('display_code', $needle)
                    ->orWhereRaw('LOWER(name) = ?', [mb_strtolower($needle)]);
            })
            ->first();

        if (! $custom) {
            return null;
        }

        return [
            'id' => $custom->id,
            'display_code' => $custom->display_code,
            'account_code' => $custom->account_code,
            'name' => $custom->name,
            'account_type' => $custom->account_type,
            'group_name' => $custom->group_name,
            'normal_balance' => in_array($custom->account_type, ['assets', 'expenses'], true) ? 'debit' : 'credit',
            'is_system' => false,
        ];
    }
}
