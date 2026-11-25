import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api/axios';
import { useAuth } from '../context/useAuth';
import { formatDate, formatMoney, getErrorMessage } from '../utils/format';

const paymentLabels = {
  cash: 'Cash',
  upi: 'UPI',
  card: 'Card',
  bank: 'Bank',
  credit: 'Credit',
};

export default function Dashboard() {
  const { activeBusiness } = useAuth();

  return <DashboardContent key={activeBusiness?.id} />;
}

function DashboardContent() {
  const [summary, setSummary] = useState(null);
  const [recentSales, setRecentSales] = useState([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  useEffect(() => {
    let active = true;
    Promise.all([
      api.get('/dashboard/summary'),
      api.get('/sales', { params: { per_page: 5 } }),
    ])
      .then(([summaryResponse, salesResponse]) => {
        if (!active) return;
        setSummary(summaryResponse.data);
        setRecentSales(salesResponse.data.data);
      })
      .catch((requestError) => {
        if (active) setError(getErrorMessage(requestError, 'Could not load your business summary.'));
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => { active = false; };
  }, []);

  return (
    <section className="page-stack">
      <div className="page-heading dashboard-heading">
        <div>
          <span className="eyebrow">YOUR BUSINESS AT A GLANCE</span>
          <h1>Dashboard</h1>
          <p>Every sale recorded. Every balance accounted for.</p>
        </div>
        <Link className="btn btn-primary" to="/sales/new"><i className="bx bx-plus me-1" /> Record a sale</Link>
      </div>

      {error && <div className="alert alert-danger py-2" role="alert">{error}</div>}

      <div className="summary-grid dashboard-summary-grid">
        <article className="summary-card">
          <span className="summary-icon summary-icon-violet"><i className="bx bx-trending-up" /></span>
          <div><small>Today's sales</small><strong>{loading ? '—' : formatMoney(summary?.today_sales)}</strong></div>
          <span className="summary-footnote">{loading ? 'Loading…' : `${summary?.today_count ?? 0} recorded today`}</span>
        </article>
        <article className="summary-card">
          <span className="summary-icon summary-icon-amber"><i className="bx bx-time-five" /></span>
          <div><small>Customer receivables</small><strong>{loading ? '—' : formatMoney(summary?.outstanding_balance)}</strong></div>
          <span className="summary-footnote">Outstanding credit sales</span>
        </article>
        <article className="summary-card">
          <span className="summary-icon summary-icon-green"><i className="bx bx-group" /></span>
          <div><small>Active customers</small><strong>{loading ? '—' : summary?.customer_count ?? 0}</strong></div>
          <span className="summary-footnote">Your customer register</span>
        </article>
      </div>

      <div className="dashboard-lower-grid">
        <section className="data-card">
          <div className="data-card-toolbar">
            <div><h2>Recent sales</h2><span className="muted-copy">Latest recorded transactions</span></div>
            <Link className="text-link" to="/sales">View all <i className="bx bx-right-arrow-alt" /></Link>
          </div>
          {loading ? (
            <div className="loading-state"><span className="spinner-border spinner-border-sm text-primary" /> Loading activity…</div>
          ) : recentSales.length === 0 ? (
            <div className="compact-empty-state">
              <i className="bx bx-receipt" />
              <strong>No sales recorded yet</strong>
              <span>Your saved sales will appear here.</span>
            </div>
          ) : (
            <div className="table-responsive">
              <table className="business-table">
                <thead><tr><th>Sale</th><th>Date</th><th>Customer</th><th>Payment</th><th className="text-end">Total</th></tr></thead>
                <tbody>
                  {recentSales.map((sale) => (
                    <tr key={sale.id}>
                      <td><Link className="table-primary-link" to={`/sales/${sale.id}/edit`}>{sale.invoice_number}</Link></td>
                      <td>{formatDate(sale.sale_date)}</td>
                      <td>{sale.customer?.name || sale.customer_name_snapshot}</td>
                      <td><span className={`payment-badge payment-${sale.payment_method}`}>{paymentLabels[sale.payment_method]}</span></td>
                      <td className="text-end fw-semibold">{formatMoney(sale.total)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          )}
        </section>

        <aside className="quick-actions-card">
          <span className="eyebrow">QUICK ACTIONS</span>
          <h2>Keep your books current</h2>
          <p>Capture the transaction while the details are fresh.</p>
          <Link to="/sales/new" className="quick-action-link">
            <span className="quick-action-icon"><i className="bx bx-receipt" /></span>
            <span><strong>Record a sale</strong><small>Cash, UPI, bank or credit</small></span>
            <i className="bx bx-chevron-right" />
          </Link>
          <Link to="/customers" className="quick-action-link">
            <span className="quick-action-icon quick-action-icon-blue"><i className="bx bx-user-plus" /></span>
            <span><strong>Add a customer</strong><small>Save their contact and terms</small></span>
            <i className="bx bx-chevron-right" />
          </Link>
          <div className="books-note"><i className="bx bx-check-shield" /> Every saved sale creates balanced journal entries.</div>
        </aside>
      </div>
    </section>
  );
}
