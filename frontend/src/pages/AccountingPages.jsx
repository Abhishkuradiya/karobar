import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/axios';
import { formatDate, formatMoney, getErrorMessage } from '../utils/format';
import './inventory-banking-invoices.css';
import './accounting-pages.css';
import PaginationControls from '../components/PaginationControls';
import { useFeedback } from '../context/FeedbackContext';
import MessageBanner from '../components/MessageBanner';

const currentMonth = () => {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
};

const localToday = () => {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

const flattenAccounts = (groups) => Object.values(groups || {}).flat();

function PageHeading({ eyebrow, title, description, children }) {
  return (
    <div className="page-heading accounting-page-heading">
      <div><span className="eyebrow">{eyebrow}</span><h1>{title}</h1><p>{description}</p></div>
      {children && <div className="accounting-heading-actions">{children}</div>}
    </div>
  );
}

function PageAlerts({ error, notice, onDismiss }) {
  return (
    <>
      <MessageBanner message={notice} onDismiss={onDismiss} />
      <MessageBanner message={error} type="error" onDismiss={onDismiss} />
    </>
  );
}

function SummaryCard({ icon, label, value, tone = 'violet' }) {
  return <article className="summary-card"><span className={`summary-icon summary-icon-${tone}`}><i className={`bx ${icon}`} /></span><div><small>{label}</small><strong>{value}</strong></div></article>;
}

function DateMonthField({ value, onChange }) {
  return <label className="compact-field"><span>Reporting month</span><input aria-label="Reporting month" type="month" value={value} onChange={(event) => onChange(event.target.value)} /></label>;
}

export function GstPage() {
  const [month, setMonth] = useState(currentMonth);
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [exporting, setExporting] = useState('');
  const changeMonth = (value) => {
    setLoading(true);
    setError('');
    setMonth(value);
  };

  useEffect(() => {
    let active = true;
    api.get('/gst/report', { params: { month } })
      .then((response) => { if (active) setReport(response.data); })
      .catch((requestError) => { if (active) setError(getErrorMessage(requestError, 'Could not load the GST report.')); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [month]);

  const download = async (format) => {
    setExporting(format);
    setError('');
    try {
      const response = await api.get('/gst/export', {
        params: { month, format },
        responseType: format === 'csv' ? 'blob' : 'json',
      });
      const body = format === 'csv' ? response.data : new Blob([JSON.stringify(response.data, null, 2)], { type: 'application/json' });
      const url = URL.createObjectURL(body);
      const link = document.createElement('a');
      link.href = url;
      link.download = `gst-report-${month}.${format}`;
      document.body.appendChild(link);
      link.click();
      link.remove();
      URL.revokeObjectURL(url);
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not download the GST report.'));
    } finally {
      setExporting('');
    }
  };

  const liability = report?.summary?.liability_after_setoff;
  return (
    <section className="page-stack">
      <PageHeading eyebrow="TAX & COMPLIANCE" title="GST" description="Review outward supplies, input tax credit and period-wise return data.">
        <button className="btn btn-outline-primary" type="button" disabled={!report || exporting !== ''} onClick={() => download('json')}>{exporting === 'json' ? 'Preparing…' : 'Download JSON'}</button>
        <button className="btn btn-primary" type="button" disabled={!report || exporting !== ''} onClick={() => download('csv')}><i className="bx bx-download me-1" />{exporting === 'csv' ? 'Preparing…' : 'Download Excel-compatible CSV'}</button>
      </PageHeading>
      <PageAlerts error={error} />
      <div className="accounting-filter-row"><DateMonthField value={month} onChange={changeMonth} />{report?.business?.gstin && <span className="accounting-context-chip"><i className="bx bx-check-shield" /> GSTIN {report.business.gstin}</span>}</div>
      {loading ? <div className="loading-state">Preparing GST summary…</div> : report && <>
        <div className="summary-grid accounting-summary-grid">
          <SummaryCard icon="bx-receipt" label="Taxable sales" value={formatMoney(report.summary.taxable_sales)} />
          <SummaryCard icon="bx-trending-up" label="Output tax" value={formatMoney(report.summary.output_tax.total)} tone="orange" />
          <SummaryCard icon="bx-wallet" label="Input tax credit" value={formatMoney(report.summary.input_tax_credit.total)} tone="green" />
          <SummaryCard icon="bx-calculator" label="Net payable after set-off" value={formatMoney(liability?.total)} tone="blue" />
        </div>
        <section className="data-card">
          <div className="data-card-toolbar"><div><h2>Tax liability and set-off</h2><span className="muted-copy">Credit is applied in GST order; CGST and SGST credits are not cross-used.</span></div></div>
          <div className="table-responsive"><table className="business-table"><thead><tr><th>Tax head</th><th className="text-end">Output liability</th><th className="text-end">Input credit used</th><th className="text-end">Net payable</th><th className="text-end">Credit remaining</th></tr></thead><tbody>
            {['igst', 'cgst', 'sgst'].map((key) => <tr key={key}><td className="text-uppercase fw-semibold">{key}</td><td className="text-end">{formatMoney(report.summary.output_tax[key])}</td><td className="text-end">{formatMoney(report.summary.setoff_used[key])}</td><td className="text-end fw-semibold">{formatMoney(liability[key])}</td><td className="text-end">{formatMoney(report.summary.input_tax_credit_remaining[key])}</td></tr>)}
            <tr className="accounting-total-row"><td>Total</td><td className="text-end">{formatMoney(report.summary.output_tax.total)}</td><td className="text-end">{formatMoney(report.summary.setoff_used.total)}</td><td className="text-end">{formatMoney(liability.total)}</td><td className="text-end">{formatMoney(report.summary.input_tax_credit_remaining.total)}</td></tr>
          </tbody></table></div>
        </section>
        <section className="data-card">
          <div className="data-card-toolbar"><div><h2>GSTR-1 outward supplies</h2><span className="muted-copy">{report.gstr1.b2b.length} B2B invoice(s) and {report.gstr1.b2c.length} B2C rate/place group(s).</span></div></div>
          {report.gstr1.b2b.length === 0 ? <div className="accounting-inline-empty">No registered-customer invoices for this period.</div> : <div className="table-responsive"><table className="business-table"><thead><tr><th>Invoice</th><th>Date</th><th>Customer / GSTIN</th><th>Place of supply</th><th className="text-end">Taxable</th><th className="text-end">Tax</th></tr></thead><tbody>{report.gstr1.b2b.map((invoice) => <tr key={`${invoice.invoice_number}-${invoice.invoice_date}`}><td><strong>{invoice.invoice_number}</strong></td><td>{formatDate(invoice.invoice_date)}</td><td>{invoice.customer_name}<small className="table-subtext">{invoice.customer_gstin}</small></td><td>{invoice.place_of_supply || '—'}</td><td className="text-end">{formatMoney(invoice.taxable_amount)}</td><td className="text-end">{formatMoney(invoice.tax.total)}</td></tr>)}</tbody></table></div>}
          {report.gstr1.b2c.length > 0 && <div className="table-responsive accounting-secondary-table"><table className="business-table"><thead><tr><th>B2C place of supply</th><th>GST rate</th><th className="text-end">Taxable</th><th className="text-end">Tax</th></tr></thead><tbody>{report.gstr1.b2c.map((row, index) => <tr key={`${row.place_of_supply}-${row.gst_rate}-${index}`}><td>{row.place_of_supply}</td><td>{row.gst_rate}%</td><td className="text-end">{formatMoney(row.taxable_amount)}</td><td className="text-end">{formatMoney(row.tax.total)}</td></tr>)}</tbody></table></div>}
        </section>
        <div className="accounting-two-column">
          <section className="data-card"><div className="data-card-toolbar"><div><h2>GST rate summary</h2><span className="muted-copy">Outward tax and eligible input credit by rate.</span></div></div><div className="table-responsive"><table className="business-table"><thead><tr><th>Rate</th><th className="text-end">Taxable outward</th><th className="text-end">Output tax</th><th className="text-end">Input credit</th></tr></thead><tbody>{report.rate_summary.length ? report.rate_summary.map((row) => <tr key={row.gst_rate}><td>{row.gst_rate}%</td><td className="text-end">{formatMoney(row.taxable_outward)}</td><td className="text-end">{formatMoney(row.output_tax.total)}</td><td className="text-end">{formatMoney(row.input_tax_credit.total)}</td></tr>) : <tr><td colSpan="4" className="text-center text-secondary">No tax lines in this period.</td></tr>}</tbody></table></div></section>
          <section className="data-card"><div className="data-card-toolbar"><div><h2>Documents issued</h2><span className="muted-copy">Documents captured in this reporting period.</span></div></div><div className="accounting-document-list"><div><span>Invoices issued, including voided</span><strong>{report.gstr1.documents_issued.invoices}</strong></div><div><span>Credit notes</span><strong>{report.gstr1.documents_issued.credit_notes}</strong></div><div><span>Debit notes</span><strong>{report.gstr1.documents_issued.debit_notes}</strong></div><div><span>Reverse-charge tax</span><strong>{formatMoney(report.summary.reverse_charge_tax.total)}</strong></div></div></section>
        </div>
        <section className="data-card"><div className="data-card-toolbar"><div><h2>HSN / SAC summary</h2><span className="muted-copy">Sales items and linked credit notes, grouped by code and rate.</span></div></div><div className="table-responsive"><table className="business-table"><thead><tr><th>HSN / SAC</th><th>Rate</th><th className="text-end">Quantity</th><th className="text-end">Taxable amount</th><th className="text-end">IGST</th><th className="text-end">CGST</th><th className="text-end">SGST</th></tr></thead><tbody>{report.hsn_summary.length ? report.hsn_summary.map((row) => <tr key={`${row.hsn_sac}-${row.gst_rate}`}><td>{row.hsn_sac}</td><td>{row.gst_rate}%</td><td className="text-end">{Number(row.quantity).toLocaleString('en-IN')}</td><td className="text-end">{formatMoney(row.taxable_amount)}</td><td className="text-end">{formatMoney(row.tax.igst)}</td><td className="text-end">{formatMoney(row.tax.cgst)}</td><td className="text-end">{formatMoney(row.tax.sgst)}</td></tr>) : <tr><td colSpan="7" className="text-center text-secondary">No sales line items in this period.</td></tr>}</tbody></table></div></section>
      </>}
    </section>
  );
}

export function CashTallyPage() {
  const [date, setDate] = useState(localToday);
  const [bookAmount, setBookAmount] = useState(0);
  const [tally, setTally] = useState(null);
  const [recent, setRecent] = useState([]);
  const [countedAmount, setCountedAmount] = useState('');
  const [note, setNote] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const changeDate = (value) => {
    setLoading(true);
    setError('');
    setDate(value);
  };

  const load = useCallback(async () => {
    const response = await api.get('/cash-tally', { params: { date } });
    setBookAmount(Number(response.data.book_amount) || 0);
    setTally(response.data.tally);
    setRecent(response.data.recent);
    setCountedAmount(response.data.tally?.counted_amount ?? '');
    setNote(response.data.tally?.note ?? '');
  }, [date]);

  useEffect(() => {
    let active = true;
    api.get('/cash-tally', { params: { date } })
      .then((response) => {
        if (!active) return;
        setBookAmount(Number(response.data.book_amount) || 0);
        setTally(response.data.tally);
        setRecent(response.data.recent);
        setCountedAmount(response.data.tally?.counted_amount ?? '');
        setNote(response.data.tally?.note ?? '');
      })
      .catch((requestError) => { if (active) setError(getErrorMessage(requestError, 'Could not load the cash tally.')); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [date]);

  const difference = Number(countedAmount) - bookAmount;
  const save = async (event) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    setNotice('');
    try {
      const response = await api.post('/cash-tally', { counted_date: date, counted_amount: Number(countedAmount), note: note.trim() || null });
      setTally(response.data.data);
      setNotice('Cash tally saved. Any difference has been posted to the cash ledger.');
      await load();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not save this cash tally.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <section className="page-stack">
      <PageHeading eyebrow="DAILY CASH CONTROL" title="Cash tally" description="Compare the physical cash count with your book balance and record any difference." />
      <PageAlerts error={error} notice={notice} onDismiss={() => setNotice('')} />
      <div className="accounting-filter-row"><label className="compact-field"><span>Tally date</span><input aria-label="Tally date" type="date" max={localToday()} value={date} onChange={(event) => changeDate(event.target.value)} /></label><span className="accounting-context-chip"><i className="bx bx-lock-alt" /> Book balance is calculated from posted cash entries</span></div>
      <div className="accounting-two-column accounting-tally-layout">
        <section className="data-card accounting-tally-card">
          <div className="data-card-toolbar"><div><h2>Physical cash count</h2><span className="muted-copy">Save one reconciliation per date.</span></div></div>
          {loading ? <div className="loading-state">Loading cash balance…</div> : <form className="accounting-tally-form" onSubmit={save}>
            <div className="accounting-book-amount"><span>Cash in books</span><strong>{formatMoney(bookAmount)}</strong></div>
            <label className="inventory-field">Cash counted<input type="number" min="0" step="0.01" required disabled={Boolean(tally)} value={countedAmount} onChange={(event) => setCountedAmount(event.target.value)} placeholder="Enter the counted amount" /></label>
            <label className="inventory-field">Note<textarea rows="3" maxLength="255" disabled={Boolean(tally)} value={note} onChange={(event) => setNote(event.target.value)} placeholder="Add a note about this count" /></label>
            {countedAmount !== '' && <div className={`accounting-difference${difference < 0 ? ' is-negative' : ''}`}><span>Difference</span><strong>{formatMoney(difference)}</strong></div>}
            {tally && <div className="alert alert-success mb-0">This date has already been tallied. The posted count is read-only.</div>}
            <button type="submit" className="btn btn-primary accounting-submit" disabled={saving || Boolean(tally) || countedAmount === ''}>{saving ? 'Saving…' : 'Save cash tally'}</button>
          </form>}
        </section>
        <section className="data-card">
          <div className="data-card-toolbar"><div><h2>Recent cash counts</h2><span className="muted-copy">Latest reconciliations for this business.</span></div></div>
          {recent.length === 0 ? <div className="accounting-inline-empty">No cash tallies recorded yet.</div> : <div className="table-responsive"><table className="business-table"><thead><tr><th>Date</th><th className="text-end">Book</th><th className="text-end">Counted</th><th className="text-end">Difference</th></tr></thead><tbody>{recent.map((item) => <tr key={item.id}><td>{formatDate(item.counted_date)}</td><td className="text-end">{formatMoney(item.book_amount)}</td><td className="text-end">{formatMoney(item.counted_amount)}</td><td className={`text-end fw-semibold${Number(item.difference) < 0 ? ' text-danger' : ''}`}>{formatMoney(item.difference)}</td></tr>)}</tbody></table></div>}
        </section>
      </div>
    </section>
  );
}

export function ActivityPage() {
  const [filters, setFilters] = useState({ from: '', to: '' });
  const [searchInput, setSearchInput] = useState('');
  const [search, setSearch] = useState('');
  const [page, setPage] = useState(1);
  const [items, setItems] = useState([]);
  const [meta, setMeta] = useState({ total: 0 });
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const updateFilters = (key, value) => {
    setLoading(true);
    setError('');
    setFilters((current) => ({ ...current, [key]: value }));
  };

  useEffect(() => {
    let active = true;
    api.get('/activity', { params: { from: filters.from || undefined, to: filters.to || undefined, search: search || undefined, page, per_page: 25 } })
      .then((response) => {
        if (!active) return;
        setItems(response.data.data);
        setMeta(response.data.meta);
      })
      .catch((requestError) => { if (active) setError(getErrorMessage(requestError, 'Could not load business activity.')); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [filters, page, search]);

  const applySearch = (event) => { event.preventDefault(); setPage(1); setSearch(searchInput.trim()); };

  return (
    <section className="page-stack">
      <PageHeading eyebrow="BUSINESS RECORD" title="Activity" description="A chronological audit trail of accounting entries posted in this business." />
      <PageAlerts error={error} />
      <section className="data-card">
        <div className="data-card-toolbar accounting-activity-toolbar"><div><h2>Recent activity</h2><span className="muted-copy">{meta.total} event(s) in the selected range.</span></div>
          <div className="accounting-date-filters"><form className="search-form" onSubmit={applySearch}><i className="bx bx-search" /><input aria-label="Search activity" value={searchInput} onChange={(event) => setSearchInput(event.target.value)} placeholder="Search activity" /><button type="submit" className="btn btn-outline-secondary btn-sm">Search</button></form><label className="compact-field"><span>From</span><input type="date" aria-label="Activity start date" value={filters.from} onChange={(event) => { setPage(1); updateFilters('from', event.target.value); }} /></label><label className="compact-field"><span>To</span><input type="date" aria-label="Activity end date" value={filters.to} onChange={(event) => { setPage(1); updateFilters('to', event.target.value); }} /></label></div>
        </div>
        {loading ? <div className="loading-state">Loading activity…</div> : items.length === 0 ? <div className="empty-state"><span className="empty-state-icon"><i className="bx bx-time-five" /></span><h3>No activity in this period</h3><p>Posted sales, purchases, expenses and journal entries will appear here.</p></div> : <div className="accounting-activity-list">{items.map((item) => <article className="accounting-activity-item" key={item.id}><span className={`accounting-activity-icon${item.is_reversal ? ' is-reversal' : ''}`}><i className={`bx ${item.is_reversal ? 'bx-undo' : 'bx-check'}`} /></span><div className="accounting-activity-copy"><strong>{item.title}</strong><span>{item.description}</span><small>{formatDate(item.entry_date)} · {item.user || 'Business user'} · {item.source_type.replaceAll('_', ' ')}</small></div><strong className="accounting-activity-amount">{formatMoney(item.amount)}</strong></article>)}</div>}
      <PaginationControls page={page} meta={meta} onChange={(nextPage) => { setLoading(true); setPage(nextPage); }} />
      </section>
    </section>
  );
}

export function MonthEndClosePage() {
  const [month, setMonth] = useState(currentMonth);
  const [report, setReport] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const changeMonth = (value) => {
    setLoading(true);
    setError('');
    setMonth(value);
  };

  useEffect(() => {
    let active = true;
    api.get('/month-end-close', { params: { month } })
      .then((response) => { if (active) setReport(response.data); })
      .catch((requestError) => { if (active) setError(getErrorMessage(requestError, 'Could not load the month-end checklist.')); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [month]);

  const statusLabel = { done: 'Complete', needs_attention: 'Needs attention', action_required: 'Review', not_started: 'Not started' };
  return (
    <section className="page-stack">
      <PageHeading eyebrow="PERIOD-END WORKSPACE" title="Month-end close" description="Work through a practical close checklist before finalising the month." />
      <PageAlerts error={error} />
      <div className="accounting-filter-row"><DateMonthField value={month} onChange={changeMonth} />{report && <span className="accounting-context-chip">{formatDate(report.period.from)} – {formatDate(report.period.to)}</span>}</div>
      {loading ? <div className="loading-state">Building your close checklist…</div> : report && <>
        <section className="data-card accounting-progress-card"><div className="accounting-progress-copy"><div><span className="eyebrow">CLOSE PROGRESS</span><h2>{report.progress.completed} of {report.progress.total} checklist items complete</h2><p>Complete the available reviews below. GST filing itself should be confirmed with your tax professional.</p></div><strong>{report.progress.percentage}%</strong></div><div className="progress accounting-progress" role="progressbar" aria-valuenow={report.progress.percentage} aria-valuemin="0" aria-valuemax="100"><div className="progress-bar" style={{ width: `${report.progress.percentage}%` }} /></div></section>
        <div className="accounting-checklist">{report.data.map((item) => <article className={`accounting-check-item status-${item.status}`} key={item.key}><span className="accounting-check-icon"><i className={`bx ${item.status === 'done' ? 'bx-check' : item.status === 'needs_attention' ? 'bx-error-circle' : 'bx-circle'}`} /></span><div className="accounting-check-copy"><div><h2>{item.title}</h2><span className={`accounting-status-pill status-${item.status}`}>{statusLabel[item.status]}</span></div><p>{item.description}</p></div><Link className="btn btn-sm btn-outline-primary" to={item.href}>Review <i className="bx bx-right-arrow-alt ms-1" /></Link></article>)}</div>
      </>}
    </section>
  );
}

export function JournalPage() {
  const [filters, setFilters] = useState({ from: '', to: '', account_code: '' });
  const [entries, setEntries] = useState([]);
  const [summary, setSummary] = useState({ count: 0, debits: 0, credits: 0 });
  const [accounts, setAccounts] = useState([]);
  const [modalOpen, setModalOpen] = useState(false);
  const [entryDate, setEntryDate] = useState(localToday);
  const [narration, setNarration] = useState('');
  const [lines, setLines] = useState([
    { account_code: '', debit: '', credit: '' },
    { account_code: '', debit: '', credit: '' },
  ]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const updateFilters = (key, value) => {
    setLoading(true);
    setError('');
    setFilters((current) => ({ ...current, [key]: value }));
  };

  const load = useCallback(async () => {
    const [journalResponse, accountResponse] = await Promise.all([
      api.get('/journal-entries', { params: { from: filters.from || undefined, to: filters.to || undefined, account_code: filters.account_code || undefined, per_page: 100 } }),
      api.get('/chart-of-accounts'),
    ]);
    setEntries(journalResponse.data.data);
    setSummary(journalResponse.data.summary);
    setAccounts(flattenAccounts(accountResponse.data.data));
  }, [filters]);

  useEffect(() => {
    let active = true;
    Promise.all([
      api.get('/journal-entries', { params: { from: filters.from || undefined, to: filters.to || undefined, account_code: filters.account_code || undefined, per_page: 100 } }),
      api.get('/chart-of-accounts'),
    ])
      .then(([journalResponse, accountResponse]) => {
        if (!active) return;
        setEntries(journalResponse.data.data);
        setSummary(journalResponse.data.summary);
        setAccounts(flattenAccounts(accountResponse.data.data));
      })
      .catch((requestError) => { if (active) setError(getErrorMessage(requestError, 'Could not load journal entries.')); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [filters]);

  const debitTotal = useMemo(() => lines.reduce((total, line) => total + (Number(line.debit) || 0), 0), [lines]);
  const creditTotal = useMemo(() => lines.reduce((total, line) => total + (Number(line.credit) || 0), 0), [lines]);
  const updateLine = (index, key, value) => setLines((current) => current.map((line, lineIndex) => lineIndex === index
    ? { ...line, [key]: value, ...(key === 'debit' && Number(value) > 0 ? { credit: '' } : {}), ...(key === 'credit' && Number(value) > 0 ? { debit: '' } : {}) }
    : line));

  const saveEntry = async (event) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    setNotice('');
    try {
      await api.post('/journal-entries', {
        entry_date: entryDate,
        narration: narration.trim(),
        lines: lines.map((line) => ({ ...line, debit: Number(line.debit) || 0, credit: Number(line.credit) || 0 })),
      });
      setModalOpen(false);
      setNotice('Balanced journal adjustment posted.');
      setNarration('');
      setLines([{ account_code: '', debit: '', credit: '' }, { account_code: '', debit: '', credit: '' }]);
      await load();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not post this journal adjustment.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <section className="page-stack">
      <PageHeading eyebrow="ACCOUNTING LEDGER" title="Journal" description="Review posted entries and create balanced manual adjustments.">
        <button type="button" className="btn btn-primary" onClick={() => { setError(''); setModalOpen(true); }}><i className="bx bx-plus me-1" /> New adjustment</button>
      </PageHeading>
      <PageAlerts error={error} notice={notice} onDismiss={() => setNotice('')} />
      <div className="summary-grid summary-grid-two"><SummaryCard icon="bx-notepad" label="Entries in view" value={summary.count} /><SummaryCard icon="bx-transfer-alt" label="Debits / credits" value={`${formatMoney(summary.debits)} / ${formatMoney(summary.credits)}`} tone="green" /></div>
      <section className="data-card">
        <div className="data-card-toolbar accounting-activity-toolbar"><div><h2>Journal entries</h2><span className="muted-copy">Every entry is double-entry and business-scoped.</span></div><div className="accounting-date-filters"><label className="compact-field"><span>From</span><input type="date" value={filters.from} onChange={(event) => updateFilters('from', event.target.value)} /></label><label className="compact-field"><span>To</span><input type="date" value={filters.to} onChange={(event) => updateFilters('to', event.target.value)} /></label><label className="compact-field"><span>Account</span><select value={filters.account_code} onChange={(event) => updateFilters('account_code', event.target.value)}><option value="">All accounts</option>{accounts.map((account) => <option key={account.account_code} value={account.account_code}>{account.display_code} · {account.name}</option>)}</select></label></div></div>
        {loading ? <div className="loading-state">Loading journal…</div> : entries.length === 0 ? <div className="empty-state"><span className="empty-state-icon"><i className="bx bx-notepad" /></span><h3>No journal entries found</h3><p>Transactions posted for this business will appear here.</p></div> : <div className="table-responsive"><table className="business-table"><thead><tr><th>Date</th><th>Reference</th><th>Narration</th><th>Account lines</th><th className="text-end">Debit</th><th className="text-end">Credit</th></tr></thead><tbody>{entries.map((entry) => <tr key={entry.id}><td>{formatDate(entry.entry_date)}</td><td><span className="accounting-source-tag">{entry.source_type.replaceAll('_', ' ')}</span></td><td><strong>{entry.description}</strong><small className="table-subtext">Entry #{entry.id}</small></td><td><div className="accounting-journal-lines">{entry.lines.map((line) => { const account = accounts.find((item) => item.account_code === line.account_code); return <span key={line.id}>{account?.display_code || line.account_code} · {account?.name || 'Account'}</span>; })}</div></td><td className="text-end">{formatMoney(entry.debit_total)}</td><td className="text-end">{formatMoney(entry.credit_total)}</td></tr>)}</tbody></table></div>}
      </section>
      {modalOpen && <div className="inventory-modal-backdrop"><section className="inventory-modal accounting-modal" role="dialog" aria-modal="true" aria-labelledby="journal-modal-title"><div className="inventory-modal-header"><div><span className="eyebrow">DOUBLE-ENTRY ADJUSTMENT</span><h2 id="journal-modal-title">New journal entry</h2></div><button className="icon-button" type="button" aria-label="Close" onClick={() => setModalOpen(false)}><i className="bx bx-x" /></button></div><form onSubmit={saveEntry}><div className="inventory-modal-body"><div className="accounting-journal-form"><label className="inventory-field">Entry date<input required type="date" value={entryDate} onChange={(event) => setEntryDate(event.target.value)} /></label><label className="inventory-field">Narration<input required maxLength="255" value={narration} onChange={(event) => setNarration(event.target.value)} placeholder="Reason for this adjustment" /></label><div className="accounting-edit-lines"><div className="accounting-edit-line accounting-edit-line-heading"><span>Account</span><span>Debit</span><span>Credit</span><span /></div>{lines.map((line, index) => <div className="accounting-edit-line" key={index}><select required aria-label={`Account for journal line ${index + 1}`} value={line.account_code} onChange={(event) => updateLine(index, 'account_code', event.target.value)}><option value="">Select account</option>{accounts.map((account) => <option key={account.account_code} value={account.account_code}>{account.display_code} · {account.name}</option>)}</select><input aria-label={`Debit for journal line ${index + 1}`} type="number" min="0" step="0.01" value={line.debit} onChange={(event) => updateLine(index, 'debit', event.target.value)} /><input aria-label={`Credit for journal line ${index + 1}`} type="number" min="0" step="0.01" value={line.credit} onChange={(event) => updateLine(index, 'credit', event.target.value)} /><button className="icon-button danger-icon" type="button" aria-label={`Remove journal line ${index + 1}`} disabled={lines.length <= 2} onClick={() => setLines((current) => current.filter((_, lineIndex) => lineIndex !== index))}><i className="bx bx-trash" /></button></div>)}</div><button type="button" className="btn btn-sm btn-outline-primary align-self-start" onClick={() => setLines((current) => [...current, { account_code: '', debit: '', credit: '' }])}><i className="bx bx-plus me-1" /> Add line</button><div className={`accounting-balance-check${Math.round((debitTotal - creditTotal) * 100) === 0 ? ' is-balanced' : ''}`}><span>Debits <strong>{formatMoney(debitTotal)}</strong></span><span>Credits <strong>{formatMoney(creditTotal)}</strong></span><strong>{Math.round((debitTotal - creditTotal) * 100) === 0 ? 'Balanced' : 'Out of balance'}</strong></div></div></div><div className="inventory-modal-footer"><button type="button" className="btn btn-outline-secondary" onClick={() => setModalOpen(false)}>Cancel</button><button type="submit" className="btn btn-primary" disabled={saving || debitTotal <= 0 || Math.round((debitTotal - creditTotal) * 100) !== 0}>{saving ? 'Posting…' : 'Post adjustment'}</button></div></form></section></div>}
    </section>
  );
}

export function ChartOfAccountsPage() {
  const { confirm } = useFeedback();
  const [accounts, setAccounts] = useState([]);
  const [search, setSearch] = useState('');
  const [type, setType] = useState('');
  const [modalOpen, setModalOpen] = useState(false);
  const [editingAccount, setEditingAccount] = useState(null);
  const [form, setForm] = useState({ display_code: '', name: '', account_type: 'assets', group_name: '' });
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  useEffect(() => {
    let active = true;
    api.get('/chart-of-accounts')
      .then((response) => { if (active) setAccounts(flattenAccounts(response.data.data)); })
      .catch((requestError) => { if (active) setError(getErrorMessage(requestError, 'Could not load chart of accounts.')); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, []);

  const visibleAccounts = accounts.filter((account) => (!type || account.account_type === type)
    && (!search || `${account.display_code} ${account.name} ${account.group_name}`.toLowerCase().includes(search.toLowerCase())));
  const save = async (event) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    setNotice('');
    try {
      const payload = editingAccount?.is_system
        ? { name: form.name, group_name: form.group_name }
        : form;
      const response = editingAccount
        ? await api.patch(`/chart-of-accounts/${encodeURIComponent(editingAccount.account_code)}`, payload)
        : await api.post('/chart-of-accounts', form);
      setAccounts((current) => {
        const updated = editingAccount
          ? current.map((account) => account.account_code === response.data.account_code ? response.data : account)
          : [...current, response.data];
        return updated.sort((a, b) => a.display_code.localeCompare(b.display_code, undefined, { numeric: true }));
      });
      setModalOpen(false);
      setEditingAccount(null);
      setForm({ display_code: '', name: '', account_type: 'assets', group_name: '' });
      setNotice(editingAccount
        ? `${response.data.name} was updated.`
        : `${response.data.name} was added to the chart of accounts.`);
    } catch (requestError) {
      setError(getErrorMessage(requestError, editingAccount ? 'Could not update this account.' : 'Could not add this account.'));
    } finally {
      setSaving(false);
    }
  };

  const openCreate = () => {
    setEditingAccount(null);
    setForm({ display_code: '', name: '', account_type: 'assets', group_name: '' });
    setError('');
    setModalOpen(true);
  };

  const openEdit = (account) => {
    setEditingAccount(account);
    setForm({
      display_code: account.display_code,
      name: account.name,
      account_type: account.account_type,
      group_name: account.group_name,
    });
    setError('');
    setModalOpen(true);
  };

  const deleteAccount = async (account) => {
    if (!await confirm({ title: 'Delete this account?', message: `${account.name} cannot be deleted if it has journal history or is required by accounting workflows.`, confirmLabel: 'Delete account' })) return;
    setSaving(true);
    setError('');
    setNotice('');
    try {
      await api.delete(`/chart-of-accounts/${encodeURIComponent(account.account_code)}`);
      setAccounts((current) => current.filter((item) => item.account_code !== account.account_code));
      setNotice(`${account.name} was deleted.`);
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not delete this account.'));
    } finally {
      setSaving(false);
    }
  };

  const types = [['assets', 'Assets'], ['liabilities', 'Liabilities'], ['equity', 'Equity'], ['income', 'Income'], ['expenses', 'Expenses']];
  return (
    <section className="page-stack">
      <PageHeading eyebrow="ACCOUNTING STRUCTURE" title="Chart of accounts" description="Browse your ledger accounts and add business-specific accounts.">
        <button className="btn btn-primary" type="button" onClick={openCreate}><i className="bx bx-plus me-1" /> New account</button>
      </PageHeading>
      <PageAlerts error={error} notice={notice} onDismiss={() => setNotice('')} />
      <section className="data-card">
        <div className="data-card-toolbar accounting-accounts-toolbar"><div><h2>All accounts</h2><span className="muted-copy">{visibleAccounts.length} account(s) · system accounts are protected from deletion.</span></div><div className="accounting-date-filters"><label className="search-box"><i className="bx bx-search" /><input aria-label="Search accounts" placeholder="Search code or name" value={search} onChange={(event) => setSearch(event.target.value)} /></label><label className="compact-field"><span>Type</span><select value={type} onChange={(event) => setType(event.target.value)}><option value="">All account types</option>{types.map(([id, label]) => <option key={id} value={id}>{label}</option>)}</select></label></div></div>
        {loading ? <div className="loading-state">Loading accounts…</div> : visibleAccounts.length === 0 ? <div className="empty-state"><h3>No accounts match</h3><p>Try changing your search or account type.</p></div> : <div className="table-responsive"><table className="business-table"><thead><tr><th>Code</th><th>Account name</th><th>Account type</th><th>Group</th><th className="text-end">Balance</th><th>Source</th><th>Actions</th></tr></thead><tbody>{visibleAccounts.map((account) => <tr key={account.account_code}><td><strong>{account.display_code}</strong></td><td>{account.name}{account.is_required && <small className="accounting-required-note">Required</small>}</td><td className="text-capitalize">{account.account_type}</td><td>{account.group_name}</td><td className="text-end">{formatMoney(account.balance)}</td><td><span className={`accounting-source-tag${account.is_system ? '' : ' is-custom'}`}>{account.is_system ? 'System' : 'Custom'}</span></td><td><div className="accounting-account-actions"><button className="btn btn-sm btn-outline-primary" type="button" disabled={saving} onClick={() => openEdit(account)}><i className="bx bx-edit-alt me-1" />Edit</button><button className="btn btn-sm btn-outline-danger" type="button" disabled={saving || !account.can_delete} title={!account.can_delete ? (account.is_required ? 'Required system account' : 'Account has journal history') : 'Delete account'} onClick={() => deleteAccount(account)}><i className="bx bx-trash me-1" />Delete</button></div></td></tr>)}</tbody></table></div>}
      </section>
      {modalOpen && <div className="inventory-modal-backdrop"><section className="inventory-modal inventory-modal-narrow" role="dialog" aria-modal="true" aria-labelledby="account-modal-title"><div className="inventory-modal-header"><div><span className="eyebrow">BUSINESS LEDGER</span><h2 id="account-modal-title">{editingAccount ? 'Edit account' : 'Add account'}</h2></div><button className="icon-button" type="button" aria-label="Close" onClick={() => { setModalOpen(false); setEditingAccount(null); }}><i className="bx bx-x" /></button></div><form onSubmit={save}><div className="inventory-modal-body"><div className="accounting-journal-form"><label className="inventory-field">Account code<input required maxLength="20" disabled={Boolean(editingAccount?.is_system)} value={form.display_code} onChange={(event) => setForm((current) => ({ ...current, display_code: event.target.value }))} /></label><label className="inventory-field">Account name<input required maxLength="120" value={form.name} onChange={(event) => setForm((current) => ({ ...current, name: event.target.value }))} /></label><label className="inventory-field">Account type<select disabled={Boolean(editingAccount?.is_system || (editingAccount?.has_transactions))} value={form.account_type} onChange={(event) => setForm((current) => ({ ...current, account_type: event.target.value }))}>{types.map(([id, label]) => <option key={id} value={id}>{label}</option>)}</select></label><label className="inventory-field">Group name<input required maxLength="80" value={form.group_name} onChange={(event) => setForm((current) => ({ ...current, group_name: event.target.value }))} /></label><p className="small text-secondary mb-0">{editingAccount?.is_system ? 'System account codes and types are fixed to protect automatic postings.' : editingAccount?.has_transactions ? 'The account type cannot be changed because this account has journal history.' : 'Accounts with posted transactions are retained for audit history.'}</p></div></div><div className="inventory-modal-footer"><button type="button" className="btn btn-outline-secondary" onClick={() => { setModalOpen(false); setEditingAccount(null); }}>Cancel</button><button type="submit" className="btn btn-primary" disabled={saving}>{saving ? 'Saving…' : editingAccount ? 'Save changes' : 'Save account'}</button></div></form></section></div>}
    </section>
  );
}

export function ImportDataPage() {
  const [type, setType] = useState('tally_xml');
  const [file, setFile] = useState(null);
  const [openingDate, setOpeningDate] = useState(localToday);
  const [imports, setImports] = useState([]);
  const [selectedImport, setSelectedImport] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const loadImports = useCallback(async () => {
    const response = await api.get('/imports');
    setImports(response.data.data);
  }, []);

  useEffect(() => {
    let active = true;
    api.get('/imports')
      .then((response) => { if (active) setImports(response.data.data); })
      .catch((requestError) => { if (active) setError(getErrorMessage(requestError, 'Could not load import history.')); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, []);

  const preview = async (event) => {
    event.preventDefault();
    if (!file) return;
    setSaving(true);
    setError('');
    setNotice('');
    const payload = new FormData();
    payload.append('file', file);
    if (type !== 'journal_vouchers_csv') payload.append('opening_date', openingDate);
    try {
      const response = await api.post(`/imports/${type}/preview`, payload, { headers: { 'Content-Type': 'multipart/form-data' } });
      setSelectedImport(response.data.data);
      setNotice(`${response.data.summary.entries} entry previewed with ${response.data.summary.errors} error(s). Nothing has been posted yet.`);
      await loadImports();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not preview this file.'));
    } finally {
      setSaving(false);
    }
  };

  const commit = async () => {
    if (!selectedImport) return;
    setSaving(true);
    setError('');
    setNotice('');
    try {
      const response = await api.post(`/imports/${selectedImport.id}/commit`);
      setSelectedImport(response.data.data);
      setNotice(`${response.data.result.entries_posted} journal entry(ies) posted.`);
      await loadImports();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not commit this import.'));
    } finally {
      setSaving(false);
    }
  };

  const typeNames = {
    tally_xml: 'Tally XML',
    opening_balances_csv: 'Opening-balance CSV',
    journal_vouchers_csv: 'Journal-voucher CSV',
  };
  const previewData = selectedImport?.preview_data;
  const canCommit = selectedImport?.status === 'previewed' && previewData?.entries?.length > 0 && previewData?.errors?.length === 0;
  return (
    <section className="page-stack">
      <PageHeading eyebrow="MIGRATE YOUR BOOKS" title="Import data" description="Preview Tally XML or accounting CSV data before posting it to your business ledger." />
      <PageAlerts error={error} notice={notice} onDismiss={() => setNotice('')} />
      <div className="accounting-two-column accounting-import-layout">
        <section className="data-card">
          <div className="data-card-toolbar"><div><h2>Start an import</h2><span className="muted-copy">Uploads are staged privately and are not posted until you confirm.</span></div></div>
          <form className="accounting-import-form" onSubmit={preview}>
            <label className="inventory-field">Import format<select value={type} onChange={(event) => { setType(event.target.value); setFile(null); }}><option value="tally_xml">Tally XML export</option><option value="opening_balances_csv">Opening-balance CSV</option><option value="journal_vouchers_csv">Journal-voucher CSV</option></select></label>
            <label className="inventory-field">File<input required type="file" accept={type === 'tally_xml' ? '.xml,text/xml,application/xml' : '.csv,text/csv'} onChange={(event) => setFile(event.target.files?.[0] || null)} /><small className="text-secondary fw-normal">Maximum file size: 10 MB.</small></label>
            {type !== 'journal_vouchers_csv' && <label className="inventory-field">Opening date for balances<input type="date" value={openingDate} onChange={(event) => setOpeningDate(event.target.value)} /></label>}
            <div className="accounting-import-help"><strong>{typeNames[type]} format</strong>{type === 'opening_balances_csv' ? <p>Required columns: <code>account_code</code>, <code>debit</code>, <code>credit</code>. Alternatively use <code>opening_balance</code> as a signed amount. All rows must use one date.</p> : type === 'journal_vouchers_csv' ? <p>Required columns: <code>entry_date</code>, <code>voucher_number</code>, <code>account_code</code>, <code>debit</code>, <code>credit</code>. Debits and credits must balance for every voucher.</p> : <p>Ledger opening balances and balanced journal vouchers are imported when accounts map to this business chart of accounts.</p>}</div>
            <button type="submit" className="btn btn-primary" disabled={saving || !file}><i className="bx bx-search-alt me-1" />{saving ? 'Preparing preview…' : 'Preview import'}</button>
          </form>
        </section>
        <section className="data-card">
          <div className="data-card-toolbar"><div><h2>Import history</h2><span className="muted-copy">Drafts can be committed once. Posted imports stay in the audit trail.</span></div></div>
          {loading ? <div className="loading-state">Loading import history…</div> : imports.length === 0 ? <div className="accounting-inline-empty">No imports created yet.</div> : <div className="accounting-import-history">{imports.map((item) => <button type="button" className={`accounting-import-history-row${selectedImport?.id === item.id ? ' is-selected' : ''}`} key={item.id} onClick={() => { setSelectedImport(item); setError(''); }}><span className="accounting-import-file-icon"><i className="bx bx-file" /></span><span><strong>{item.original_name}</strong><small>{typeNames[item.import_type] || item.import_type} · {formatDate(item.created_at?.slice(0, 10))}</small></span><span className={`accounting-import-status status-${item.status}`}>{item.status}</span></button>)}</div>}
        </section>
      </div>
      {selectedImport && <section className="data-card">
        <div className="data-card-toolbar accounting-import-preview-toolbar"><div><h2>Review import preview</h2><span className="muted-copy">{selectedImport.original_name} · {previewData?.entries?.length || 0} balanced entry(ies) ready for review.</span></div><button className="btn btn-primary" type="button" disabled={!canCommit || saving} onClick={commit}>{saving ? 'Posting…' : selectedImport.status === 'committed' ? 'Already committed' : 'Confirm and post'}</button></div>
        {selectedImport.status === 'committed' && <div className="alert alert-success mx-3">This import has been posted. Journal entry IDs: {selectedImport.result_data?.journal_entry_ids?.join(', ') || '—'}.</div>}
        {previewData?.errors?.length > 0 && <div className="accounting-import-errors"><strong><i className="bx bx-error-circle me-1" />Fix these issues before committing</strong>{previewData.errors.map((item, index) => <p key={`${item.row}-${index}`}>{item.row ? `Row ${item.row}: ` : ''}{item.message}</p>)}</div>}
        {previewData?.entries?.length > 0 && <div className="accounting-import-entry-list">{previewData.entries.map((entry, index) => <article className="accounting-import-entry" key={`${entry.entry_date}-${index}`}><div className="accounting-import-entry-heading"><div><strong>{entry.description}</strong><small>{formatDate(entry.entry_date)} · {entry.source_type.replaceAll('_', ' ')}</small></div><span>{entry.lines.length} line(s)</span></div><div className="table-responsive"><table className="business-table"><thead><tr><th>Account code</th><th className="text-end">Debit</th><th className="text-end">Credit</th></tr></thead><tbody>{entry.lines.map((line, lineIndex) => <tr key={`${line.account_code}-${lineIndex}`}><td>{line.account_code}</td><td className="text-end">{formatMoney(line.debit)}</td><td className="text-end">{formatMoney(line.credit)}</td></tr>)}</tbody></table></div></article>)}</div>}
        {(!previewData?.entries?.length && !previewData?.errors?.length) && <div className="accounting-inline-empty">This import contains no entries.</div>}
      </section>}
    </section>
  );
}
