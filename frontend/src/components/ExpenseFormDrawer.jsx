import { useEffect, useState } from 'react';
import api from '../api/axios';
import { getErrorMessage, formatMoney } from '../utils/format';
import { expenseCategories, quickExpenseCategories, moreExpenseCategories } from '../utils/expenseCategories';
import MessageBanner from './MessageBanner';

const paymentOptions = [
  { value: 'cash', label: 'Cash', icon: 'bx-money' },
  { value: 'upi', label: 'UPI', icon: 'bx-mobile-alt' },
  { value: 'bank', label: 'Bank', icon: 'bx-building-house' },
  { value: 'card', label: 'Card', icon: 'bx-credit-card' },
  { value: 'unpaid', label: 'Not paid yet', icon: 'bx-time-five' },
];

const today = () => {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

function createForm(expense, scanBill) {
  return {
    category: expense?.category ?? 'rent',
    amount: expense?.amount ?? '',
    gst_rate: expense?.gst_rate ?? '0',
    claim_input_tax_credit: expense?.claim_input_tax_credit ?? false,
    payment_method: expense?.payment_method ?? 'cash',
    bank_account_id: expense?.bank_account_id ? String(expense.bank_account_id) : '',
    expense_date: expense?.expense_date ?? today(),
    supplier_id: expense?.supplier_id ?? '',
    scan_bill_id: expense?.scan_bill_id ?? scanBill?.id ?? '',
    note: expense?.note ?? '',
  };
}

export default function ExpenseFormDrawer({ expense, scanBill, onClose, onSaved }) {
  const [form, setForm] = useState(() => createForm(expense, scanBill));
  const [suppliers, setSuppliers] = useState([]);
  const [bankAccounts, setBankAccounts] = useState([]);
  const [supplierError, setSupplierError] = useState('');
  const [bankError, setBankError] = useState('');
  const [loadingSuppliers, setLoadingSuppliers] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    let active = true;
    api.get('/suppliers', { params: { per_page: 50 } })
      .then((response) => {
        if (active) setSuppliers(response.data.data);
      })
      .catch((requestError) => {
        if (active) setSupplierError(getErrorMessage(requestError, 'Could not load suppliers.'));
      })
      .finally(() => {
        if (active) setLoadingSuppliers(false);
      });
    api.get('/banking/accounts')
      .then((response) => {
        if (active) setBankAccounts(response.data.data);
      })
      .catch((requestError) => {
        if (active) setBankError(getErrorMessage(requestError, 'Could not load bank accounts.'));
      });

    return () => { active = false; };
  }, []);

  const updateField = (event) => {
    const { name, value } = event.target;
    setForm((current) => ({
      ...current,
      [name]: name === 'claim_input_tax_credit' ? event.target.checked : value,
    }));
  };

  const setCategory = (category) => setForm((current) => ({ ...current, category }));

  const submit = async (event) => {
    event.preventDefault();
    setError('');
    setSaving(true);
    const payload = {
      ...form,
      amount: Number(form.amount),
      gst_rate: Number(form.gst_rate),
      supplier_id: form.supplier_id ? Number(form.supplier_id) : null,
      bank_account_id: form.bank_account_id ? Number(form.bank_account_id) : null,
      scan_bill_id: form.scan_bill_id ? Number(form.scan_bill_id) : null,
      claim_input_tax_credit: Boolean(form.claim_input_tax_credit),
      note: form.note.trim() || null,
    };

    try {
      const response = expense
        ? await api.put(`/expenses/${expense.id}`, payload)
        : await api.post('/expenses', payload);
      onSaved(response.data);
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not save this expense.'));
    } finally {
      setSaving(false);
    }
  };

  const taxRate = Number(form.gst_rate) || 0;
  const amount = Number(form.amount) || 0;
  const taxAmount = Math.round(amount * taxRate) / 100;
  const total = amount + taxAmount;
  const isQuickCategory = quickExpenseCategories.some((category) => category.value === form.category);

  return (
    <div className="drawer-backdrop expense-drawer-backdrop" onMouseDown={(event) => {
      if (event.target === event.currentTarget && !saving) onClose();
    }}>
      <aside className="expense-drawer" role="dialog" aria-modal="true" aria-labelledby="expense-drawer-title">
        <form className="expense-drawer-form" onSubmit={submit}>
          <div className="expense-drawer-heading">
            <div className="expense-heading-icon"><i className={`bx ${expense ? 'bx-edit-alt' : 'bx-receipt'}`} /></div>
            <div className="expense-heading-copy">
              <span className="eyebrow">{expense ? 'UPDATE YOUR BOOKS' : 'RECORD A BUSINESS COST'}</span>
              <h2 id="expense-drawer-title">{expense ? 'Edit expense' : 'New expense'}</h2>
              <p>Choose a category, enter the amount and payment details.</p>
            </div>
            <button type="button" className="icon-button" onClick={onClose} aria-label="Close expense form" disabled={saving}>
              <i className="bx bx-x" />
            </button>
          </div>

          <div className="expense-drawer-content">
            <MessageBanner message={error} type="error" onDismiss={() => setError('')} />
            <MessageBanner message={supplierError ? `${supplierError} You can still save this expense without a supplier.` : ''} type="warning" onDismiss={() => setSupplierError('')} />
            <MessageBanner message={bankError} type="warning" onDismiss={() => setBankError('')} />

            {scanBill && (
              <div className="expense-attachment-note">
                <i className="bx bx-paperclip" />
                <span><strong>Bill attached</strong><small>{scanBill.original_name}</small></span>
              </div>
            )}

            <div className="field-label">
              <span>Category <span className="required-mark">*</span></span>
              <div className="expense-category-options" role="group" aria-label="Expense category">
                {quickExpenseCategories.map((category) => (
                  <button
                    type="button"
                    key={category.value}
                    className={`expense-category-chip${form.category === category.value ? ' is-selected' : ''}`}
                    aria-pressed={form.category === category.value}
                    onClick={() => setCategory(category.value)}
                  >
                    {category.label}
                  </button>
                ))}
              </div>
              <select
                className="expense-more-category"
                value={isQuickCategory ? '' : form.category}
                onChange={(event) => event.target.value && setCategory(event.target.value)}
                aria-label="More expense categories"
              >
                <option value="">More categories…</option>
                {moreExpenseCategories.map((category) => <option key={category.value} value={category.value}>{category.label}</option>)}
              </select>
            </div>

            <div className="expense-form-grid">
              <label className="field-label">
                <span>Amount (₹) <span className="required-mark">*</span></span>
                <input
                  autoFocus
                  name="amount"
                  type="number"
                  min="0.01"
                  max="999999999999.99"
                  step="0.01"
                  value={form.amount}
                  onChange={updateField}
                  required
                  placeholder="0.00"
                />
              </label>
              <label className="field-label">
                GST
                <select name="gst_rate" value={form.gst_rate} onChange={updateField}>
                  {[0, 5, 12, 18, 28].map((rate) => <option key={rate} value={rate}>{rate}%</option>)}
                </select>
              </label>
            </div>

            {taxRate > 0 && (
              <div className="expense-tax-credit">
                <label>
                  <input
                    type="checkbox"
                    name="claim_input_tax_credit"
                    checked={form.claim_input_tax_credit}
                    onChange={updateField}
                  />
                  <span>Claim input tax credit</span>
                </label>
                <small>Only claim GST credit when this business expense is eligible.</small>
              </div>
            )}

            <div className="expense-total-preview" aria-live="polite">
              <span>Expense total <small>{taxRate > 0 ? `Includes ${formatMoney(taxAmount)} GST` : 'No GST added'}</small></span>
              <strong>{formatMoney(total)}</strong>
            </div>

            <fieldset className="expense-payment-fieldset">
              <legend>Paid by</legend>
              <div className="expense-payment-options">
                {paymentOptions.map((option) => (
                  <button
                    type="button"
                    key={option.value}
                    className={`expense-payment-chip${form.payment_method === option.value ? ' is-selected' : ''}`}
                    aria-pressed={form.payment_method === option.value}
                    onClick={() => setForm((current) => ({ ...current, payment_method: option.value }))}
                  >
                    <i className={`bx ${option.icon}`} /> {option.label}
                  </button>
                ))}
              </div>
              {form.payment_method === 'unpaid' && <small className="expense-payment-hint">The full amount will be recorded as payable.</small>}
              {form.payment_method === 'bank' && <label className="field-label bank-account-select mt-3">Bank account
                <select required name="bank_account_id" value={form.bank_account_id} onChange={updateField}>
                  <option value="">Select a bank account</option>
                  {bankAccounts.map((account) => <option key={account.id} value={account.id}>{account.name} · {account.bank_name}{account.account_last4 ? ` ·••${account.account_last4}` : ''}</option>)}
                </select>
                {bankAccounts.length === 0 && <small className="field-hint">Add a bank account in Banking before selecting bank payment.</small>}
              </label>}
            </fieldset>

            <div className="expense-form-grid">
              <label className="field-label">
                <span>Date <span className="required-mark">*</span></span>
                <input type="date" name="expense_date" value={form.expense_date} onChange={updateField} required />
              </label>
              <label className="field-label">
                Supplier
                <select name="supplier_id" value={form.supplier_id} onChange={updateField} disabled={loadingSuppliers}>
                  <option value="">{loadingSuppliers ? 'Loading suppliers…' : 'No supplier'}</option>
                  {suppliers.map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}</option>)}
                </select>
              </label>
            </div>

            <label className="field-label">
              Note
              <input name="note" value={form.note} onChange={updateField} maxLength="2000" placeholder="e.g. September shop rent" />
            </label>
          </div>

          <div className="expense-drawer-footer">
            <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={saving}>Cancel</button>
            <button type="submit" className="btn btn-primary" disabled={saving || !expenseCategories.some((category) => category.value === form.category) || (form.payment_method === 'bank' && !form.bank_account_id)}>
              {saving ? 'Saving…' : expense ? 'Save changes' : 'Save expense'}
            </button>
          </div>
        </form>
      </aside>
    </div>
  );
}
