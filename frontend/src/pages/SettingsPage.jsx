import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import api from '../api/axios';
import { useAuth } from '../context/useAuth';
import { getErrorMessage } from '../utils/format';
import './settings.css';

const notificationOptions = [
  {
    key: 'invoice_payment_reminders',
    title: 'Invoice payment reminders',
    description: 'Invoices that are due or overdue.',
  },
  {
    key: 'customer_outstanding',
    title: 'Customer outstanding',
    description: 'Unpaid customer invoices that are not yet due.',
  },
  {
    key: 'supplier_payment_reminders',
    title: 'Supplier payment reminders',
    description: 'Purchase payments due today or overdue.',
  },
  {
    key: 'low_stock',
    title: 'Low stock',
    description: 'Tracked products at or below their reorder level.',
  },
  {
    key: 'expiring_inventory',
    title: 'Expiring inventory',
    description: 'Inventory batches that are expired or expiring within 30 days.',
  },
];

const defaultPreferences = Object.fromEntries(notificationOptions.map(({ key }) => [key, true]));

const emptyPasswordForm = {
  current_password: '',
  password: '',
  password_confirmation: '',
};

function businessFormFrom(business) {
  return {
    name: business?.name || '',
    legal_name: business?.legal_name || '',
    gst_registration_type: business?.gst_registration_type || 'not_registered',
    gstin: business?.gstin || '',
    gst_return_frequency: business?.gst_return_frequency || 'monthly',
    business_type: business?.business_type || 'other',
    language: business?.language || 'en',
    state: business?.state || '',
    city: business?.city || '',
    address: business?.address || '',
    postal_code: business?.postal_code || '',
    phone: business?.phone || '',
  };
}

export default function SettingsPage() {
  const { user, activeBusiness, updateBusinessProfile, updateProfile } = useAuth();
  const [searchParams, setSearchParams] = useSearchParams();
  const requestedTab = searchParams.get('tab');
  const activeTab = ['business', 'account', 'notifications'].includes(requestedTab) ? requestedTab : 'business';
  const [businessForm, setBusinessForm] = useState(() => businessFormFrom(activeBusiness));
  const [accountForm, setAccountForm] = useState({ name: user?.name || '', email: user?.email || '' });
  const [passwordForm, setPasswordForm] = useState(emptyPasswordForm);
  const [preferences, setPreferences] = useState(defaultPreferences);
  const [loadingPreferences, setLoadingPreferences] = useState(true);
  const [savingBusiness, setSavingBusiness] = useState(false);
  const [savingAccount, setSavingAccount] = useState(false);
  const [savingPassword, setSavingPassword] = useState(false);
  const [savingPreferences, setSavingPreferences] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  useEffect(() => {
    let active = true;
    api.get('/notification-preferences')
      .then((response) => {
        if (active) setPreferences({ ...defaultPreferences, ...response.data.preferences });
      })
      .catch((requestError) => {
        if (active) setError(getErrorMessage(requestError, 'Could not load notification preferences.'));
      })
      .finally(() => {
        if (active) setLoadingPreferences(false);
      });

    return () => { active = false; };
  }, []);

  const clearAlerts = () => {
    setError('');
    setNotice('');
  };

  const saveBusiness = async (event) => {
    event.preventDefault();
    clearAlerts();
    setSavingBusiness(true);
    const payload = {
      ...businessForm,
      legal_name: businessForm.legal_name || null,
      gstin: businessForm.gst_registration_type === 'not_registered' ? null : businessForm.gstin.trim().toUpperCase(),
      gst_return_frequency: businessForm.gst_registration_type === 'regular' ? businessForm.gst_return_frequency : null,
      city: businessForm.city || null,
      address: businessForm.address || null,
      postal_code: businessForm.postal_code || null,
      phone: businessForm.phone || null,
    };

    try {
      const business = await updateBusinessProfile(payload);
      setBusinessForm(businessFormFrom(business));
      setNotice('Business profile saved.');
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not save the business profile.'));
    } finally {
      setSavingBusiness(false);
    }
  };

  const saveAccount = async (event) => {
    event.preventDefault();
    clearAlerts();
    setSavingAccount(true);
    try {
      await updateProfile(accountForm);
      setNotice('Account profile saved.');
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not save the account profile.'));
    } finally {
      setSavingAccount(false);
    }
  };

  const savePassword = async (event) => {
    event.preventDefault();
    clearAlerts();
    setSavingPassword(true);
    try {
      await api.patch('/account/password', passwordForm);
      setPasswordForm(emptyPasswordForm);
      setNotice('Password changed successfully.');
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not change the password.'));
    } finally {
      setSavingPassword(false);
    }
  };

  const savePreferences = async (event) => {
    event.preventDefault();
    clearAlerts();
    setSavingPreferences(true);
    try {
      const response = await api.put('/notification-preferences', { preferences });
      setPreferences({ ...defaultPreferences, ...response.data.preferences });
      setNotice('Notification preferences saved for your account.');
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not save notification preferences.'));
    } finally {
      setSavingPreferences(false);
    }
  };

  const changeBusinessField = (event) => {
    const { name, value } = event.target;
    setBusinessForm((current) => {
      const next = { ...current, [name]: value };
      if (name === 'gst_registration_type') {
        if (value === 'not_registered') next.gstin = '';
        if (value !== 'regular') next.gst_return_frequency = 'monthly';
      }

      return next;
    });
  };

  return (
    <section className="page-stack settings-page">
      <header className="settings-heading">
        <span className="eyebrow">PREFERENCES & PROFILE</span>
        <h1>Settings</h1>
        <p>Manage your business details, sign-in profile, and in-app notifications.</p>
      </header>

      {(error || notice) && (
        <div className={`settings-alert ${error ? 'is-error' : 'is-success'}`} role={error ? 'alert' : 'status'}>
          <span>{error || notice}</span>
          <button type="button" aria-label="Dismiss message" onClick={() => { setError(''); setNotice(''); }}>
            <i className="bx bx-x" />
          </button>
        </div>
      )}

      <nav className="settings-tabs" aria-label="Settings sections">
        {[
          ['business', 'Business', 'bx-buildings'],
          ['account', 'Account & security', 'bx-user'],
          ['notifications', 'Notifications', 'bx-bell'],
        ].map(([tab, label, icon]) => (
          <button
            type="button"
            className={activeTab === tab ? 'is-active' : ''}
            key={tab}
            aria-current={activeTab === tab ? 'page' : undefined}
            onClick={() => { clearAlerts(); setSearchParams({ tab }); }}
          >
            <i className={`bx ${icon}`} />
            {label}
          </button>
        ))}
      </nav>

      {activeTab === 'business' && (
        <form className="settings-form" onSubmit={saveBusiness}>
          <div className="settings-section-heading">
            <div>
              <span className="eyebrow">ACTIVE BUSINESS</span>
              <h2>Business profile</h2>
              <p>These details are kept with the selected business.</p>
            </div>
            <span className="settings-business-chip"><i className="bx bx-briefcase-alt-2" />{activeBusiness?.name}</span>
          </div>

          <section className="settings-card">
            <h3>Business details</h3>
            <div className="settings-field-grid">
              <label className="settings-field">
                <span>Business name <b>*</b></span>
                <input required name="name" maxLength="150" value={businessForm.name} onChange={changeBusinessField} />
              </label>
              <label className="settings-field">
                <span>Legal name</span>
                <input name="legal_name" maxLength="150" value={businessForm.legal_name} onChange={changeBusinessField} />
              </label>
              <label className="settings-field settings-field-wide">
                <span>Address</span>
                <input name="address" maxLength="255" value={businessForm.address} onChange={changeBusinessField} placeholder="Street address" />
              </label>
              <label className="settings-field">
                <span>City</span>
                <input name="city" maxLength="100" value={businessForm.city} onChange={changeBusinessField} />
              </label>
              <label className="settings-field">
                <span>State <b>*</b></span>
                <input required name="state" maxLength="100" value={businessForm.state} onChange={changeBusinessField} />
              </label>
              <label className="settings-field">
                <span>PIN code</span>
                <input inputMode="numeric" name="postal_code" maxLength="6" pattern="[0-9]{6}" value={businessForm.postal_code} onChange={changeBusinessField} />
                <small>Enter a six-digit PIN code.</small>
              </label>
              <label className="settings-field">
                <span>Business phone</span>
                <input type="tel" name="phone" maxLength="25" value={businessForm.phone} onChange={changeBusinessField} />
              </label>
              <label className="settings-field">
                <span>Business type <b>*</b></span>
                <select required name="business_type" value={businessForm.business_type} onChange={changeBusinessField}>
                  <option value="retail_shop">Retail shop</option>
                  <option value="wholesale_distribution">Wholesale / distribution</option>
                  <option value="manufacturing">Manufacturing</option>
                  <option value="services">Services</option>
                  <option value="restaurant_food">Restaurant / food</option>
                  <option value="other">Other</option>
                </select>
              </label>
              <label className="settings-field">
                <span>Language</span>
                <select name="language" value={businessForm.language} onChange={changeBusinessField}>
                  <option value="en">English</option>
                  <option value="hi">Hindi</option>
                  <option value="bn">Bengali</option>
                  <option value="mr">Marathi</option>
                  <option value="te">Telugu</option>
                  <option value="ta">Tamil</option>
                </select>
              </label>
            </div>
          </section>

          <section className="settings-card">
            <h3>GST details</h3>
            <div className="settings-field-grid">
              <label className="settings-field">
                <span>GST registration</span>
                <select name="gst_registration_type" value={businessForm.gst_registration_type} onChange={changeBusinessField}>
                  <option value="not_registered">Not registered</option>
                  <option value="regular">Regular</option>
                  <option value="composition">Composition</option>
                </select>
              </label>
              {businessForm.gst_registration_type !== 'not_registered' && (
                <label className="settings-field">
                  <span>GSTIN <b>*</b></span>
                  <input required name="gstin" maxLength="15" value={businessForm.gstin} onChange={changeBusinessField} />
                </label>
              )}
              {businessForm.gst_registration_type === 'regular' && (
                <label className="settings-field">
                  <span>GST return filing</span>
                  <select name="gst_return_frequency" value={businessForm.gst_return_frequency} onChange={changeBusinessField}>
                    <option value="monthly">Monthly</option>
                    <option value="quarterly">Quarterly</option>
                  </select>
                </label>
              )}
            </div>
          </section>

          <div className="settings-form-actions">
            <button type="submit" className="btn btn-primary" disabled={savingBusiness}>
              {savingBusiness ? 'Saving…' : 'Save business profile'}
            </button>
          </div>
        </form>
      )}

      {activeTab === 'account' && (
        <div className="settings-form">
          <section className="settings-card">
            <div className="settings-section-heading">
              <div><span className="eyebrow">YOUR SIGN-IN</span><h2>Account profile</h2><p>Update the name and email address associated with your account.</p></div>
            </div>
            <form onSubmit={saveAccount}>
              <div className="settings-field-grid">
                <label className="settings-field">
                  <span>Name <b>*</b></span>
                  <input required maxLength="100" value={accountForm.name} onChange={(event) => setAccountForm((current) => ({ ...current, name: event.target.value }))} />
                </label>
                <label className="settings-field">
                  <span>Email address <b>*</b></span>
                  <input required type="email" maxLength="255" value={accountForm.email} onChange={(event) => setAccountForm((current) => ({ ...current, email: event.target.value }))} />
                </label>
              </div>
              <div className="settings-form-actions">
                <button type="submit" className="btn btn-primary" disabled={savingAccount}>{savingAccount ? 'Saving…' : 'Save account profile'}</button>
              </div>
            </form>
          </section>

          <section className="settings-card">
            <div className="settings-section-heading">
              <div><span className="eyebrow">PASSWORD</span><h2>Change password</h2><p>Confirm your current password before choosing a new one.</p></div>
            </div>
            <form onSubmit={savePassword}>
              <div className="settings-field-grid">
                <label className="settings-field settings-field-wide">
                  <span>Current password <b>*</b></span>
                  <input required type="password" autoComplete="current-password" value={passwordForm.current_password} onChange={(event) => setPasswordForm((current) => ({ ...current, current_password: event.target.value }))} />
                </label>
                <label className="settings-field">
                  <span>New password <b>*</b></span>
                  <input required type="password" minLength="8" autoComplete="new-password" value={passwordForm.password} onChange={(event) => setPasswordForm((current) => ({ ...current, password: event.target.value }))} />
                  <small>Use at least eight characters.</small>
                </label>
                <label className="settings-field">
                  <span>Confirm new password <b>*</b></span>
                  <input required type="password" minLength="8" autoComplete="new-password" value={passwordForm.password_confirmation} onChange={(event) => setPasswordForm((current) => ({ ...current, password_confirmation: event.target.value }))} />
                </label>
              </div>
              <div className="settings-form-actions">
                <button type="submit" className="btn btn-primary" disabled={savingPassword}>{savingPassword ? 'Updating…' : 'Change password'}</button>
              </div>
            </form>
          </section>
        </div>
      )}

      {activeTab === 'notifications' && (
        <form className="settings-form" onSubmit={savePreferences}>
          <div className="settings-section-heading">
            <div><span className="eyebrow">IN-APP ALERTS</span><h2>Notifications</h2><p>Choose which business reminders appear in the header notification menu.</p></div>
          </div>
          <section className="settings-card settings-notification-list">
            {loadingPreferences ? <div className="settings-loading">Loading notification preferences…</div> : notificationOptions.map((option) => (
              <label className="settings-notification-row" key={option.key}>
                <span className="settings-notification-copy">
                  <strong>{option.title}</strong>
                  <small>{option.description}</small>
                </span>
                <input
                  type="checkbox"
                  role="switch"
                  checked={Boolean(preferences[option.key])}
                  onChange={(event) => setPreferences((current) => ({ ...current, [option.key]: event.target.checked }))}
                  aria-label={option.title}
                  disabled={loadingPreferences || savingPreferences}
                />
              </label>
            ))}
          </section>
          <p className="settings-note"><i className="bx bx-info-circle" /> Preferences are saved to your account and apply across your devices. Notifications do not include customer names or amounts.</p>
          <div className="settings-form-actions">
            <button type="submit" className="btn btn-primary" disabled={loadingPreferences || savingPreferences}>
              {savingPreferences ? 'Saving…' : 'Save notification preferences'}
            </button>
          </div>
        </form>
      )}
    </section>
  );
}
