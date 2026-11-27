import { useCallback, useEffect, useState } from 'react';
import CustomerFormDrawer from '../components/CustomerFormDrawer';
import api from '../api/axios';
import { formatMoney, getErrorMessage } from '../utils/format';
import { useFeedback } from '../context/FeedbackContext';
import MessageBanner from '../components/MessageBanner';

const emptyMeta = { current_page: 1, last_page: 1, total: 0 };

export default function CustomersPage() {
  const { confirm } = useFeedback();
  const [customers, setCustomers] = useState([]);
  const [meta, setMeta] = useState(emptyMeta);
  const [summary, setSummary] = useState({ count: 0, outstanding: 0 });
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [drawer, setDrawer] = useState(null);

  const fetchCustomers = useCallback(() => api.get('/customers', {
    params: { search: search || undefined, page, per_page: 15 },
  }), [page, search]);

  const applyCustomerResponse = (response) => {
    setCustomers(response.data.data);
    setMeta(response.data.meta);
    setSummary(response.data.summary);
  };

  const loadCustomers = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      applyCustomerResponse(await fetchCustomers());
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not load customers.'));
    } finally {
      setLoading(false);
    }
  }, [fetchCustomers]);

  useEffect(() => {
    let active = true;
    fetchCustomers()
      .then((response) => {
        if (active) applyCustomerResponse(response);
      })
      .catch((requestError) => {
        if (active) setError(getErrorMessage(requestError, 'Could not load customers.'));
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => { active = false; };
  }, [fetchCustomers]);

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

  const handleSaved = (customer) => {
    setDrawer(null);
    setNotice(`${customer.name} ${drawer?.customer ? 'was updated' : 'was added'} successfully.`);
    setLoading(true);
    loadCustomers();
  };

  const deleteCustomer = async (customer) => {
    if (!await confirm({ title: 'Delete this customer?', message: `${customer.name} will be removed from the active customer list. Past sales will keep their details.`, confirmLabel: 'Delete customer' })) return;

    setNotice('');
    setError('');
    try {
      await api.delete(`/customers/${customer.id}`);
      setNotice(`${customer.name} was deleted.`);
      setLoading(true);
      await loadCustomers();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not delete this customer.'));
    }
  };

  return (
    <section className="page-stack">
      <div className="page-heading">
        <div>
          <span className="eyebrow">YOUR CONTACTS</span>
          <h1>Customers</h1>
          <p>Manage customer details, credit terms and outstanding balances.</p>
        </div>
        <button type="button" className="btn btn-primary" onClick={() => setDrawer({ customer: null })}>
          <i className="bx bx-plus me-1" /> Add customer
        </button>
      </div>

      <MessageBanner message={notice} onDismiss={() => setNotice('')} />
      <MessageBanner message={error} type="error" onDismiss={() => setError('')} />

      <div className="summary-grid summary-grid-two">
        <article className="summary-card">
          <span className="summary-icon summary-icon-violet"><i className="bx bx-group" /></span>
          <div><small>Active customers</small><strong>{summary.count}</strong></div>
        </article>
        <article className="summary-card">
          <span className="summary-icon summary-icon-amber"><i className="bx bx-wallet" /></span>
          <div><small>You'll receive</small><strong>{formatMoney(summary.outstanding)}</strong></div>
        </article>
      </div>

      <section className="data-card">
        <div className="data-card-toolbar">
          <div>
            <h2>Customer list</h2>
            <span className="muted-copy">{meta.total} contacts</span>
          </div>
          <form className="search-form" onSubmit={submitSearch}>
            <label className="sr-only" htmlFor="customer-search">Search customers</label>
            <i className="bx bx-search" />
            <input id="customer-search" value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Search name, mobile or GSTIN" />
            <button type="submit" className="btn btn-outline-secondary btn-sm">Search</button>
          </form>
        </div>

        {loading ? (
          <div className="loading-state"><span className="spinner-border spinner-border-sm text-primary" /> Loading customers…</div>
        ) : customers.length === 0 ? (
          <div className="empty-state">
            <span className="empty-state-icon"><i className="bx bx-group" /></span>
            <h3>{search ? 'No matching customers' : 'No customers yet'}</h3>
            <p>Add the people and businesses you sell to. Credit balances update with each sale.</p>
            {!search && <button type="button" className="btn btn-primary" onClick={() => setDrawer({ customer: null })}>Add your first customer</button>}
          </div>
        ) : (
          <>
            <div className="table-responsive">
              <table className="business-table">
                <thead>
                  <tr>
                    <th>S.No.</th><th>Customer</th>
                    <th>Mobile</th>
                    <th>GSTIN / State</th>
                    <th>Credit terms</th>
                    <th className="text-end">Outstanding</th>
                    <th aria-label="Actions" />
                  </tr>
                </thead>
                <tbody>
                  {customers.map((customer, index) => (
                    <tr key={customer.id}>
                      <td>{(page - 1) * (meta.per_page || 15) + index + 1}</td>
                      <td>
                        <strong>{customer.name}</strong>
                        {customer.email && <small className="table-subtext">{customer.email}</small>}
                      </td>
                      <td>{customer.mobile || '—'}</td>
                      <td>
                        <span>{customer.gstin || 'No GSTIN'}</span>
                        <small className="table-subtext">{customer.state || 'Same as business / not known'}</small>
                      </td>
                      <td>
                        <span>{customer.credit_days} days</span>
                        <small className="table-subtext">{customer.credit_limit === null ? 'No credit limit' : `Limit ${formatMoney(customer.credit_limit)}`}</small>
                      </td>
                      <td className="text-end fw-semibold">{formatMoney(customer.outstanding_balance)}</td>
                      <td>
                        <div className="table-actions">
                          <button type="button" className="icon-button" onClick={() => setDrawer({ customer })} aria-label={`Edit ${customer.name}`} title="Edit customer">
                            <i className="bx bx-edit-alt" />
                          </button>
                          <button type="button" className="icon-button danger-icon" onClick={() => deleteCustomer(customer)} aria-label={`Delete ${customer.name}`} title="Delete customer">
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
        <CustomerFormDrawer
          key={drawer.customer?.id ?? 'new'}
          customer={drawer.customer}
          onClose={() => setDrawer(null)}
          onSaved={handleSaved}
        />
      )}
    </section>
  );
}
