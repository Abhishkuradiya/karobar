export const expenseCategories = [
  { value: 'rent', label: 'Rent' },
  { value: 'electricity', label: 'Electricity' },
  { value: 'salary', label: 'Salary' },
  { value: 'fuel', label: 'Fuel' },
  { value: 'transport', label: 'Transport' },
  { value: 'internet', label: 'Internet' },
  { value: 'office_expenses', label: 'Office expenses' },
  { value: 'repairs', label: 'Repairs' },
  { value: 'advertising', label: 'Advertising' },
  { value: 'travel', label: 'Travel' },
  { value: 'bank_charges', label: 'Bank charges' },
  { value: 'miscellaneous', label: 'Miscellaneous' },
  { value: 'insurance', label: 'Insurance' },
  { value: 'telephone', label: 'Telephone' },
  { value: 'maintenance', label: 'Maintenance' },
  { value: 'professional_fees', label: 'Professional fees' },
  { value: 'packaging', label: 'Packaging' },
  { value: 'taxes_licenses', label: 'Taxes & licences' },
  { value: 'other', label: 'Other' },
];

export const quickExpenseCategories = expenseCategories.slice(0, 12);
export const moreExpenseCategories = expenseCategories.slice(12);

export function getExpenseCategoryLabel(category) {
  return expenseCategories.find((item) => item.value === category)?.label ?? 'Other';
}
