import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import ExpenseFormDrawer from '../components/ExpenseFormDrawer';
import api from '../api/axios';
import MessageBanner from '../components/MessageBanner';
import { formatDate, formatMoney, getErrorMessage } from '../utils/format';
import { getExpenseCategoryLabel } from '../utils/expenseCategories';
import { useFeedback } from '../context/FeedbackContext';

const emptyMeta = { current_page: 1, last_page: 1, total: 0 };
const paymentLabels = {
  cash: 'Cash',
  upi: 'UPI',
  bank: 'Bank',
  card: 'Card',
  unpaid: 'Not paid yet',
};

export default function ExpensesPage() {
  const { confirm } = useFeedback();
  const navigate = useNavigate();
  const [searchParams, setSearchParams] = useSearchParams();
  const queryString = searchParams.toString();
  const scanBillId = searchParams.get('scanBill');
  const expenseId = searchParams.get('expense');
  const [expenses, setExpenses] = useState([]);
  const [meta, setMeta] = useState(emptyMeta);
  const [summary, setSummary] = useState({ count: 0, total: 0, outstanding: 0 });
  const [filters, setFilters] = useState({ fromInput: '', toInput: '', from: '', to: '' });
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [drawer, setDrawer] = useState(null);

  const fetchExpenses = useCallback(() => api.get('/expenses', {
    params: {
      search: search || undefined,
      from: filters.from || undefined,
      to: filters.to || undefined,
      page,
      per_page: 15,
    },
  }), [filters.from, filters.to, page, search]);

  const applyExpenseResponse = (response) => {
    setExpenses(response.data.data);
    setMeta(response.data.meta);
    setSummary(response.data.summary);
  };

  const loadExpenses = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      applyExpenseResponse(await fetchExpenses());
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not load expenses.'));
    } finally {
      setLoading(false);
    }
  }, [fetchExpenses]);

  useEffect(() => {
    let active = true;
    fetchExpenses()
      .then((response) => {
        if (active) applyExpenseResponse(response);
      })
      .catch((requestError) => {
        if (active) setError(getErrorMessage(requestError, 'Could not load expenses.'));
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => { active = false; };
  }, [fetchExpenses]);

  useEffect(() => {
    if (!scanBillId && !expenseId) return undefined;
    let active = true;
    const request = expenseId
      ? api.get(`/expenses/${expenseId}`).then((response) => ({ expense: response.data }))
      : api.get(`/scan-bills/${scanBillId}`).then((response) => ({ scanBill: response.data }));

    request
      .then((record) => {
        if (active) setDrawer(record);
      })
      .catch((requestError) => {
        if (active) setError(getErrorMessage(requestError, 'Could not open this record.'));
      })
      .finally(() => {
        if (active) setSearchParams({}, { replace: true });
      });

    return () => { active = false; };
  }, [expenseId, scanBillId, setSearchParams]);

  const updateFilter = (event) => {
    const { name, value } = event.target;
    setFilters((current) => ({ ...current, [name]: value }));
  };

  const applyFilters = (event) => {
    event.preventDefault();
    setLoading(true);
    setError('');
    setPage(1);
    setFilters((current) => ({ ...current, from: current.fromInput, to: current.toInput }));
  };

  const submitSearch = (event) => {
    event.preventDefault();
    setLoading(true);
    setError('');
    setPage(1);
    setSearch(searchInput.trim());
  };

  const changePage = (nextPage) => {
    setLoading(true);
    setError('');
    setPage(nextPage);
  };

  const handleSaved = async (expense) => {
    const wasEditing = Boolean(drawer?.expense);
    setDrawer(null);
    setNotice(`${expense.expense_number} was ${wasEditing ? 'updated' : 'recorded'} successfully.`);
    await loadExpenses();
    if (queryString) navigate('/expenses', { replace: true });
  };

  const deleteExpense = async (expense) => {
    if (!await confirm({ title: 'Delete this expense?', message: `${expense.expense_number} will be deleted and its journal entry reversed.`, confirmLabel: 'Delete expense' })) return;

    setNotice('');
    setError('');
    try {
      await api.delete(`/expenses/${expense.id}`);
      setNotice(`${expense.expense_number} was deleted and its journal entry reversed.`);
      await loadExpenses();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not delete this expense.'));
    }
  };

  return (
    <section className="page-stack">
      <div className="page-heading">
        <div>
          <span className="eyebrow">EVERYDAY BUSINESS COSTS</span>
          <h1>Expenses</h1>
          <p>Record operating costs and keep GST, payments and payables in sync.</p>
        </div>
        <button type="button" className="btn btn-primary" onClick={() => setDrawer({})}>
          <i className="bx bx-plus me-1" /> New expense
        </button>
      </div>

      <MessageBanner message={notice} onDismiss={() => setNotice('')} />
      <MessageBanner message={error} type="error" onDismiss={() => setError('')} />

      <div className="summary-grid summary-grid-two">
        <article className="summary-card">
          <span className="summary-icon summary-icon-violet"><i className="bx bx-receipt" /></span>
          <div><small>Expenses in view</small><strong>{loading ? '—' : formatMoney(summary.total)}</strong></div>
          <span className="summary-footnote">{loading ? 'Loading…' : `${summary.count} recorded`}</span>
        </article>
        <article className="summary-card">
          <span className="summary-icon summary-icon-amber"><i className="bx bx-time-five" /></span>
          <div><small>Still to pay</small><strong>{loading ? '—' : formatMoney(summary.outstanding)}</strong></div>
          <span className="summary-footnote">Unpaid expenses in view</span>
        </article>
      </div>

      <div className="expense-filter-toolbar">
        <form className="sales-filter-form" onSubmit={applyFilters}>
          <label className="compact-field">
            <span>From</span>
            <input aria-label="From date" type="date" name="fromInput" value={filters.fromInput} onChange={updateFilter} />
          </label>
          <label className="compact-field">
            <span>To</span>
            <input aria-label="To date" type="date" name="toInput" value={filters.toInput} onChange={updateFilter} />
          </label>
          <button type="submit" className="btn btn-outline-secondary btn-sm">Apply</button>
        </form>
        <form className="search-form" onSubmit={submitSearch}>
          <label className="sr-only" htmlFor="expense-search">Search expenses</label>
          <i className="bx bx-search" />
          <input id="expense-search" value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Search category, note or supplier" />
          <button type="submit" className="btn btn-outline-secondary btn-sm">Search</button>
        </form>
      </div>

      <section className="data-card">
        <div className="data-card-toolbar">
          <div><h2>Expense register</h2><span className="muted-copy">{meta.total} recorded {meta.total === 1 ? 'expense' : 'expenses'}</span></div>
          <Link className="text-link" to="/scan-bills"><i className="bx bx-scan" /> Scan a bill</Link>
        </div>
        {loading ? (
          <div className="loading-state"><span className="spinner-border spinner-border-sm text-primary" /> Loading expenses…</div>
        ) : expenses.length === 0 ? (
          <div className="empty-state">
            <span className="empty-state-icon"><i className="bx bx-receipt" /></span>
            <h3>{search || filters.from || filters.to ? 'No matching expenses' : 'No expenses yet'}</h3>
            <p>Rent, electricity, salaries and other day-to-day costs belong here.</p>
            {!search && !filters.from && !filters.to && (
              <button type="button" className="btn btn-primary" onClick={() => setDrawer({})}>Record your first expense</button>
            )}
          </div>
        ) : (
          <>
            <div className="table-responsive">
              <table className="business-table">
                <thead>
                  <tr>
                    <th>S.No.</th><th>Expense</th>
                    <th>Date</th>
                    <th>Supplier / note</th>
                    <th>Paid by</th>
                    <th className="text-end">Total</th>
                    <th className="text-end">Due</th>
                    <th aria-label="Actions" />
                  </tr>
                </thead>
                <tbody>
                  {expenses.map((expense, index) => (
                    <tr key={expense.id}>
                      <td>{(page - 1) * (meta.per_page || 15) + index + 1}</td>
                      <td>
                        <strong>{getExpenseCategoryLabel(expense.category)}</strong>
                        <small className="table-subtext">{expense.expense_number}</small>
                      </td>
                      <td>{formatDate(expense.expense_date)}</td>
                      <td>
                        <span>{expense.supplier?.name || expense.supplier_name_snapshot || 'No supplier'}</span>
                        <small className="table-subtext">{expense.note || expense.scan_bill?.original_name || '—'}</small>
                      </td>
                      <td><span className={`payment-badge payment-${expense.payment_method}`}>{paymentLabels[expense.payment_method]}</span></td>
                      <td className="text-end fw-semibold">
                        {formatMoney(expense.total)}
                        {Number(expense.tax_amount) > 0 && <small className="table-subtext">{formatMoney(expense.tax_amount)} GST</small>}
                      </td>
                      <td className="text-end">
                        {Number(expense.balance_due) > 0
                          ? <span className="due-amount">{formatMoney(expense.balance_due)}</span>
                          : <span className="settled-label">Paid</span>}
                      </td>
                      <td>
                        <div className="table-actions">
                          <button type="button" className="icon-button" onClick={() => setDrawer({ expense })} aria-label={`Edit ${expense.expense_number}`} title="Edit expense">
                            <i className="bx bx-edit-alt" />
                          </button>
                          <button type="button" className="icon-button danger-icon" onClick={() => deleteExpense(expense)} aria-label={`Delete ${expense.expense_number}`} title="Delete expense">
                            <i className="bx bx-trash" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="pagination-bar">
              <span>Page {meta.current_page} of {meta.last_page}</span>
              <div>
                <button type="button" className="btn btn-outline-secondary btn-sm" disabled={page <= 1} onClick={() => changePage(page - 1)}>Previous</button>
                <button type="button" className="btn btn-outline-secondary btn-sm ms-2" disabled={page >= meta.last_page} onClick={() => changePage(page + 1)}>Next</button>
              </div>
            </div>
          </>
        )}
      </section>

      {drawer && (
        <ExpenseFormDrawer
          key={drawer.expense?.id ?? drawer.scanBill?.id ?? 'new'}
          expense={drawer.expense}
          scanBill={drawer.scanBill}
          onClose={() => setDrawer(null)}
          onSaved={handleSaved}
        />
      )}
    </section>
  );
}
