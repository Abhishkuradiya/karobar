import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import api from '../api/axios';
import MessageBanner from '../components/MessageBanner';
import { formatDate, formatMoney, getErrorMessage } from '../utils/format';
import { useFeedback } from '../context/FeedbackContext';

const emptyMeta = { current_page: 1, last_page: 1, total: 0 };
const MAX_FILE_SIZE = 10 * 1024 * 1024;

function formatFileSize(size) {
  if (size < 1024 * 1024) return `${Math.max(1, Math.round(size / 1024))} KB`;
  return `${(size / (1024 * 1024)).toFixed(1)} MB`;
}

export default function ScanBillsPage() {
  const { confirm } = useFeedback();
  const navigate = useNavigate();
  const photoInput = useRef(null);
  const uploadInput = useRef(null);
  const [bills, setBills] = useState([]);
  const [meta, setMeta] = useState(emptyMeta);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [uploading, setUploading] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [preview, setPreview] = useState(null);

  const fetchBills = useCallback(() => api.get('/scan-bills', {
    params: { page, per_page: 15 },
  }), [page]);

  const applyResponse = (response) => {
    setBills(response.data.data);
    setMeta(response.data.meta);
  };

  const loadBills = useCallback(async () => {
    setLoading(true);
    setError('');
    try {
      applyResponse(await fetchBills());
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not load scanned bills.'));
    } finally {
      setLoading(false);
    }
  }, [fetchBills]);

  useEffect(() => {
    let active = true;
    fetchBills()
      .then((response) => {
        if (active) applyResponse(response);
      })
      .catch((requestError) => {
        if (active) setError(getErrorMessage(requestError, 'Could not load scanned bills.'));
      })
      .finally(() => {
        if (active) setLoading(false);
      });

    return () => { active = false; };
  }, [fetchBills]);

  useEffect(() => () => {
    if (preview?.url) URL.revokeObjectURL(preview.url);
  }, [preview]);

  const uploadFile = async (event) => {
    const file = event.target.files?.[0];
    event.target.value = '';
    if (!file) return;

    if (file.size > MAX_FILE_SIZE) {
      setError('Choose an image or PDF under 10 MB.');
      return;
    }

    setUploading(true);
    setError('');
    setNotice('');
    const payload = new FormData();
    payload.append('file', file);

    try {
      await api.post('/scan-bills', payload, { headers: { 'Content-Type': 'multipart/form-data' } });
      setNotice(`${file.name} was uploaded privately. Review the bill and enter its details to record an expense.`);
      if (page === 1) {
        await loadBills();
      } else {
        setPage(1);
      }
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not upload this bill.'));
    } finally {
      setUploading(false);
    }
  };

  const previewBill = async (bill) => {
    setError('');
    try {
      const response = await api.get(`/scan-bills/${bill.id}/file`, { responseType: 'blob' });
      const url = URL.createObjectURL(response.data);
      setPreview({ bill, url });
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not open this bill.'));
    }
  };

  const deleteBill = async (bill) => {
    const message = bill.status === 'recorded'
      ? `Remove the uploaded file for ${bill.original_name}? Its expense record will remain.`
      : `Delete ${bill.original_name}? This permanently removes the uploaded file.`;
    if (!await confirm({ title: 'Delete scanned bill?', message, confirmLabel: 'Delete bill' })) return;

    setNotice('');
    setError('');
    try {
      await api.delete(`/scan-bills/${bill.id}`);
      setNotice('The uploaded bill file was deleted.');
      await loadBills();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not delete this bill.'));
    }
  };

  const changePage = (nextPage) => {
    setLoading(true);
    setError('');
    setPage(nextPage);
  };

  return (
    <section className="page-stack">
      <div className="page-heading">
        <div>
          <span className="eyebrow">CAPTURE THE PAPERWORK</span>
          <h1>Scan bills</h1>
          <p>Upload or photograph a bill, then review its details before recording an expense.</p>
        </div>
      </div>

      <MessageBanner message={notice} onDismiss={() => setNotice('')} />
      <MessageBanner message={error} type="error" onDismiss={() => setError('')} />

      <section className="scan-upload-card">
        <input ref={photoInput} className="sr-only" type="file" accept="image/jpeg,image/png" capture="environment" onChange={uploadFile} />
        <input ref={uploadInput} className="sr-only" type="file" accept="image/jpeg,image/png,application/pdf,.jpg,.jpeg,.png,.pdf" onChange={uploadFile} />
        <div className="scan-upload-intro">
          <span className="scan-upload-icon"><i className="bx bx-scan" /></span>
          <div>
            <h2>Add a bill</h2>
            <p>Use your camera or choose a clear image or PDF. Maximum file size: 10 MB.</p>
          </div>
        </div>
        <div className="scan-upload-actions">
          <button type="button" className="scan-upload-button is-primary" onClick={() => photoInput.current?.click()} disabled={uploading}>
            <i className={`bx ${uploading ? 'bx-loader-alt' : 'bx-camera'}`} />
            {uploading ? 'Uploading…' : 'Take a photo'}
          </button>
          <button type="button" className="scan-upload-button" onClick={() => uploadInput.current?.click()} disabled={uploading}>
            <i className="bx bx-file" /> Upload photo or PDF
          </button>
        </div>
        <div className="scan-privacy-note">
          <i className="bx bx-lock-alt" />
          <span><strong>Private to your business.</strong> Files are stored on your application server. Details are entered and checked by you before anything is posted; automatic OCR is not configured.</span>
        </div>
      </section>

      <section className="data-card scan-bills-card">
        <div className="data-card-toolbar">
          <div><h2>Your bills</h2><span className="muted-copy">{meta.total} uploaded {meta.total === 1 ? 'bill' : 'bills'}</span></div>
          <span className="scan-storage-label"><i className="bx bx-shield-quarter" /> Business-only files</span>
        </div>

        {loading ? (
          <div className="loading-state"><span className="spinner-border spinner-border-sm text-primary" /> Loading bills…</div>
        ) : bills.length === 0 ? (
          <div className="empty-state scan-empty-state">
            <span className="empty-state-icon"><i className="bx bx-scan" /></span>
            <h3>No bills uploaded yet</h3>
            <p>Nothing is recorded until you review the bill and save its expense details.</p>
          </div>
        ) : (
          <>
            <div className="scan-bill-list">
              {bills.map((bill) => (
                <article className="scan-bill-row" key={bill.id}>
                  <span className={`scan-file-icon${bill.mime_type === 'application/pdf' ? ' is-pdf' : ''}`}>
                    <i className={`bx ${bill.mime_type === 'application/pdf' ? 'bx-file' : 'bx-image'}`} />
                  </span>
                  <div className="scan-bill-copy">
                    <strong title={bill.original_name}>{bill.original_name}</strong>
                    <small>{formatFileSize(bill.file_size)} <span aria-hidden="true">·</span> Uploaded {formatDate(bill.created_at)}</small>
                  </div>
                  <span className={`scan-bill-status${bill.status === 'recorded' ? ' is-recorded' : ''}`}>
                    <i className={`bx ${bill.status === 'recorded' ? 'bx-check-circle' : 'bx-time-five'}`} />
                    {bill.status === 'recorded' ? 'Recorded' : 'Needs review'}
                  </span>
                  {bill.expense && <span className="scan-bill-total">{formatMoney(bill.expense.total)}</span>}
                  <div className="table-actions scan-bill-actions">
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => previewBill(bill)}>Preview</button>
                    {bill.status === 'recorded' && bill.expense ? (
                      <button type="button" className="btn btn-outline-secondary btn-sm" onClick={() => navigate(`/expenses?expense=${bill.expense.id}`)}>View expense</button>
                    ) : (
                      <button type="button" className="btn btn-primary btn-sm" onClick={() => navigate(`/expenses?scanBill=${bill.id}`)}>Enter details</button>
                    )}
                    <button type="button" className="icon-button danger-icon" onClick={() => deleteBill(bill)} aria-label={`Delete ${bill.original_name}`} title="Delete uploaded file">
                      <i className="bx bx-trash" />
                    </button>
                  </div>
                </article>
              ))}
            </div>
            <div className="pagination-bar">
              <span>Page {meta.current_page} of {meta.last_page}</span>
              <div>
                <button type="button" className="btn btn-outline-secondary btn-sm" disabled={page <= 1} onClick={() => changePage(page - 1)}>Previous</button>
                <button type="button" className="btn btn-outline-secondary btn-sm ms-2" disabled={page >= meta.last_page} onClick={() => changePage(page + 1)}>Next</button>
              </div>
            </div>
          </>
        )}
      </section>

      {preview && (
        <div className="scan-preview-backdrop" onMouseDown={(event) => {
          if (event.target === event.currentTarget) setPreview(null);
        }}>
          <section className="scan-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="scan-preview-title">
            <header>
              <div><span className="eyebrow">BILL PREVIEW</span><h2 id="scan-preview-title">{preview.bill.original_name}</h2></div>
              <button type="button" className="icon-button" onClick={() => setPreview(null)} aria-label="Close preview"><i className="bx bx-x" /></button>
            </header>
            <div className="scan-preview-content">
              {preview.bill.mime_type === 'application/pdf'
                ? <iframe title={`Preview of ${preview.bill.original_name}`} src={preview.url} />
                : <img src={preview.url} alt={`Uploaded bill: ${preview.bill.original_name}`} />}
            </div>
          </section>
        </div>
      )}
    </section>
  );
}
