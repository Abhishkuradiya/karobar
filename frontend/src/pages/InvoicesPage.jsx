import { useCallback, useEffect, useMemo, useState } from 'react';
import api from '../api/axios';
import { formatDate, formatMoney, getErrorMessage } from '../utils/format';
import './inventory-banking-invoices.css';

const today = () => new Date().toISOString().slice(0, 10);
const emptyFilters = { search: '', from: '', to: '', status: 'all' };

export default function InvoicesPage() {
  const [tab, setTab] = useState('invoices');
  const [filters, setFilters] = useState(emptyFilters);
  const [invoices, setInvoices] = useState([]);
  const [notes, setNotes] = useState([]);
  const [summary, setSummary] = useState({ count: 0, total: 0, outstanding: 0 });
  const [selectedInvoice, setSelectedInvoice] = useState(null);
  const [noteModal, setNoteModal] = useState(null);
  const [sources, setSources] = useState([]);
  const [banks, setBanks] = useState([]);
  const [sourceId, setSourceId] = useState('');
  const [noteSource, setNoteSource] = useState(null);
  const [quantities, setQuantities] = useState({});
  const [settlementMethod, setSettlementMethod] = useState('cash');
  const [bankAccountId, setBankAccountId] = useState('');
  const [noteDate, setNoteDate] = useState(today);
  const [reason, setReason] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const loadInvoices = useCallback(async () => {
    const response = await api.get('/invoices', {
      params: {
        search: filters.search || undefined,
        from: filters.from || undefined,
        to: filters.to || undefined,
        status: filters.status,
        per_page: 100,
      },
    });
    setInvoices(response.data.data);
    setSummary(response.data.summary);
  }, [filters]);

  const loadNotes = useCallback(async () => {
    const noteType = tab === 'credit-notes' ? 'credit-notes' : 'debit-notes';
    const response = await api.get(`/${noteType}`, { params: { from: filters.from || undefined, to: filters.to || undefined } });
    setNotes(response.data.data);
    setSummary(response.data.summary);
  }, [filters.from, filters.to, tab]);

  useEffect(() => {
    let active = true;
    Promise.resolve()
      .then(() => (tab === 'invoices' ? loadInvoices() : loadNotes()))
      .catch((requestError) => { if (active) setError(getErrorMessage(requestError, 'Could not load invoices and notes.')); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [loadInvoices, loadNotes, tab]);

  const openInvoice = async (invoice) => {
    setError('');
    try {
      const response = await api.get(`/invoices/${invoice.id}`);
      setSelectedInvoice(response.data);
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not open this invoice.'));
    }
  };

  const startReturnNote = async (type) => {
    setError('');
    setSaving(true);
    try {
      const [sourceResponse, bankResponse] = await Promise.all([
        type === 'credit'
          ? api.get('/sales', { params: { per_page: 50 } })
          : api.get('/purchases', { params: { per_page: 50 } }),
        api.get('/banking/accounts'),
      ]);
      setSources(sourceResponse.data.data);
      setBanks(bankResponse.data.data);
      setSourceId('');
      setNoteSource(null);
      setQuantities({});
      setSettlementMethod('cash');
      setBankAccountId('');
      setNoteDate(today());
      setReason('');
      setNoteModal({ type });
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not prepare the return note.'));
    } finally {
      setSaving(false);
    }
  };

  const chooseSource = async (event) => {
    const id = event.target.value;
    setSourceId(id);
    setNoteSource(null);
    setQuantities({});
    if (!id) return;
    try {
      const endpoint = noteModal.type === 'credit' ? `/sales/${id}` : `/purchases/${id}`;
      const response = await api.get(endpoint);
      setNoteSource(response.data);
      setSettlementMethod(response.data.payment_method === 'credit' ? 'credit' : 'cash');
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not load the original transaction.'));
    }
  };

  const estimatedTotal = useMemo(() => {
    if (!noteSource) return 0;
    return noteSource.items.reduce((sum, item) => {
      const quantity = Number(quantities[item.id]) || 0;
      return sum + (Number(item.line_total) * quantity / Number(item.quantity));
    }, 0);
  }, [noteSource, quantities]);

  const saveReturnNote = async (event) => {
    event.preventDefault();
    if (!noteSource) return;
    const items = noteSource.items
      .filter((item) => Number(quantities[item.id]) > 0)
      .map((item) => ({ item_id: item.id, quantity: Number(quantities[item.id]) }));
    if (items.length === 0) {
      setError('Enter a return quantity for at least one line item.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      const type = noteModal.type;
      const payload = {
        [type === 'credit' ? 'sale_id' : 'purchase_id']: Number(sourceId),
        note_date: noteDate,
        settlement_method: settlementMethod,
        bank_account_id: settlementMethod === 'bank' && bankAccountId ? Number(bankAccountId) : null,
        reason: reason.trim() || null,
        items,
      };
      const response = await api.post(type === 'credit' ? '/credit-notes' : '/debit-notes', payload);
      setNoteModal(null);
      setNotice(`${response.data.note_number} was recorded and posted to the books.`);
      await (type === 'credit' ? api.get('/credit-notes', { params: { from: filters.from || undefined, to: filters.to || undefined } }) : api.get('/debit-notes', { params: { from: filters.from || undefined, to: filters.to || undefined } }))
        .then((result) => { setTab(type === 'credit' ? 'credit-notes' : 'debit-notes'); setNotes(result.data.data); setSummary(result.data.summary); });
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not create this return note.'));
    } finally {
      setSaving(false);
    }
  };

  const updateFilter = (event) => {
    setLoading(true);
    setError('');
    setFilters((current) => ({ ...current, [event.target.name]: event.target.value }));
  };
  const settlementOptions = [
    { value: 'cash', label: 'Cash' }, { value: 'upi', label: 'UPI' }, { value: 'card', label: 'Card' },
    { value: 'bank', label: 'Bank transfer' }, { value: 'credit', label: 'Adjust outstanding balance' },
  ];

  return (
    <section className="page-stack">
      <div className="page-heading"><div><span className="eyebrow">CUSTOMER INVOICES & RETURNS</span><h1>Invoices</h1><p>Review issued invoices and keep credit and debit notes linked to the original transaction.</p></div><div className="d-flex gap-2"><button type="button" className="btn btn-outline-primary" disabled={saving} onClick={() => startReturnNote('debit')}><i className="bx bx-minus-circle me-1" /> Debit note</button><button type="button" className="btn btn-primary" disabled={saving} onClick={() => startReturnNote('credit')}><i className="bx bx-undo me-1" /> Credit note</button></div></div>
      {notice && <div className="alert alert-success py-2" role="status">{notice}<button className="btn-close float-end" type="button" aria-label="Dismiss" onClick={() => setNotice('')} /></div>}
      {error && <div className="alert alert-danger py-2" role="alert">{error}</div>}
      <div className="finance-tabs" role="tablist" aria-label="Invoice records">
        {[['invoices', 'Invoices', 'bx-file'], ['credit-notes', 'Credit notes', 'bx-undo'], ['debit-notes', 'Debit notes', 'bx-log-in-circle']].map(([id, label, icon]) => <button key={id} role="tab" aria-selected={tab === id} type="button" className={tab === id ? 'is-active' : ''} onClick={() => { setLoading(true); setTab(id); setError(''); }}><i className={`bx ${icon}`} /> {label}</button>)}
      </div>
      <div className="summary-grid summary-grid-two">
        <article className="summary-card"><span className="summary-icon summary-icon-violet"><i className="bx bx-file" /></span><div><small>{tab === 'invoices' ? 'Invoices in view' : 'Notes in view'}</small><strong>{loading ? '—' : summary.count}</strong></div></article>
        <article className="summary-card"><span className="summary-icon summary-icon-green"><i className="bx bx-wallet" /></span><div><small>{tab === 'invoices' ? 'Invoice total' : 'Note total'}</small><strong>{loading ? '—' : formatMoney(summary.total)}</strong></div></article>
      </div>
      <section className="data-card">
        <div className="data-card-toolbar invoice-toolbar"><div><h2>{tab === 'invoices' ? 'Invoice register' : tab === 'credit-notes' ? 'Sales returns' : 'Purchase returns'}</h2><span className="muted-copy">{tab === 'invoices' ? 'Printed invoices include original sale details.' : 'Each note adjusts its original transaction and accounting entries.'}</span></div>
          <form className="invoice-filter" onSubmit={(event) => event.preventDefault()}><label className="compact-field"><span>From</span><input aria-label="From date" type="date" name="from" value={filters.from} onChange={updateFilter} /></label><label className="compact-field"><span>To</span><input aria-label="To date" type="date" name="to" value={filters.to} onChange={updateFilter} /></label>{tab === 'invoices' && <><label className="compact-field"><span>Status</span><select name="status" value={filters.status} onChange={updateFilter}><option value="all">All invoices</option><option value="active">Active</option><option value="void">Voided</option></select></label><label className="search-box"><i className="bx bx-search" /><input name="search" aria-label="Search invoices" placeholder="Invoice or customer" value={filters.search} onChange={updateFilter} /></label></>}</form>
        </div>
        {loading ? <div className="loading-state">Loading records…</div> : tab === 'invoices' ? invoices.length === 0 ? <div className="empty-state"><h3>No invoices found</h3><p>Sales recorded in this business appear here as invoices.</p></div> : <div className="table-responsive"><table className="business-table"><thead><tr><th>Invoice</th><th>Date</th><th>Customer</th><th>Payment</th><th>Status</th><th className="text-end">Total</th><th className="text-end">Due</th><th /></tr></thead><tbody>{invoices.map((invoice) => <tr key={invoice.id} className={invoice.status === 'void' ? 'invoice-void-row' : ''}><td><button className="invoice-link-button" type="button" onClick={() => openInvoice(invoice)}><strong>{invoice.invoice_number}</strong></button></td><td>{formatDate(invoice.sale_date)}</td><td>{invoice.customer?.name || invoice.customer_name_snapshot}</td><td className="text-capitalize">{invoice.payment_method}</td><td><span className={`invoice-status ${invoice.status}`}>{invoice.status}</span></td><td className="text-end fw-semibold">{formatMoney(invoice.total)}</td><td className="text-end">{formatMoney(invoice.balance_due)}</td><td><button type="button" className="icon-button" title="View invoice" aria-label={`View ${invoice.invoice_number}`} onClick={() => openInvoice(invoice)}><i className="bx bx-show" /></button></td></tr>)}</tbody></table></div> : notes.length === 0 ? <div className="empty-state"><h3>No {tab === 'credit-notes' ? 'credit' : 'debit'} notes in this date range</h3><p>Create a note to record a customer or supplier return.</p></div> : <div className="table-responsive"><table className="business-table"><thead><tr><th>Note</th><th>Date</th><th>{tab === 'credit-notes' ? 'Invoice' : 'Purchase'}</th><th>Counterparty</th><th>Settlement</th><th className="text-end">Total</th></tr></thead><tbody>{notes.map((note) => <tr key={note.id}><td><strong>{note.note_number}</strong><small className="table-subtext">{note.items.length} returned line(s)</small></td><td>{formatDate(note.note_date)}</td><td>{note.sale?.invoice_number || note.purchase?.purchase_number || '—'}</td><td>{note.counterparty_name_snapshot}</td><td className="text-capitalize">{note.settlement_method}</td><td className="text-end fw-semibold">{formatMoney(note.total)}</td></tr>)}</tbody></table></div>}
      </section>

      {selectedInvoice && <div className="inventory-modal-backdrop invoice-print-backdrop"><section className="invoice-detail" role="dialog" aria-modal="true" aria-labelledby="invoice-print-title"><div className="d-flex justify-content-between align-items-start mb-3 no-print"><button className="btn btn-sm btn-outline-secondary" type="button" onClick={() => setSelectedInvoice(null)}><i className="bx bx-arrow-back me-1" /> Close</button><button className="btn btn-sm btn-primary" type="button" onClick={() => window.print()}><i className="bx bx-printer me-1" /> Print invoice</button></div><div className="invoice-detail-header"><div><span className="eyebrow">TAX INVOICE</span><h2 id="invoice-print-title">{selectedInvoice.invoice_number}</h2></div><span className={`invoice-status ${selectedInvoice.status}`}>{selectedInvoice.status}</span></div><div className="invoice-detail-meta"><div><span>Bill to</span><strong>{selectedInvoice.customer?.name || selectedInvoice.customer_name_snapshot}</strong>{selectedInvoice.customer?.phone && <small className="d-block text-secondary">{selectedInvoice.customer.phone}</small>}</div><div><span>Invoice date</span><strong>{formatDate(selectedInvoice.sale_date)}</strong><small className="d-block text-secondary">Payment: {selectedInvoice.payment_method}</small></div></div><div className="table-responsive"><table className="business-table"><thead><tr><th>Description</th><th>HSN/SAC</th><th>Qty</th><th className="text-end">Rate</th><th className="text-end">GST</th><th className="text-end">Amount</th></tr></thead><tbody>{selectedInvoice.items.map((item) => <tr key={item.id}><td>{item.description}</td><td>{item.hsn_sac || '—'}</td><td>{Number(item.quantity).toLocaleString()}</td><td className="text-end">{formatMoney(item.unit_rate)}</td><td className="text-end">{Number(item.gst_rate)}%</td><td className="text-end">{formatMoney(item.line_total)}</td></tr>)}</tbody></table></div><div className="invoice-total-list"><div><span>Taxable amount</span><strong>{formatMoney(selectedInvoice.taxable_amount)}</strong></div><div><span>GST</span><strong>{formatMoney(selectedInvoice.tax_amount)}</strong></div><div className="invoice-grand-total"><span>Total</span><strong>{formatMoney(selectedInvoice.total)}</strong></div><div><span>Balance due</span><strong>{formatMoney(selectedInvoice.balance_due)}</strong></div></div>{selectedInvoice.notes && <p className="mt-4 text-secondary small">Notes: {selectedInvoice.notes}</p>}</section></div>}

      {noteModal && <div className="inventory-modal-backdrop"><section className="inventory-modal" role="dialog" aria-modal="true" aria-labelledby="return-note-title"><div className="inventory-modal-header"><div><span className="eyebrow">{noteModal.type === 'credit' ? 'SALES RETURN' : 'PURCHASE RETURN'}</span><h2 id="return-note-title">New {noteModal.type} note</h2></div><button className="icon-button" type="button" aria-label="Close" onClick={() => setNoteModal(null)}><i className="bx bx-x" /></button></div><form onSubmit={saveReturnNote}><div className="inventory-modal-body"><div className="return-note-form"><label className="inventory-field">Original {noteModal.type === 'credit' ? 'invoice' : 'purchase'}<select required value={sourceId} onChange={chooseSource}><option value="">Select original transaction</option>{sources.map((source) => <option key={source.id} value={source.id}>{source.invoice_number || source.purchase_number} · {source.customer_name_snapshot || source.supplier_name_snapshot} · {formatMoney(source.total)}</option>)}</select></label>{noteSource && <><div className="alert alert-light border py-2 small mb-0">Return quantities cannot exceed the remaining quantity on the original transaction. Stock items are returned to their original lots.</div><div className="table-responsive"><table className="business-table return-items-table"><thead><tr><th>Item</th><th>Sold / bought</th><th>Return qty</th><th className="text-end">Rate</th></tr></thead><tbody>{noteSource.items.map((item) => <tr key={item.id}><td>{item.description}<small className="table-subtext">{Number(item.gst_rate)}% GST</small></td><td>{Number(item.quantity).toLocaleString()}</td><td><input aria-label={`Return quantity for ${item.description}`} type="number" min="0" max={item.quantity} step="0.001" value={quantities[item.id] || ''} onChange={(event) => setQuantities((current) => ({ ...current, [item.id]: event.target.value }))} /></td><td className="text-end">{formatMoney(item.unit_rate)}</td></tr>)}</tbody></table></div><div className="inventory-form-grid"><label className="inventory-field">Note date<input required type="date" value={noteDate} onChange={(event) => setNoteDate(event.target.value)} /></label><label className="inventory-field">Settlement<select value={settlementMethod} onChange={(event) => setSettlementMethod(event.target.value)}>{settlementOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></label>{settlementMethod === 'bank' && <label className="inventory-field inventory-field-wide">Bank account<select required value={bankAccountId} onChange={(event) => setBankAccountId(event.target.value)}><option value="">Select bank account</option>{banks.map((bank) => <option key={bank.id} value={bank.id}>{bank.name} · {bank.bank_name}</option>)}</select></label>}<label className="inventory-field inventory-field-wide">Reason<textarea rows="2" maxLength="255" value={reason} onChange={(event) => setReason(event.target.value)} /></label></div><div className="return-note-total"><span>Estimated note total</span><span>{formatMoney(estimatedTotal)}</span></div></>}</div>{error && <div className="alert alert-danger py-2 mt-3">{error}</div>}</div><div className="inventory-modal-footer"><button className="btn btn-outline-secondary" type="button" onClick={() => setNoteModal(null)}>Cancel</button><button className="btn btn-primary" type="submit" disabled={!noteSource || saving}>{saving ? 'Posting…' : 'Create and post note'}</button></div></form></section></div>}
    </section>
  );
}
