import { useEffect, useMemo, useState } from 'react';
import api from '../api/axios';
import { formatMoney, getErrorMessage } from '../utils/format';
import '../pages/accounting-pages.css';

const labels = { assets: 'Assets', liabilities: 'Liabilities', equity: 'Capital' };

export default function OpeningBalancesPage() {
  const [data, setData] = useState(null);
  const [values, setValues] = useState({});
  const [sides, setSides] = useState({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const load = async () => {
    setLoading(true); setError('');
    try {
      const response = await api.get('/opening-balances');
      setData(response.data);
      const nextValues = {}; const nextSides = {};
      Object.values(response.data.accounts || {}).flat().forEach((account) => {
        nextValues[account.account_code] = account.opening_amount || '';
        nextSides[account.account_code] = account.normal_balance;
      });
      setValues(nextValues); setSides(nextSides);
    } catch (requestError) { setError(getErrorMessage(requestError, 'Could not load opening balances.')); }
    finally { setLoading(false); }
  };
  useEffect(() => { load(); }, []);

  const rows = useMemo(() => Object.values(data?.accounts || {}).flat(), [data]);
  const totals = useMemo(() => rows.reduce((sum, account) => {
    const amount = Number(values[account.account_code] || 0);
    return { debit: sum.debit + (sides[account.account_code] === 'debit' ? amount : 0), credit: sum.credit + (sides[account.account_code] === 'credit' ? amount : 0) };
  }, { debit: 0, credit: 0 }), [rows, sides, values]);

  const save = async (event) => {
    event.preventDefault(); setSaving(true); setError(''); setNotice('');
    try {
      await api.put('/opening-balances', { opening_date: data.opening_date, balances: rows.map((account) => ({ account_code: account.account_code, amount: Number(values[account.account_code] || 0), side: sides[account.account_code] })) });
      setNotice('Opening balances saved successfully.'); await load();
    } catch (requestError) { setError(getErrorMessage(requestError, 'Could not save opening balances.')); }
    finally { setSaving(false); }
  };

  return <section className="page-stack opening-balances-page">
    <div className="page-heading accounting-page-heading"><div><span className="eyebrow">ACCOUNTING SETUP</span><h1>Opening balances</h1><p>Balances as on {data?.opening_date || 'the start date'}, the day your books start in Karobar.</p></div></div>
    {notice && <div className="alert alert-success" role="status">{notice}</div>}{error && <div className="alert alert-danger" role="alert">{error}</div>}
    <div className="opening-balance-note"><i className="bx bx-info-circle" /><span>Enter what each account held on the start date. Any difference is recorded in Opening Balance Equity. Customer and supplier balances should be entered on their respective party pages.</span></div>
    {loading ? <div className="data-card loading-state">Loading opening balances…</div> : <form onSubmit={save}>
      {['assets', 'liabilities', 'equity'].map((type) => <section className="data-card opening-balance-section" key={type}><div className="opening-section-heading"><div><span className="eyebrow">{labels[type]}</span><p>{type === 'assets' ? 'What the business owns — cash, bank, stock and recoverables' : type === 'liabilities' ? 'What the business owes — suppliers, loans and taxes' : "Owner's capital and past profits"}</p></div></div>{(data.accounts[type] || []).map((account) => <div className="opening-balance-row" key={account.account_code}><div><strong>{account.name}</strong><small>{account.display_code} · {account.group_name}</small></div><input aria-label={`${account.name} amount`} min="0" step="0.01" type="number" value={values[account.account_code] ?? ''} onChange={(event) => setValues((current) => ({ ...current, [account.account_code]: event.target.value }))} placeholder="0.00" /><select aria-label={`${account.name} side`} value={sides[account.account_code]} onChange={(event) => setSides((current) => ({ ...current, [account.account_code]: event.target.value }))}><option value="debit">Dr</option><option value="credit">Cr</option></select></div>)}</section>)}
      <div className="opening-balance-footer"><span>Debits <strong>{formatMoney(totals.debit)}</strong></span><span>Credits <strong>{formatMoney(totals.credit)}</strong></span><span>Difference <strong className={Math.abs(totals.debit - totals.credit) > 0.005 ? 'text-warning' : 'text-success'}>{formatMoney(Math.abs(totals.debit - totals.credit))}</strong></span><button className="btn btn-primary" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save opening balances'}</button></div>
    </form>}
  </section>;
}
