import { useCallback, useEffect, useMemo, useState } from 'react';
import api from '../api/axios';
import { formatMoney, getErrorMessage } from '../utils/format';
import { useFeedback } from '../context/FeedbackContext';
import MessageBanner from '../components/MessageBanner';
import './inventory-banking-invoices.css';

const emptyAccount = () => ({ name: '', bank_name: '', account_number: '', ifsc: '', upi_id: '' });

export default function BankingPage() {
  const { confirm } = useFeedback();
  const [accounts, setAccounts] = useState([]);
  const [accountId, setAccountId] = useState('');
  const [accountForm, setAccountForm] = useState(emptyAccount());
  const [statementFile, setStatementFile] = useState(null);
  const [statementRows, setStatementRows] = useState([]);
  const [reconciledRows, setReconciledRows] = useState([]);
  const [legacyRows, setLegacyRows] = useState([]);
  const [bookRows, setBookRows] = useState([]);
  const [matches, setMatches] = useState({});
  const [accountModal, setAccountModal] = useState(false);
  const [editingAccount, setEditingAccount] = useState(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  const activeAccount = useMemo(() => accounts.find((item) => String(item.id) === String(accountId)), [accounts, accountId]);

  const loadAccounts = useCallback(async () => {
    const response = await api.get('/banking/accounts');
    setAccounts(response.data.data);
    setAccountId((current) => current && response.data.data.some((item) => String(item.id) === String(current))
      ? current
      : String(response.data.data[0]?.id || ''));
  }, []);

  const loadReconciliation = useCallback(async () => {
    if (!accountId) {
      setStatementRows([]);
      setReconciledRows([]);
      setBookRows([]);
      return;
    }
    const response = await api.get(`/banking/accounts/${accountId}/reconciliation`);
    setStatementRows(response.data.statement_transactions);
    setReconciledRows(response.data.reconciled_transactions);
    setLegacyRows(response.data.legacy_unassigned_entries);
    setBookRows(response.data.book_transactions);
    setMatches((current) => {
      const next = { ...current };
      response.data.statement_transactions.forEach((transaction) => {
        const candidates = response.data.book_transactions.filter((line) => Number(line.amount) === Number(transaction.amount));
        if (!next[transaction.id] && candidates.length === 1) next[transaction.id] = String(candidates[0].id);
      });
      return next;
    });
  }, [accountId]);

  useEffect(() => {
    let active = true;
    api.get('/banking/accounts')
      .then((response) => {
        if (!active) return;
        const items = response.data.data;
        setAccounts(items);
        setAccountId((current) => current && items.some((item) => String(item.id) === String(current))
          ? current
          : String(items[0]?.id || ''));
      })
      .catch((requestError) => { if (active) setError(getErrorMessage(requestError, 'Could not load bank accounts.')); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, []);

  useEffect(() => {
    let active = true;
    if (!accountId) {
      return undefined;
    }
    api.get(`/banking/accounts/${accountId}/reconciliation`)
      .then((response) => {
        if (!active) return;
        setStatementRows(response.data.statement_transactions);
        setReconciledRows(response.data.reconciled_transactions);
        setLegacyRows(response.data.legacy_unassigned_entries);
        setBookRows(response.data.book_transactions);
        setMatches((current) => {
          const next = { ...current };
          response.data.statement_transactions.forEach((transaction) => {
            const candidates = response.data.book_transactions.filter((line) => Number(line.amount) === Number(transaction.amount));
            if (!next[transaction.id] && candidates.length === 1) next[transaction.id] = String(candidates[0].id);
          });
          return next;
        });
      })
      .catch((requestError) => { if (active) setError(getErrorMessage(requestError, 'Could not load reconciliation entries.')); });
    return () => { active = false; };
  }, [accountId]);

  const saveAccount = async (event) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      const response = editingAccount
        ? await api.patch(`/banking/accounts/${editingAccount.id}`, accountForm)
        : await api.post('/banking/accounts', accountForm);
      setAccounts((current) => editingAccount
        ? current.map((item) => item.id === editingAccount.id ? { ...item, ...response.data } : item)
        : [...current, response.data]);
      setAccountId(String(response.data.id));
      setAccountForm(emptyAccount());
      setEditingAccount(null);
      setAccountModal(false);
      setNotice(`Bank account ${response.data.name} ${editingAccount ? 'updated' : 'added'}. Only its last four account digits are shown.`);
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not save this bank account.'));
    } finally {
      setSaving(false);
    }
  };

  const deleteAccount = async (account) => {
    if (!await confirm({ title: 'Delete bank account?', message: `${account.name} will be removed from active banking. Accounts with imported statement history may not be deletable.`, confirmLabel: 'Delete account' })) return;
    setError('');
    try {
      await api.delete(`/banking/accounts/${account.id}`);
      setAccounts((current) => current.filter((item) => item.id !== account.id));
      setAccountId((current) => String(current) === String(account.id) ? '' : current);
      setNotice(`${account.name} was deleted.`);
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not delete this account.'));
    }
  };

  const importStatement = async (event) => {
    event.preventDefault();
    if (!statementFile || !accountId) return;
    setSaving(true);
    setError('');
    setNotice('');
    const payload = new FormData();
    payload.append('statement', statementFile);
    try {
      const response = await api.post(`/banking/accounts/${accountId}/statements`, payload, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      setNotice(`${response.data.imported} transaction(s) imported; ${response.data.duplicates_skipped} duplicate(s) skipped.`);
      setStatementFile(null);
      event.target.reset();
      await loadReconciliation();
      await loadAccounts();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not import this statement.'));
    } finally {
      setSaving(false);
    }
  };

  const matchTransaction = async (transaction) => {
    const journalLineId = matches[transaction.id];
    if (!journalLineId) {
      setError('Choose a book entry to match first.');
      return;
    }
    setSaving(true);
    setError('');
    try {
      await api.post(`/banking/accounts/${accountId}/reconciliation`, {
        statement_transaction_id: transaction.id,
        journal_line_id: Number(journalLineId),
      });
      setNotice('Statement line reconciled.');
      await loadReconciliation();
      await loadAccounts();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not reconcile this line.'));
    } finally {
      setSaving(false);
    }
  };

  const exactBookMatches = (transaction) => bookRows.filter((line) => Number(line.amount) === Number(transaction.amount));

  const unmatchTransaction = async (transaction) => {
    try {
      await api.delete(`/banking/accounts/${accountId}/reconciliation/${transaction.id}`);
      setNotice('Reconciliation undone.');
      await loadReconciliation();
      await loadAccounts();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not undo this reconciliation.'));
    }
  };

  const assignLegacyLine = async (line) => {
    setSaving(true);
    setError('');
    try {
      await api.patch(`/banking/accounts/${accountId}/legacy-lines/${line.id}`);
      setNotice('Older book entry assigned to this bank account.');
      await loadReconciliation();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not assign this book entry.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <section className="page-stack">
      <div className="page-heading">
        <div><span className="eyebrow">CASH & BANK RECONCILIATION</span><h1>Banking</h1><p>Import statements and match each bank transaction to your books.</p></div>
        <button className="btn btn-primary" type="button" onClick={() => { setEditingAccount(null); setAccountForm(emptyAccount()); setAccountModal(true); setError(''); }}><i className="bx bx-plus me-1" /> Add bank account</button>
      </div>
      <MessageBanner message={notice} onDismiss={() => setNotice('')} />
      <MessageBanner message={error} type="error" onDismiss={() => setError('')} />

      <div className="data-card">
        <div className="data-card-toolbar banking-toolbar"><div><h2>Your bank accounts</h2><span className="muted-copy">Full account numbers are stored securely; only the last four digits are shown.</span></div></div>
        {loading ? <div className="loading-state">Loading bank accounts…</div> : accounts.length === 0 ? <div className="empty-state"><span className="empty-state-icon"><i className="bx bx-building-house" /></span><h3>No bank accounts yet</h3><p>Add an account to import a statement and reconcile bank-paid transactions.</p><button className="btn btn-primary" type="button" onClick={() => setAccountModal(true)}>Add a bank account</button></div> : <div className="bank-account-grid">{accounts.map((account) => <article key={account.id} className={`bank-account-card${String(account.id) === String(accountId) ? ' is-selected' : ''}`}><button type="button" className="stretched-link-button" aria-label={`Select ${account.name}`} onClick={() => { setAccountId(String(account.id)); setError(''); }} /><h3>{account.name}</h3><p>{account.bank_name}</p><span className="account-last4">{account.account_last4 ? `•••• ${account.account_last4}` : 'Account number not provided'}</span><div className="account-actions"><button type="button" className="btn btn-sm btn-outline-primary" onClick={() => setAccountId(String(account.id))}>Reconcile <span className="badge text-bg-light ms-1">{account.unreconciled_count || 0}</span></button><button type="button" className="btn btn-sm btn-outline-danger bank-delete-button" aria-label={`Delete ${account.name}`} title="Delete account" onClick={() => deleteAccount(account)}><i className="bx bx-trash me-1" /> Delete</button></div></article>)}</div>}
      </div>

      {activeAccount && <section className="data-card">
        <div className="data-card-toolbar banking-toolbar"><div><h2>Reconcile {activeAccount.name}</h2><span className="muted-copy">{statementRows.length} unmatched statement line(s) · {bookRows.length} unmatched book entry(ies)</span></div>
          <form className="statement-upload" onSubmit={importStatement}><input aria-label="CSV statement file" type="file" accept=".csv,text/csv" onChange={(event) => setStatementFile(event.target.files?.[0] || null)} /><button className="btn btn-outline-primary btn-sm" type="submit" disabled={!statementFile || saving}>{saving ? 'Importing…' : 'Import CSV'}</button><small>CSV columns: Date, Description and Amount, or Debit and Credit. Up to 5 MB / 5,000 rows.</small></form>
        </div>
        <div className="alert alert-light border py-2 small mb-3">Statement credits are deposits; debits are withdrawals. Matches must have the same amount and direction.</div>
        <div className="reconciliation-split">
          <div className="reconciliation-column"><h3>Imported statement</h3>{statementRows.length === 0 ? <div className="p-3 text-secondary small">No unmatched statement transactions.</div> : statementRows.map((transaction) => <div className="reconciliation-row" key={transaction.id}><div><strong>{transaction.description}</strong><small>{transaction.transaction_date}{transaction.reference ? ` · Ref ${transaction.reference}` : ''}</small></div><strong className={Number(transaction.amount) < 0 ? 'text-danger' : 'text-success'}>{formatMoney(transaction.amount)}</strong><select aria-label={`Book entry for ${transaction.description}`} value={matches[transaction.id] || ''} onChange={(event) => setMatches((current) => ({ ...current, [transaction.id]: event.target.value }))}><option value="">Select matching book entry</option>{exactBookMatches(transaction).map((line) => <option value={line.id} key={line.id}>{line.date} · {line.description} · {formatMoney(line.amount)}</option>)}</select><button className="btn btn-sm btn-primary" type="button" disabled={!matches[transaction.id] || saving} onClick={() => matchTransaction(transaction)}>Match</button></div>)}</div>
          <div className="reconciliation-column"><h3>Book entries</h3>{bookRows.length === 0 ? <div className="p-3 text-secondary small">No unmatched bank entries for this account.</div> : bookRows.map((line) => <div className="reconciliation-row" key={line.id}><div><strong>{line.description}</strong><small>{line.date} · {line.source_type}</small></div><strong className={Number(line.amount) < 0 ? 'text-danger' : 'text-success'}>{formatMoney(line.amount)}</strong></div>)}</div>
        </div>
        {legacyRows.length > 0 && <div className="reconciled-history"><h3>Older bank entries without an account</h3><p className="small text-secondary">Choose which bank account owns each older ledger entry before matching it.</p>{legacyRows.map((line) => <div key={line.id}><span><strong>{line.description}</strong><small>{line.date} · {line.source_type}</small></span><strong>{formatMoney(line.amount)}</strong><button className="btn btn-sm btn-outline-primary" type="button" disabled={saving} onClick={() => assignLegacyLine(line)}>Assign here</button></div>)}</div>}
        {reconciledRows.length > 0 && <div className="reconciled-history"><h3>Recently reconciled</h3>{reconciledRows.map((transaction) => <div key={transaction.id}><span><strong>{transaction.description}</strong><small>{transaction.transaction_date} · {transaction.matched_journal_line?.journal_entry?.description || 'Matched book entry'}</small></span><strong>{formatMoney(transaction.amount)}</strong><button className="btn btn-sm btn-outline-secondary" type="button" onClick={() => unmatchTransaction(transaction)}>Undo match</button></div>)}</div>}
      </section>}

      {accountModal && <div className="inventory-modal-backdrop"><section className="inventory-modal inventory-modal-narrow" role="dialog" aria-modal="true" aria-labelledby="bank-account-title"><div className="inventory-modal-header"><div><span className="eyebrow">LINK A LEDGER</span><h2 id="bank-account-title">{editingAccount ? 'Edit bank account' : 'Add bank account'}</h2></div><button className="icon-button" type="button" aria-label="Close" onClick={() => setAccountModal(false)}><i className="bx bx-x" /></button></div><form onSubmit={saveAccount}><div className="inventory-modal-body"><div className="bank-account-form"><label className="inventory-field">Account name <span className="required-mark">*</span><input required maxLength="100" value={accountForm.name} onChange={(event) => setAccountForm((current) => ({ ...current, name: event.target.value }))} placeholder="e.g. Business current account" /></label><label className="inventory-field">Bank name <span className="required-mark">*</span><input required maxLength="100" value={accountForm.bank_name} onChange={(event) => setAccountForm((current) => ({ ...current, bank_name: event.target.value }))} /></label><label className="inventory-field">Account number <span className="required-mark">*</span><input required inputMode="numeric" minLength="4" maxLength="40" value={accountForm.account_number} onChange={(event) => setAccountForm((current) => ({ ...current, account_number: event.target.value }))} placeholder="Full account number; only last four digits are shown" /></label><label className="inventory-field">IFSC <span className="required-mark">*</span><input required maxLength="11" value={accountForm.ifsc} onChange={(event) => setAccountForm((current) => ({ ...current, ifsc: event.target.value.toUpperCase() }))} placeholder="e.g. HDFC0001234" /></label><label className="inventory-field">UPI ID<input maxLength="100" value={accountForm.upi_id} onChange={(event) => setAccountForm((current) => ({ ...current, upi_id: event.target.value }))} placeholder="name@bank (optional)" /></label></div>{editingAccount && <p className="field-hint mb-0">Re-enter the full account number to update it. For security, it is never loaded back into the form.</p>}{error && <div className="alert alert-danger py-2 mt-3">{error}</div>}</div><div className="inventory-modal-footer"><button className="btn btn-outline-secondary" type="button" onClick={() => setAccountModal(false)}>Cancel</button><button className="btn btn-primary" type="submit" disabled={saving}>{saving ? 'Saving…' : editingAccount ? 'Save changes' : 'Save account'}</button></div></form></section></div>}
    </section>
  );
}
