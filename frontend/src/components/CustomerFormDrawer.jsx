import { useState } from 'react';
import api from '../api/axios';
import { indiaStates } from '../utils/indiaStates';
import { getErrorMessage } from '../utils/format';
import MessageBanner from './MessageBanner';

function createForm(customer) {
  return {
    name: customer?.name ?? '',
    mobile: customer?.mobile ?? '',
    credit_days: customer?.credit_days ?? 0,
    credit_limit: customer?.credit_limit ?? '',
    gstin: customer?.gstin ?? '',
    state: customer?.state ?? '',
    email: customer?.email ?? '',
    address: customer?.address ?? '',
  };
}

export default function CustomerFormDrawer({ customer, onClose, onSaved }) {
  const [form, setForm] = useState(() => createForm(customer));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');

  const updateField = (event) => {
    const { name, value } = event.target;
    setForm((current) => ({ ...current, [name]: value }));
  };

  const submit = async (event) => {
    event.preventDefault();
    setError('');
    setSaving(true);

    const payload = {
      ...form,
      name: form.name.trim(),
      mobile: form.mobile.trim() || null,
      credit_days: Number(form.credit_days),
      credit_limit: form.credit_limit === '' ? null : Number(form.credit_limit),
      gstin: form.gstin.trim().toUpperCase() || null,
      state: form.state || null,
      email: form.email.trim() || null,
      address: form.address.trim() || null,
    };

    try {
      const response = customer
        ? await api.put(`/customers/${customer.id}`, payload)
        : await api.post('/customers', payload);
      onSaved(response.data);
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="drawer-backdrop" onMouseDown={(event) => {
      if (event.target === event.currentTarget) onClose();
    }}>
      <aside className="customer-drawer" role="dialog" aria-modal="true" aria-labelledby="customer-drawer-title">
        <form className="customer-drawer-form" onSubmit={submit}>
          <div className="drawer-heading">
            <div>
              <span className="eyebrow">{customer ? 'CUSTOMER DETAILS' : 'NEW CONTACT'}</span>
              <h2 id="customer-drawer-title">{customer ? 'Edit customer' : 'New customer'}</h2>
              <p>Only the name is required. GSTIN is used to classify GST on sales.</p>
            </div>
            <button type="button" className="icon-button" onClick={onClose} aria-label="Close customer form">
              <i className="bx bx-x" />
            </button>
          </div>

          <MessageBanner message={error} type="error" onDismiss={() => setError('')} />

          <div className="drawer-fields">
            <label className="field-label">
              <span>Name <span className="required-mark">*</span></span>
              <input autoFocus name="name" value={form.name} onChange={updateField} maxLength="150" required placeholder="e.g. Sharma Traders" />
            </label>

            <div className="form-grid-two">
              <label className="field-label">
                Mobile
                <input name="mobile" type="tel" inputMode="numeric" pattern="[0-9]{10}" value={form.mobile} onChange={updateField} maxLength="10" placeholder="10-digit mobile number" />
              </label>
              <label className="field-label">
                Credit days
                <input name="credit_days" type="number" min="0" max="3650" step="1" value={form.credit_days} onChange={updateField} required />
              </label>
            </div>

            <label className="field-label">
              <span>Credit limit <span className="currency-label">₹</span></span>
              <input name="credit_limit" type="number" min="0" step="0.01" value={form.credit_limit} onChange={updateField} placeholder="No limit" />
              <small>Credit sales above this limit will be declined.</small>
            </label>

            <label className="field-label">
              GSTIN
              <input name="gstin" value={form.gstin} onChange={updateField} maxLength="15" autoCapitalize="characters" placeholder="15-character GSTIN" />
            </label>

            <label className="field-label">
              State
              <select name="state" value={form.state} onChange={updateField}>
                <option value="">Same as my business / not known</option>
                {indiaStates.map((state) => <option key={state} value={state}>{state}</option>)}
              </select>
            </label>

            <label className="field-label">
              Email
              <input name="email" type="email" value={form.email} onChange={updateField} maxLength="254" placeholder="name@example.com" />
            </label>

            <label className="field-label">
              Address
              <textarea name="address" value={form.address} onChange={updateField} maxLength="2000" rows="3" placeholder="Street, city, PIN code" />
            </label>
          </div>

          <div className="drawer-footer">
            <button type="button" className="btn btn-outline-secondary" onClick={onClose} disabled={saving}>Cancel</button>
            <button type="submit" className="btn btn-primary" disabled={saving}>
              {saving ? 'Saving…' : customer ? 'Save changes' : 'Add customer'}
            </button>
          </div>
        </form>
      </aside>
    </div>
  );
}
