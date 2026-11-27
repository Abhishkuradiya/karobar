import { useState } from 'react';
import api from '../api/axios';
import { indiaStates } from '../utils/indiaStates';
import { getErrorMessage } from '../utils/format';
import MessageBanner from './MessageBanner';

const msmeTypes = [
  { value: 'not_known', label: 'Not MSME / not known' },
  { value: 'micro', label: 'Micro' },
  { value: 'small', label: 'Small' },
  { value: 'medium', label: 'Medium' },
];

function createForm(supplier) {
  return {
    name: supplier?.name ?? '',
    mobile: supplier?.mobile ?? '',
    credit_days: supplier?.credit_days ?? 0,
    msme_type: supplier?.msme_type ?? 'not_known',
    udyam_number: supplier?.udyam_number ?? '',
    gstin: supplier?.gstin ?? '',
    state: supplier?.state ?? '',
    email: supplier?.email ?? '',
    address: supplier?.address ?? '',
  };
}

export default function SupplierFormDrawer({ supplier, onClose, onSaved }) {
  const [form, setForm] = useState(() => createForm(supplier));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const msmeCreditDays = ['micro', 'small'].includes(form.msme_type);

  const updateField = (event) => {
    const { name, value } = event.target;
    setForm((current) => ({
      ...current,
      [name]: ['gstin', 'udyam_number'].includes(name) ? value.toUpperCase() : value,
    }));
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
      udyam_number: form.udyam_number.trim().toUpperCase() || null,
      gstin: form.gstin.trim().toUpperCase() || null,
      state: form.state || null,
      email: form.email.trim() || null,
      address: form.address.trim() || null,
    };

    try {
      const response = supplier
        ? await api.put(`/suppliers/${supplier.id}`, payload)
        : await api.post('/suppliers', payload);
      onSaved(response.data);
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not save this supplier.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="drawer-backdrop" onMouseDown={(event) => {
      if (event.target === event.currentTarget) onClose();
    }}>
      <aside className="customer-drawer" role="dialog" aria-modal="true" aria-labelledby="supplier-drawer-title">
        <form className="customer-drawer-form" onSubmit={submit}>
          <div className="drawer-heading">
            <div>
              <span className="eyebrow">{supplier ? 'SUPPLIER DETAILS' : 'NEW SUPPLIER'}</span>
              <h2 id="supplier-drawer-title">{supplier ? 'Edit supplier' : 'New supplier'}</h2>
              <p>Only the name is required. GSTIN is used to classify GST on purchases.</p>
            </div>
            <button type="button" className="icon-button" onClick={onClose} aria-label="Close supplier form">
              <i className="bx bx-x" />
            </button>
          </div>

          <MessageBanner message={error} type="error" onDismiss={() => setError('')} />

          <div className="drawer-fields">
            <label className="field-label">
              <span>Name <span className="required-mark">*</span></span>
              <input autoFocus name="name" value={form.name} onChange={updateField} maxLength="150" required placeholder="e.g. Gupta Suppliers" />
            </label>

            <div className="form-grid-two">
              <label className="field-label">
                Mobile
                <input name="mobile" type="tel" inputMode="numeric" pattern="[0-9]{10}" value={form.mobile} onChange={updateField} maxLength="10" placeholder="10-digit mobile number" />
              </label>
              <label className="field-label">
                Credit days
                <input
                  name="credit_days"
                  type="number"
                  min="0"
                  max={msmeCreditDays ? '45' : '3650'}
                  step="1"
                  value={form.credit_days}
                  onChange={updateField}
                  required
                />
              </label>
            </div>

            <div className="form-grid-two">
              <label className="field-label">
                MSME (Udyam)
                <select name="msme_type" value={form.msme_type} onChange={updateField} required>
                  {msmeTypes.map((type) => <option key={type.value} value={type.value}>{type.label}</option>)}
                </select>
              </label>
              <label className="field-label">
                Udyam number
                <input
                  name="udyam_number"
                  value={form.udyam_number}
                  onChange={updateField}
                  maxLength="30"
                  placeholder="UDYAM-MH-12-0012345"
                />
              </label>
            </div>
            <small className="supplier-terms-note">
              Pay micro and small suppliers within 45 days (or the agreed term, max 45); later payments may not be deductible until paid (Sec 43B(h)).
            </small>

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
              {saving ? 'Saving…' : supplier ? 'Save changes' : 'Add supplier'}
            </button>
          </div>
        </form>
      </aside>
    </div>
  );
}
