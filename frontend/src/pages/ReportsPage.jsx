import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import api from '../api/axios';
import { formatDate, formatMoney, getErrorMessage } from '../utils/format';
import './reports.css';

const localDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const today = () => localDate(new Date());
const yearStart = () => `${new Date().getFullYear()}-01-01`;
const forecastEnd = () => {
  const date = new Date();
  date.setDate(date.getDate() + 90);
  return localDate(date);
};

const reportCards = [
  { title: 'Tax-format statements', description: 'GST statements and tax-period summaries for review and export.', icon: 'bx-file-blank', to: '/gst', category: 'Tax & compliance' },
  { title: 'Income tax & compliance', description: 'Bookkeeping income and expense summary. Confirm filing with your tax professional.', icon: 'bx-calculator', id: 'income-tax-compliance', category: 'Tax & compliance' },
  { title: 'Business check-up', description: 'Review overdue invoices, unpaid obligations, low stock and expired batches.', icon: 'bx-pulse', id: 'business-checkup', category: 'Business health' },
  { title: 'Cash forecast', description: 'Known receivables and payments due over the selected horizon.', icon: 'bx-trending-up', id: 'cash-forecast', category: 'Business health' },
  { title: 'Profit by product', description: 'Compare taxable sales with recorded inventory cost by product.', icon: 'bx-purchase-tag', id: 'profit-by-product', category: 'Business health' },
  { title: 'Profit & Loss', description: 'Income, expenses and net result from posted journal entries.', icon: 'bx-line-chart', id: 'profit-and-loss', category: 'Financial statements' },
  { title: 'Balance sheet', description: 'Assets, liabilities and equity based on the posted ledger.', icon: 'bx-building-house', id: 'balance-sheet', category: 'Financial statements' },
  { title: 'Cash flow', description: 'Cash and bank movements from journal activity.', icon: 'bx-bar-chart-alt-2', id: 'cash-flow', category: 'Financial statements' },
  { title: 'Sales', description: 'Sales invoices, collections and outstanding customer balances.', icon: 'bx-receipt', id: 'sales', category: 'Transactions' },
  { title: 'Purchases', description: 'Supplier purchases, payments and outstanding balances.', icon: 'bx-cart', id: 'purchases', category: 'Transactions' },
  { title: 'Expenses', description: 'Business expenses by date, category and supplier.', icon: 'bx-pie-chart-alt-2', id: 'expenses', category: 'Transactions' },
  { title: 'Receivables', description: 'Open customer invoices and overdue balances.', icon: 'bx-money', id: 'receivables', category: 'Balances & ledgers' },
  { title: 'Payables', description: 'Open supplier purchases and unpaid expenses.', icon: 'bx-wallet', id: 'payables', category: 'Balances & ledgers' },
  { title: 'GST summary', description: 'Output tax, input credit, liability and return data.', icon: 'bx-file', to: '/gst', category: 'Tax & compliance' },
  { title: 'Customer ledgers', description: 'Customer invoices, amounts paid and balances.', icon: 'bx-group', id: 'customer-ledgers', category: 'Balances & ledgers' },
  { title: 'Vendor ledgers', description: 'Supplier purchases, expenses, payments and balances.', icon: 'bx-group', id: 'vendor-ledgers', category: 'Balances & ledgers' },
  { title: 'Inventory', description: 'Stock on hand, estimated value and low-stock levels.', icon: 'bx-cube', id: 'inventory', category: 'Business health' },
  { title: 'Trial balance', description: 'Account debit and credit balances as of a date.', icon: 'bx-notepad', id: 'trial-balance', category: 'Financial statements' },
  { title: 'Day book & journal', description: 'Posted journal entries with debit and credit totals.', icon: 'bx-calendar', id: 'day-book-journal', category: 'Balances & ledgers' },
  { title: 'Account ledgers', description: 'Choose an account to see its dated running balance.', icon: 'bx-book-open', id: 'account-ledgers', category: 'Balances & ledgers' },
];

function isMoneyColumn(key) {
  return /amount|balance|total|paid|sales|cost|profit|debit|credit|inflow|outflow|cash|billed|collected|receipts|payments|expenses|income|liabilit|equity|assets|outstanding|net/i.test(key);
}

function displayValue(value, key) {
  if (value === null || value === undefined || value === '') return '—';
  if (typeof value === 'number' && isMoneyColumn(key)) return formatMoney(value);
  if (typeof value === 'number') return value.toLocaleString('en-IN', { maximumFractionDigits: 3 });
  return value;
}

function ReportCatalog() {
  const groups = [...new Set(reportCards.map((card) => card.category))];

  return (
    <section className="page-stack reports-page">
      <header className="reports-heading">
        <span className="eyebrow">BUSINESS INSIGHTS</span>
        <h1>Reports</h1>
        <p>Calculated from this business’s invoices, inventory and double-entry books.</p>
      </header>
      {groups.map((group) => (
        <section className="reports-category" key={group} aria-labelledby={`reports-${group.replaceAll(' ', '-')}`}>
          <div className="reports-category-heading">
            <h2 id={`reports-${group.replaceAll(' ', '-')}`}>{group}</h2>
            <span>{reportCards.filter((card) => card.category === group).length} reports</span>
          </div>
          <div className="reports-card-grid">
            {reportCards.filter((card) => card.category === group).map((card) => {
              const destination = card.to || `/reports/${card.id}`;
              return (
                <article className="reports-card" key={card.title}>
                  <span className="reports-card-icon"><i className={`bx ${card.icon}`} /></span>
                  <h3>{card.title}</h3>
                  <p>{card.description}</p>
                  <Link to={destination}>Open report <i className="bx bx-right-arrow-alt" /></Link>
                </article>
              );
            })}
          </div>
        </section>
      ))}
    </section>
  );
}

function ReportDetail({ reportId }) {
  const isForecast = reportId === 'cash-forecast';
  const [from, setFrom] = useState(isForecast ? today() : yearStart());
  const [to, setTo] = useState(isForecast ? forecastEnd() : today());
  const [accountCode, setAccountCode] = useState('');
  const [data, setData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let active = true;
    api.get(`/reports/${reportId}`, {
      params: {
        from,
        to,
        ...(reportId === 'account-ledgers' && accountCode ? { account_code: accountCode } : {}),
      },
    })
      .then((response) => {
        if (active) setData(response.data);
      })
      .catch((requestError) => {
        if (active) setError(getErrorMessage(requestError, 'Could not load this report.'));
      })
      .finally(() => {
        if (active) setLoading(false);
      });
    return () => {
      active = false;
    };
  }, [reportId, from, to, accountCode]);

  const changePeriod = (setter) => (event) => {
    setLoading(true);
    setError('');
    setData(null);
    setter(event.target.value);
  };

  const downloadCsv = () => {
    if (!data) return;
    const escapeCell = (value) => `"${String(value ?? '').replaceAll('"', '""')}"`;
    const lines = [
      data.columns.map((column) => escapeCell(column.label)).join(','),
      ...data.rows.map((row) => data.columns.map((column) => escapeCell(row[column.key])).join(',')),
    ];
    const url = URL.createObjectURL(new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `${reportId}-${from}-to-${to}.csv`;
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);
  };

  return (
    <section className="page-stack reports-page">
      <header className="reports-detail-heading">
        <div>
          <Link className="reports-back-link" to="/reports"><i className="bx bx-left-arrow-alt" /> All reports</Link>
          <span className="eyebrow">BUSINESS REPORT</span>
          <h1>{data?.title || 'Report'}</h1>
          <p>Review the records for the selected period. Results are scoped to your active business.</p>
        </div>
        <button className="btn btn-outline-primary" type="button" onClick={downloadCsv} disabled={!data || data.rows.length === 0}>
          <i className="bx bx-download me-1" /> Download CSV
        </button>
      </header>

      <div className="reports-filter-bar">
        <label><span>From</span><input aria-label="Report start date" type="date" value={from} max={to} onChange={changePeriod(setFrom)} /></label>
        <label><span>To</span><input aria-label="Report end date" type="date" value={to} min={from} onChange={changePeriod(setTo)} /></label>
        {reportId === 'account-ledgers' && data?.account_options?.length > 0 && (
          <label className="reports-account-filter">
            <span>Account</span>
            <select value={accountCode} onChange={(event) => { setLoading(true); setError(''); setData(null); setAccountCode(event.target.value); }}>
              <option value="">Choose an account</option>
              {data.account_options.map((account) => <option value={account.code} key={account.code}>{account.code} · {account.name}</option>)}
            </select>
          </label>
        )}
        {data?.period && <span className="reports-period-chip"><i className="bx bx-calendar" /> {formatDate(data.period.from)} – {formatDate(data.period.to)}</span>}
      </div>

      {error && <div className="alert alert-danger py-2" role="alert">{error}</div>}
      {loading ? (
        <div className="reports-loading"><span className="spinner-border spinner-border-sm" role="status" /> Preparing report…</div>
      ) : data ? (
        <>
          {Object.keys(data.summary || {}).length > 0 && (
            <div className="reports-summary-grid">
              {Object.entries(data.summary).map(([key, value]) => (
                <article className="reports-summary-card" key={key}>
                  <span>{key.replaceAll('_', ' ')}</span>
                  <strong>{displayValue(value, key)}</strong>
                </article>
              ))}
            </div>
          )}
          {data.notes?.map((note) => <p className="reports-note" key={note}><i className="bx bx-info-circle" /> {note}</p>)}
          <section className="reports-data-card">
            <div className="reports-data-heading">
              <div><h2>{data.title}</h2><span>{data.rows.length.toLocaleString('en-IN')} row(s)</span></div>
            </div>
            {data.rows.length === 0 ? (
              <div className="reports-empty"><i className="bx bx-bar-chart-alt-2" /><strong>No records for this period</strong><span>Try a different date range or add records to this business.</span></div>
            ) : (
              <div className="table-responsive reports-table-wrap">
                <table className="business-table reports-table">
                  <thead><tr>{data.columns.map((column) => <th className={column.numeric ? 'text-end' : ''} key={column.key}>{column.label}</th>)}</tr></thead>
                  <tbody>{data.rows.map((row, index) => (
                    <tr key={`${row.reference || row.code || row.date || row.product || 'row'}-${index}`}>
                      {data.columns.map((column) => <td className={column.numeric ? 'text-end' : ''} key={column.key}>{displayValue(row[column.key], column.key)}</td>)}
                    </tr>
                  ))}</tbody>
                </table>
              </div>
            )}
          </section>
        </>
      ) : null}
    </section>
  );
}

export default function ReportsPage() {
  const { reportId } = useParams();
  return reportId ? <ReportDetail key={reportId} reportId={reportId} /> : <ReportCatalog />;
}
