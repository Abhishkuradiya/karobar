import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import SneatLogo from '../components/SneatLogo';
import { useAuth } from '../context/useAuth';
import { indiaStates } from '../utils/indiaStates';
import { getErrorMessage } from '../utils/format';

const languages = [
  { value: 'en', label: 'English' },
  { value: 'hi', label: 'हिन्दी' },
  { value: 'bn', label: 'বাংলা' },
  { value: 'mr', label: 'मराठी' },
  { value: 'te', label: 'తెలుగు' },
  { value: 'ta', label: 'தமிழ்' },
];

const businessTypes = [
  { value: 'retail_shop', label: 'Retail shop' },
  { value: 'wholesale_distribution', label: 'Wholesale / Distribution' },
  { value: 'manufacturing', label: 'Manufacturing' },
  { value: 'services', label: 'Services' },
  { value: 'restaurant_food', label: 'Restaurant / Food' },
  { value: 'other', label: 'Other' },
];

const initialForm = {
  name: '',
  gst_registration_type: '',
  gstin: '',
  gst_return_frequency: '',
  state: '',
  city: '',
  business_type: '',
  language: 'en',
};

export default function BusinessSetupPage() {
  const navigate = useNavigate();
  const { user, businesses, createBusiness } = useAuth();
  const [form, setForm] = useState(initialForm);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const greetingName = user?.name || user?.username || 'there';
  const gstRegistered = ['regular', 'composition'].includes(form.gst_registration_type);
  const regularGst = form.gst_registration_type === 'regular';

  const updateField = (event) => {
    const { name, value } = event.target;

    if (name === 'gst_registration_type') {
      setForm((current) => ({
        ...current,
        gst_registration_type: value,
        gstin: value === 'not_registered' ? '' : current.gstin,
        gst_return_frequency: value === 'regular' ? current.gst_return_frequency || 'monthly' : '',
      }));
      return;
    }

    setForm((current) => ({
      ...current,
      [name]: name === 'gstin' ? value.toUpperCase() : value,
    }));
  };

  const submit = async (event) => {
    event.preventDefault();
    setError('');
    setSaving(true);

    try {
      await createBusiness({
        ...form,
        name: form.name.trim(),
        gstin: gstRegistered ? form.gstin.trim().toUpperCase() : null,
        gst_return_frequency: regularGst ? form.gst_return_frequency : null,
        state: form.state || null,
        city: form.city.trim() || null,
      });
      navigate('/dashboard', { replace: true });
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not create this business.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="business-setup-page">
      <header className="setup-brand">
        <SneatLogo title="Karobar" />
        <span className="business-tagline">Daily books, kept right.</span>
      </header>

      <main className="setup-content">
        <div className="setup-heading">
          <span className="setup-kicker">{businesses.length ? 'Add another business' : 'Add a business'}</span>
          <h1>Namaste, {greetingName}! Let&apos;s set up your books.</h1>
          <p>We&apos;ll create a separate set of books for this business. You can switch businesses or add another one anytime.</p>
        </div>

        <form className="setup-form" onSubmit={submit}>
          <section className="setup-card">
            <fieldset className="setup-fieldset">
              <legend>App language</legend>
              <div className="language-options" role="group" aria-label="App language">
                {languages.map((language) => (
                  <button
                    type="button"
                    key={language.value}
                    className={`setup-pill${form.language === language.value ? ' is-selected' : ''}`}
                    aria-pressed={form.language === language.value}
                    onClick={() => setForm((current) => ({ ...current, language: language.value }))}
                  >
                    {language.label}
                  </button>
                ))}
              </div>
              <small className="setup-hint">Your preferred language is saved with this business.</small>
            </fieldset>

            <label className="field-label">
              <span>Business name <span className="required-mark">*</span></span>
              <input
                autoFocus
                name="name"
                value={form.name}
                onChange={updateField}
                maxLength="150"
                placeholder="e.g. Garg General Store"
                required
              />
            </label>

            <label className="field-label">
              <span>Are you registered under GST? <span className="required-mark">*</span></span>
              <select name="gst_registration_type" value={form.gst_registration_type} onChange={updateField} required>
                <option value="">Select</option>
                <option value="regular">Yes — regular (charges GST, claims ITC)</option>
                <option value="composition">Yes — composition scheme</option>
                <option value="not_registered">No — not registered</option>
              </select>
            </label>

            {regularGst && (
              <label className="field-label">
                <span>How do you file GST returns? <span className="required-mark">*</span></span>
                <select name="gst_return_frequency" value={form.gst_return_frequency} onChange={updateField} required>
                  <option value="monthly">Monthly</option>
                  <option value="quarterly">Quarterly (QRMP, turnover up to ₹5 crore)</option>
                </select>
              </label>
            )}

            {gstRegistered && (
              <label className="field-label">
                <span>GSTIN <span className="required-mark">*</span></span>
                <input
                  name="gstin"
                  value={form.gstin}
                  onChange={updateField}
                  maxLength={15}
                  pattern="[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]"
                  title="Enter a valid 15-character GSTIN."
                  placeholder="15-character GSTIN"
                  autoCapitalize="characters"
                  required
                />
              </label>
            )}

            <div className="setup-location-grid">
              <label className="field-label">
                <span>State <span className="required-mark">*</span></span>
                <select name="state" value={form.state} onChange={updateField} required>
                  <option value="">Select state</option>
                  {indiaStates.map((state) => <option key={state} value={state}>{state}</option>)}
                </select>
              </label>
              <label className="field-label">
                <span>City</span>
                <input name="city" value={form.city} onChange={updateField} maxLength="100" />
              </label>
            </div>

            <fieldset className="setup-fieldset">
              <legend>What kind of business? <span className="required-mark">*</span></legend>
              <div className="business-type-options" role="group" aria-label="Business type">
                {businessTypes.map((type) => (
                  <button
                    type="button"
                    key={type.value}
                    className={`setup-pill${form.business_type === type.value ? ' is-selected' : ''}`}
                    aria-pressed={form.business_type === type.value}
                    onClick={() => setForm((current) => ({ ...current, business_type: type.value }))}
                  >
                    {type.label}
                  </button>
                ))}
              </div>
            </fieldset>
          </section>

          {error && <div className="alert alert-danger py-2" role="alert">{error}</div>}

          <button type="submit" className="btn btn-primary setup-submit" disabled={saving || !form.business_type}>
            {saving ? 'Creating your books…' : 'Create my books'}
          </button>
        </form>
      </main>
    </div>
  );
}
