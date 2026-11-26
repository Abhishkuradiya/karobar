import { useEffect, useState } from 'react';
import api from '../api/axios';
import { formatDate, getErrorMessage } from '../utils/format';
import './help-support.css';

const urgencyLabels = {
  low: 'Low',
  normal: 'Normal',
  high: 'High',
  urgent: 'Urgent',
};

function RequestStatus({ status }) {
  const label = status.replaceAll('_', ' ');
  return <span className={`support-status support-status-${status}`}>{label}</span>;
}

export default function HelpSupportPage() {
  const [requests, setRequests] = useState([]);
  const [subject, setSubject] = useState('');
  const [urgency, setUrgency] = useState('normal');
  const [description, setDescription] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  useEffect(() => {
    let active = true;
    api.get('/support-requests')
      .then((response) => {
        if (active) setRequests(response.data.data);
      })
      .catch((requestError) => {
        if (active) setError(getErrorMessage(requestError, 'Could not load your support requests.'));
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => {
      active = false;
    };
  }, []);

  const submitRequest = async (event) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    setNotice('');

    try {
      const response = await api.post('/support-requests', {
        subject: subject.trim(),
        urgency,
        description: description.trim(),
      });
      setRequests((current) => [response.data.data, ...current]);
      setSubject('');
      setUrgency('normal');
      setDescription('');
      setNotice(`Request ${response.data.data.reference} was saved. It will appear in Your requests below.`);
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not submit your support request.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <section className="page-stack support-page">
      <header className="support-page-heading">
        <span className="eyebrow">KAROBAR SUPPORT</span>
        <h1>Help &amp; support</h1>
        <p>Tell us what went wrong. Your request will be saved here so you can keep track of it.</p>
      </header>

      <aside className="support-delivery-note" role="note">
        <i className="bx bx-info-circle" />
        <p>Requests are stored in your Karobar account and shown below. This in-app form does not send email or notify a support agent.</p>
      </aside>

      {error && <div className="alert alert-danger py-2" role="alert">{error}</div>}
      {notice && <div className="alert alert-success py-2" role="status">{notice}</div>}

      <section className="support-request-card" aria-labelledby="support-new-request-title">
        <div className="support-card-heading">
          <div>
            <span className="eyebrow">CONTACT SUPPORT</span>
            <h2 id="support-new-request-title">New request</h2>
          </div>
          <span className="support-heading-icon" aria-hidden="true"><i className="bx bx-message-square-edit" /></span>
        </div>

        <form className="support-request-form" onSubmit={submitRequest}>
          <div className="support-subject-row">
            <label className="support-field">
              <span>Subject</span>
              <input
                type="text"
                maxLength="120"
                required
                value={subject}
                onChange={(event) => setSubject(event.target.value)}
                placeholder="e.g. Invoice total looks incorrect"
              />
            </label>
            <label className="support-field support-urgency-field">
              <span>How urgent?</span>
              <select value={urgency} onChange={(event) => setUrgency(event.target.value)}>
                {Object.entries(urgencyLabels).map(([value, label]) => <option value={value} key={value}>{label}</option>)}
              </select>
            </label>
          </div>

          <label className="support-field">
            <span>What happened?</span>
            <textarea
              minLength="10"
              maxLength="5000"
              required
              rows="5"
              value={description}
              onChange={(event) => setDescription(event.target.value)}
              placeholder="Include the page, steps you took, and what you expected to happen. Do not include passwords, OTPs, or access tokens."
            />
            <small>{description.length}/5000 characters</small>
          </label>

          <div className="support-form-footer">
            <span><i className="bx bx-lock-alt" /> Only you can view your submitted requests in this account.</span>
            <button type="submit" className="btn btn-primary" disabled={saving}>
              {saving ? 'Saving request…' : 'Send request'}
              {!saving && <i className="bx bx-right-arrow-alt ms-1" />}
            </button>
          </div>
        </form>
      </section>

      <section className="support-history-section" aria-labelledby="support-requests-title">
        <div className="support-history-heading">
          <div>
            <span className="eyebrow">REQUEST HISTORY</span>
            <h2 id="support-requests-title">Your requests</h2>
          </div>
          {!loading && <span className="support-request-count">{requests.length} request{requests.length === 1 ? '' : 's'}</span>}
        </div>

        {loading ? (
          <div className="support-history-state"><span className="spinner-border spinner-border-sm" role="status" /> Loading your requests…</div>
        ) : requests.length === 0 ? (
          <div className="support-history-empty">
            <span className="support-empty-icon"><i className="bx bx-inbox" /></span>
            <strong>No requests yet</strong>
            <span>Requests you submit will be listed here.</span>
          </div>
        ) : (
          <div className="support-request-list">
            {requests.map((request) => (
              <article className="support-request-item" key={request.id}>
                <span className="support-request-item-icon"><i className="bx bx-message-detail" /></span>
                <div className="support-request-item-copy">
                  <div className="support-request-item-title">
                    <h3>{request.subject}</h3>
                    <RequestStatus status={request.status} />
                  </div>
                  <p>{request.description}</p>
                  <div className="support-request-meta">
                    <span>{request.reference}</span>
                    <span>{request.business}</span>
                    <span>{formatDate(request.created_at)}</span>
                    <span className={`support-urgency support-urgency-${request.urgency}`}>{urgencyLabels[request.urgency] || request.urgency}</span>
                  </div>
                </div>
              </article>
            ))}
          </div>
        )}
      </section>
    </section>
  );
}
