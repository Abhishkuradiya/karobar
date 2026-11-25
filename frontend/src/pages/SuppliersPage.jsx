import { useCallback, useEffect, useState } from 'react';
import SupplierFormDrawer from '../components/SupplierFormDrawer';
import api from '../api/axios';
import { formatMoney, getErrorMessage } from '../utils/format';

const emptyMeta = { current_page: 1, last_page: 1, total: 0 };
const msmeLabels = {
  not_known: 'Not MSME / not known',
  micro: 'Micro',
  small: 'Small',
  medium: 'Medium',
};

export default function SuppliersPage() {
  const [suppliers, setSuppliers] = useState([]);
  const [meta, setMeta] = useState(emptyMeta);
  const [summary, setSummary] = useState({ count: 0, outstanding: 0 });
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [drawer, setDrawer] = useState(null);

  const fetchSuppliers = useCallback(() => api.get('/suppliers', {
    params: { search: search || undefined, page, per_page: 15 },
  }), [page, search]);

  const applySupplierResponse = (response) => {
    setSuppliers(response.data.data);
    setMeta(response.data.meta);
    setSummary(response.data.summary);
  };

  const loadSuppliers = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      applySupplierResponse(await fetchSuppliers());
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not load suppliers.'));
    } finally {
      setLoading(false);
    }
  }, [fetchSuppliers]);

  useEffect(() => {
    let active = true;
    fetchSuppliers()
      .then((response) => {
        if (active) applySupplierResponse(response);
      })
      .catch((requestError) => {
        if (active) setError(getErrorMessage(requestError, 'Could not load suppliers.'));
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => { active = false; };
  }, [fetchSuppliers]);

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

  const handleSaved = async (supplier) => {
    setDrawer(null);
    setNotice(`${supplier.name} ${drawer?.supplier ? 'was updated' : 'was added'} successfully.`);
    await loadSuppliers();
  };

  const deleteSupplier = async (supplier) => {
    if (!window.confirm(`Delete ${supplier.name}? Past purchases will retain the supplier details.`)) return;

    setNotice('');
    setError('');
    try {
      await api.delete(`/suppliers/${supplier.id}`);
      setNotice(`${supplier.name} was deleted.`);
      await loadSuppliers();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not delete this supplier.'));
    }
  };

  return (
    <section className="page-stack">
      <div className="page-heading">
        <div>
          <span className="eyebrow">YOUR SUPPLIER REGISTER</span>
          <h1>Suppliers</h1>
          <p>Manage supplier details, payment terms and balances due.</p>
        </div>
        <button type="button" className="btn btn-primary" onClick={() => setDrawer({ supplier: null })}>
          <i className="bx bx-plus me-1" /> Add supplier
        </button>
      </div>

      {notice && <div className="alert alert-success py-2" role="status">{notice}</div>}
      {error && <div className="alert alert-danger py-2" role="alert">{error}</div>}

      <div className="summary-grid summary-grid-two">
        <article className="summary-card">
          <span className="summary-icon summary-icon-violet"><i className="bx bx-group" /></span>
          <div><small>Active suppliers</small><strong>{summary.count}</strong></div>
        </article>
        <article className="summary-card">
          <span className="summary-icon summary-icon-amber"><i className="bx bx-wallet" /></span>
          <div><small>You&apos;ll pay</small><strong>{formatMoney(summary.outstanding)}</strong></div>
        </article>
      </div>

      <section className="data-card">
        <div className="data-card-toolbar">
          <div>
            <h2>Supplier list</h2>
            <span className="muted-copy">{meta.total} supplier{meta.total === 1 ? '' : 's'}</span>
          </div>
          <form className="search-form" onSubmit={submitSearch}>
            <label className="sr-only" htmlFor="supplier-search">Search suppliers</label>
            <i className="bx bx-search" />
            <input id="supplier-search" value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Search supplier, mobile or GSTIN" />
            <button type="submit" className="btn btn-outline-secondary btn-sm">Search</button>
          </form>
        </div>

        {loading ? (
          <div className="loading-state"><span className="spinner-border spinner-border-sm text-primary" /> Loading suppliers…</div>
        ) : suppliers.length === 0 ? (
          <div className="empty-state">
            <span className="empty-state-icon"><i className="bx bx-group" /></span>
            <h3>{search ? 'No matching suppliers' : 'No suppliers yet'}</h3>
            <p>Add the suppliers you buy from. Their balances update with every purchase and payment.</p>
            {!search && <button type="button" className="btn btn-primary" onClick={() => setDrawer({ supplier: null })}>Add your first supplier</button>}
          </div>
        ) : (
          <>
            <div className="table-responsive">
              <table className="business-table">
                <thead>
                  <tr>
                    <th>Supplier</th>
                    <th>Mobile</th>
                    <th>GSTIN / State</th>
                    <th>MSME (Udyam)</th>
                    <th>Credit terms</th>
                    <th className="text-end">Outstanding</th>
                    <th aria-label="Actions" />
                  </tr>
                </thead>
                <tbody>
                  {suppliers.map((supplier) => (
                    <tr key={supplier.id}>
                      <td>
                        <strong>{supplier.name}</strong>
                        {supplier.email && <small className="table-subtext">{supplier.email}</small>}
                      </td>
                      <td>{supplier.mobile || '—'}</td>
                      <td>
                        <span>{supplier.gstin || 'No GSTIN'}</span>
                        <small className="table-subtext">{supplier.state || 'Same as business / not known'}</small>
                      </td>
                      <td>
                        <span>{msmeLabels[supplier.msme_type] || msmeLabels.not_known}</span>
                        {supplier.udyam_number && <small className="table-subtext">{supplier.udyam_number}</small>}
                      </td>
                      <td>
                        <span>{supplier.credit_days} days</span>
                        <small className="table-subtext">{supplier.purchases_count} purchases</small>
                      </td>
                      <td className="text-end fw-semibold">{formatMoney(supplier.outstanding_balance)}</td>
                      <td>
                        <div className="table-actions">
                          <button type="button" className="icon-button" onClick={() => setDrawer({ supplier })} aria-label={`Edit ${supplier.name}`} title="Edit supplier">
                            <i className="bx bx-edit-alt" />
                          </button>
                          <button type="button" className="icon-button danger-icon" onClick={() => deleteSupplier(supplier)} aria-label={`Delete ${supplier.name}`} title="Delete supplier">
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
        <SupplierFormDrawer
          key={drawer.supplier?.id ?? 'new'}
          supplier={drawer.supplier}
          onClose={() => setDrawer(null)}
          onSaved={handleSaved}
        />
      )}
    </section>
  );
}
