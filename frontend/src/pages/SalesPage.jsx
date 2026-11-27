import { useCallback, useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import api from '../api/axios';
import MessageBanner from '../components/MessageBanner';
import { formatDate, formatMoney, getErrorMessage } from '../utils/format';
import { useFeedback } from '../context/FeedbackContext';

const paymentLabels = {
  cash: 'Cash',
  upi: 'UPI',
  card: 'Card',
  bank: 'Bank',
  credit: 'Credit',
};

const emptyMeta = { current_page: 1, last_page: 1, total: 0 };

export default function SalesPage() {
  const { confirm } = useFeedback();
  const location = useLocation();
  const [sales, setSales] = useState([]);
  const [meta, setMeta] = useState(emptyMeta);
  const [summary, setSummary] = useState({ count: 0, total: 0, outstanding: 0 });
  const [filters, setFilters] = useState({ searchInput: '', search: '', from: '', to: '' });
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState(() => location.state?.notice || '');

  const fetchSales = useCallback(() => api.get('/sales', {
    params: {
      search: filters.search || undefined,
      from: filters.from || undefined,
      to: filters.to || undefined,
      page,
      per_page: 15,
    },
  }), [filters.from, filters.search, filters.to, page]);

  const applySalesResponse = (response) => {
    setSales(response.data.data);
    setMeta(response.data.meta);
    setSummary(response.data.summary);
  };

  const loadSales = useCallback(async () => {
    try {
      applySalesResponse(await fetchSales());
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not load sales.'));
    } finally {
      setLoading(false);
    }
  }, [fetchSales]);

  useEffect(() => {
    let active = true;
    fetchSales()
      .then((response) => {
        if (active) applySalesResponse(response);
      })
      .catch((requestError) => {
        if (active) setError(getErrorMessage(requestError, 'Could not load sales.'));
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => { active = false; };
  }, [fetchSales]);

  const updateFilter = (event) => {
    const { name, value } = event.target;
    setFilters((current) => ({ ...current, [name]: value }));
  };

  const applyFilters = (event) => {
    event.preventDefault();
    setLoading(true);
    setError('');
    setPage(1);
    setFilters((current) => ({ ...current, search: current.searchInput.trim() }));
  };

  const changePage = (nextPage) => {
    setLoading(true);
    setError('');
    setPage(nextPage);
  };

  const voidSale = async (sale) => {
    if (!await confirm({ title: 'Void this sale?', message: `${sale.invoice_number} will be reversed with a journal entry.`, confirmLabel: 'Void sale' })) return;
    setError('');
    setNotice('');
    try {
      await api.delete(`/sales/${sale.id}`);
      setNotice(`${sale.invoice_number} was voided and its journal entry reversed.`);
      setLoading(true);
      await loadSales();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not void this sale.'));
    }
  };

  return (
    <section className="page-stack">
      <div className="page-heading">
        <div>
          <span className="eyebrow">REVENUE & RECEIVABLES</span>
          <h1>Sales</h1>
          <p>Record sales and keep receipts, GST and customer balances in sync.</p>
        </div>
        <Link className="btn btn-primary" to="/sales/new"><i className="bx bx-plus me-1" /> New sale</Link>
      </div>

      <MessageBanner message={notice} onDismiss={() => setNotice('')} />
      <MessageBanner message={error} type="error" onDismiss={() => setError('')} />

      <div className="summary-grid">
        <article className="summary-card">
          <span className="summary-icon summary-icon-violet"><i className="bx bx-receipt" /></span>
          <div><small>Sales in this view</small><strong>{summary.count}</strong></div>
        </article>
        <article className="summary-card">
          <span className="summary-icon summary-icon-green"><i className="bx bx-trending-up" /></span>
          <div><small>Sales total</small><strong>{formatMoney(summary.total)}</strong></div>
        </article>
        <article className="summary-card">
          <span className="summary-icon summary-icon-amber"><i className="bx bx-time-five" /></span>
          <div><small>Still to receive</small><strong>{formatMoney(summary.outstanding)}</strong></div>
        </article>
      </div>

      <section className="data-card">
        <div className="data-card-toolbar sales-toolbar">
          <div>
            <h2>Sales register</h2>
            <span className="muted-copy">{meta.total} recorded {meta.total === 1 ? 'sale' : 'sales'}</span>
          </div>
          <form className="sales-filter-form" onSubmit={applyFilters}>
            <label className="compact-field">
              <span>From</span>
              <input aria-label="From date" type="date" name="from" value={filters.from} onChange={updateFilter} />
            </label>
            <label className="compact-field">
              <span>To</span>
              <input aria-label="To date" type="date" name="to" value={filters.to} onChange={updateFilter} />
            </label>
            <label className="search-box">
              <i className="bx bx-search" />
              <input aria-label="Search sales" name="searchInput" value={filters.searchInput} onChange={updateFilter} placeholder="Invoice, customer, item" />
            </label>
            <button type="submit" className="btn btn-outline-secondary btn-sm">Apply</button>
          </form>
        </div>

        {loading ? (
          <div className="loading-state"><span className="spinner-border spinner-border-sm text-primary" /> Loading sales…</div>
        ) : sales.length === 0 ? (
          <div className="empty-state">
            <span className="empty-state-icon"><i className="bx bx-file" /></span>
            <h3>{filters.search || filters.from || filters.to ? 'No sales match these filters' : 'No sales yet'}</h3>
            <p>Record cash, UPI or credit sales. GST and the books update automatically.</p>
            {!filters.search && !filters.from && !filters.to && <Link className="btn btn-primary" to="/sales/new">Record your first sale</Link>}
          </div>
        ) : (
          <>
            <div className="table-responsive">
              <table className="business-table">
                <thead>
                  <tr>
                    <th>S.No.</th><th>Sale</th>
                    <th>Date</th>
                    <th>Customer</th>
                    <th>Payment</th>
                    <th className="text-end">Total</th>
                    <th className="text-end">Due</th>
                    <th aria-label="Actions" />
                  </tr>
                </thead>
                <tbody>
                  {sales.map((sale, index) => (
                    <tr key={sale.id}>
                      <td>{(page - 1) * (meta.per_page || 15) + index + 1}</td>
                      <td>
                        <strong>{sale.invoice_number}</strong>
                        <small className="table-subtext">{sale.items.length} {sale.items.length === 1 ? 'item' : 'items'}</small>
                      </td>
                      <td>{formatDate(sale.sale_date)}</td>
                      <td>{sale.customer?.name || sale.customer_name_snapshot}</td>
                      <td><span className={`payment-badge payment-${sale.payment_method}`}>{paymentLabels[sale.payment_method]}</span></td>
                      <td className="text-end fw-semibold">{formatMoney(sale.total)}</td>
                      <td className="text-end">{Number(sale.balance_due) > 0 ? <span className="due-amount">{formatMoney(sale.balance_due)}</span> : <span className="settled-label">Paid</span>}</td>
                      <td>
                        <div className="table-actions">
                          <Link className="icon-button" to={`/sales/${sale.id}/edit`} aria-label={`Edit ${sale.invoice_number}`} title="Edit sale">
                            <i className="bx bx-edit-alt" />
                          </Link>
                          <button type="button" className="icon-button danger-icon" onClick={() => voidSale(sale)} aria-label={`Void ${sale.invoice_number}`} title="Void sale">
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
    </section>
  );
}
