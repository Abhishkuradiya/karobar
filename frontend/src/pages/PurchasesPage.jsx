import { useCallback, useEffect, useState } from 'react';
import { Link, useLocation } from 'react-router-dom';
import api from '../api/axios';
import { formatDate, formatMoney, getErrorMessage } from '../utils/format';
import PaginationControls from '../components/PaginationControls';

const emptyMeta = { current_page: 1, last_page: 1, total: 0 };
const paymentLabels = {
  cash: 'Cash',
  upi: 'UPI',
  card: 'Card',
  bank: 'Bank',
  credit: 'Credit',
};

export default function PurchasesPage() {
  const location = useLocation();
  const [purchases, setPurchases] = useState([]);
  const [meta, setMeta] = useState(emptyMeta);
  const [filters, setFilters] = useState({ searchInput: '', search: '', fromInput: '', toInput: '', from: '', to: '' });
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState(() => location.state?.notice || '');

  const fetchPurchases = useCallback(() => api.get('/purchases', {
    params: {
      from: filters.from || undefined,
      to: filters.to || undefined,
      search: filters.search || undefined,
      page,
      per_page: 15,
    },
  }), [filters.from, filters.to, page]);

  const applyPurchaseResponse = (response) => {
    setPurchases(response.data.data);
    setMeta(response.data.meta);
  };

  const loadPurchases = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      applyPurchaseResponse(await fetchPurchases());
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not load purchases.'));
    } finally {
      setLoading(false);
    }
  }, [fetchPurchases]);

  useEffect(() => {
    let active = true;
    fetchPurchases()
      .then((response) => {
        if (active) applyPurchaseResponse(response);
      })
      .catch((requestError) => {
        if (active) setError(getErrorMessage(requestError, 'Could not load purchases.'));
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => { active = false; };
  }, [fetchPurchases]);

  const updateFilter = (event) => {
    const { name, value } = event.target;
    setFilters((current) => ({ ...current, [name]: value }));
  };

  const applyFilters = (event) => {
    event.preventDefault();
    setLoading(true);
    setError('');
    setPage(1);
    setFilters((current) => ({
      ...current,
      from: current.fromInput,
      to: current.toInput,
      search: current.searchInput.trim(),
    }));
  };

  const changePage = (nextPage) => {
    setLoading(true);
    setError('');
    setPage(nextPage);
  };

  const voidPurchase = async (purchase) => {
    if (!window.confirm(`Void purchase ${purchase.purchase_number}? A reversing journal entry will be recorded.`)) return;

    setNotice('');
    setError('');
    try {
      await api.delete(`/purchases/${purchase.id}`);
      setNotice(`${purchase.purchase_number} was voided and its journal entry reversed.`);
      await loadPurchases();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not void this purchase.'));
    }
  };

  return (
    <section className="page-stack">
      <div className="page-heading">
        <div>
          <span className="eyebrow">SUPPLIER BILLS & INPUT TAX</span>
          <h1>Purchases</h1>
          <p>Record supplier bills and keep GST, payments and payables in sync.</p>
        </div>
        <Link className="btn btn-primary" to="/purchases/new"><i className="bx bx-plus me-1" /> New purchase</Link>
      </div>

      {notice && <div className="alert alert-success py-2" role="status">{notice}</div>}
      {error && <div className="alert alert-danger py-2" role="alert">{error}</div>}

      <div className="purchase-filter-row">
        <form className="sales-filter-form" onSubmit={applyFilters}>
            <label className="search-box purchase-search-box">
              <i className="bx bx-search" />
              <input aria-label="Search purchases" name="searchInput" value={filters.searchInput} onChange={updateFilter} placeholder="Search purchase, supplier or item" />
            </label>
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
        <span className="muted-copy">{meta.total} recorded {meta.total === 1 ? 'purchase' : 'purchases'}</span>
      </div>

      <section className="data-card">
        {loading ? (
          <div className="loading-state"><span className="spinner-border spinner-border-sm text-primary" /> Loading purchases…</div>
        ) : purchases.length === 0 ? (
          <div className="empty-state">
            <span className="empty-state-icon"><i className="bx bx-file" /></span>
            <h3>{filters.search || filters.from || filters.to ? 'No purchases match these filters' : 'No purchases yet'}</h3>
            <p>Record supplier bills — input GST and supplier balances update automatically.</p>
            {!filters.search && !filters.from && !filters.to && <Link className="btn btn-primary" to="/purchases/new">Record your first purchase</Link>}
          </div>
        ) : (
          <>
            <div className="table-responsive">
              <table className="business-table">
                <thead>
                  <tr>
                    <th>Purchase</th>
                    <th>Date</th>
                    <th>Supplier / bill</th>
                    <th>Paid by</th>
                    <th className="text-end">Total</th>
                    <th className="text-end">Due</th>
                    <th aria-label="Actions" />
                  </tr>
                </thead>
                <tbody>
                  {purchases.map((purchase) => (
                    <tr key={purchase.id}>
                      <td>
                        <Link className="table-primary-link" to={`/purchases/${purchase.id}/edit`}>{purchase.purchase_number}</Link>
                        <small className="table-subtext">{purchase.items.length} {purchase.items.length === 1 ? 'item' : 'items'}</small>
                      </td>
                      <td>{formatDate(purchase.purchase_date)}</td>
                      <td>
                        <span>{purchase.supplier?.name || purchase.supplier_name_snapshot}</span>
                        <small className="table-subtext">{purchase.supplier_bill_number || 'No supplier bill number'}</small>
                      </td>
                      <td><span className={`payment-badge payment-${purchase.payment_method}`}>{paymentLabels[purchase.payment_method]}</span></td>
                      <td className="text-end fw-semibold">{formatMoney(purchase.total)}</td>
                      <td className="text-end">
                        {Number(purchase.balance_due) > 0
                          ? <span className="due-amount">{formatMoney(purchase.balance_due)}</span>
                          : <span className="settled-label">Paid</span>}
                      </td>
                      <td>
                        <div className="table-actions">
                          <Link className="icon-button" to={`/purchases/${purchase.id}/edit`} aria-label={`Edit ${purchase.purchase_number}`} title="Edit purchase">
                            <i className="bx bx-edit-alt" />
                          </Link>
                          <button type="button" className="icon-button danger-icon" onClick={() => voidPurchase(purchase)} aria-label={`Void ${purchase.purchase_number}`} title="Void purchase">
                            <i className="bx bx-trash" />
                          </button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <PaginationControls page={page} meta={meta} onChange={changePage} />
          </>
        )}
      </section>
    </section>
  );
}
