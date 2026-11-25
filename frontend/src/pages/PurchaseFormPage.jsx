import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import SupplierFormDrawer from '../components/SupplierFormDrawer';
import api from '../api/axios';
import { formatMoney, getErrorMessage } from '../utils/format';

const paymentMethods = [
  { value: 'cash', label: 'Cash', icon: 'bx-money' },
  { value: 'upi', label: 'UPI', icon: 'bx-mobile-alt' },
  { value: 'card', label: 'Card', icon: 'bx-credit-card' },
  { value: 'bank', label: 'Bank', icon: 'bx-building-house' },
  { value: 'credit', label: 'Credit', icon: 'bx-time-five' },
];

const makeEmptyItem = () => ({
  product_id: '',
  description: '',
  quantity: '1',
  unit_rate: '',
  gst_rate: '18',
  discount: '0',
  hsn_sac: '',
  batch_number: '',
  expiry_date: '',
});

const today = () => {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

const roundMoney = (amount) => Math.round((amount + Number.EPSILON) * 100) / 100;

function calculateTotals(items, pricesIncludeGst) {
  const totals = { subtotal: 0, discount: 0, taxable: 0, tax: 0, total: 0 };

  items.forEach((item) => {
    const gross = roundMoney((Number(item.quantity) || 0) * (Number(item.unit_rate) || 0));
    const discount = Math.min(gross, Number(item.discount) || 0);
    const afterDiscount = roundMoney(gross - discount);
    const rate = Number(item.gst_rate) || 0;
    const tax = pricesIncludeGst
      ? rate > 0 ? roundMoney(afterDiscount * rate / (100 + rate)) : 0
      : roundMoney(afterDiscount * rate / 100);
    const taxable = pricesIncludeGst ? roundMoney(afterDiscount - tax) : afterDiscount;
    const total = pricesIncludeGst ? afterDiscount : roundMoney(taxable + tax);

    totals.subtotal = roundMoney(totals.subtotal + gross);
    totals.discount = roundMoney(totals.discount + discount);
    totals.taxable = roundMoney(totals.taxable + taxable);
    totals.tax = roundMoney(totals.tax + tax);
    totals.total = roundMoney(totals.total + total);
  });

  return totals;
}

function toFormPurchase(purchase) {
  return {
    supplier_id: purchase.supplier_id ? String(purchase.supplier_id) : '',
    purchase_date: purchase.purchase_date,
    supplier_bill_number: purchase.supplier_bill_number || '',
    supplier_bill_date: purchase.supplier_bill_date || '',
    payment_method: purchase.payment_method,
    bank_account_id: purchase.bank_account_id ? String(purchase.bank_account_id) : '',
    prices_include_gst: purchase.prices_include_gst,
    claim_input_tax_credit: purchase.claim_input_tax_credit,
    reverse_charge: purchase.reverse_charge,
    notes: purchase.notes || '',
    items: purchase.items.map((item) => ({
      product_id: item.product_id ? String(item.product_id) : '',
      description: item.description,
      quantity: item.quantity,
      unit_rate: item.unit_rate,
      gst_rate: item.gst_rate,
      discount: item.discount,
      hsn_sac: item.hsn_sac || '',
      batch_number: item.batch_number || '',
      expiry_date: item.expiry_date || '',
    })),
  };
}

export default function PurchaseFormPage() {
  const { purchaseId } = useParams();
  const navigate = useNavigate();
  const editing = Boolean(purchaseId);
  const [form, setForm] = useState({
    supplier_id: '',
    purchase_date: today(),
    supplier_bill_number: '',
    supplier_bill_date: '',
    payment_method: 'cash',
    bank_account_id: '',
    prices_include_gst: false,
    claim_input_tax_credit: true,
    reverse_charge: false,
    notes: '',
    items: [makeEmptyItem()],
  });
  const [suppliers, setSuppliers] = useState([]);
  const [products, setProducts] = useState([]);
  const [bankAccounts, setBankAccounts] = useState([]);
  const [loading, setLoading] = useState(editing);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [drawerOpen, setDrawerOpen] = useState(false);

  useEffect(() => {
    let active = true;
    const loadFormData = async () => {
      setError('');
      try {
        const [supplierResponse, productResponse, bankResponse, purchaseResponse] = await Promise.all([
          api.get('/suppliers', { params: { per_page: 50 } }),
          api.get('/inventory/products', { params: { per_page: 100 } }),
          api.get('/banking/accounts'),
          editing ? api.get(`/purchases/${purchaseId}`) : Promise.resolve(null),
        ]);
        if (!active) return;
        const availableSuppliers = supplierResponse.data.data;
        setSuppliers(availableSuppliers);
        setProducts(productResponse.data.data);
        setBankAccounts(bankResponse.data.data);
        if (purchaseResponse) {
          const purchaseForm = toFormPurchase(purchaseResponse.data);
          if (purchaseForm.supplier_id && !availableSuppliers.some((supplier) => String(supplier.id) === purchaseForm.supplier_id)) {
            purchaseForm.supplier_id = '';
          }
          setForm(purchaseForm);
        }
      } catch (requestError) {
        if (active) setError(getErrorMessage(requestError, 'Could not load purchase details.'));
      } finally {
        if (active) setLoading(false);
      }
    };

    loadFormData();
    return () => { active = false; };
  }, [editing, purchaseId]);

  const totals = useMemo(
    () => calculateTotals(form.items, form.prices_include_gst),
    [form.items, form.prices_include_gst],
  );
  const supplierAmount = form.reverse_charge ? totals.taxable : totals.total;
  const balanceDue = form.payment_method === 'credit' ? supplierAmount : 0;

  const updateField = (event) => {
    const { name, value, type, checked } = event.target;
    if (name === 'reverse_charge' && checked) {
      setForm((current) => ({ ...current, reverse_charge: true, prices_include_gst: false }));
      return;
    }
    setForm((current) => ({ ...current, [name]: type === 'checkbox' ? checked : value }));
  };

  const updateItem = (index, event) => {
    const { name, value } = event.target;
    setForm((current) => ({
      ...current,
      items: current.items.map((item, itemIndex) => itemIndex === index ? { ...item, [name]: value } : item),
    }));
  };

  const addItem = () => setForm((current) => ({ ...current, items: [...current.items, makeEmptyItem()] }));

  const removeItem = (index) => {
    setForm((current) => ({
      ...current,
      items: current.items.length === 1
        ? current.items
        : current.items.filter((_, itemIndex) => itemIndex !== index),
    }));
  };

  const savePurchase = async (event) => {
    event.preventDefault();
    setError('');
    setSaving(true);
    const payload = {
      supplier_id: form.supplier_id ? Number(form.supplier_id) : null,
      purchase_date: form.purchase_date,
      supplier_bill_number: form.supplier_bill_number.trim() || null,
      supplier_bill_date: form.supplier_bill_date || null,
      payment_method: form.payment_method,
      bank_account_id: form.bank_account_id ? Number(form.bank_account_id) : null,
      prices_include_gst: form.prices_include_gst,
      claim_input_tax_credit: form.claim_input_tax_credit,
      reverse_charge: form.reverse_charge,
      notes: form.notes.trim() || null,
      items: form.items.map((item) => ({
        ...item,
        product_id: item.product_id ? Number(item.product_id) : null,
        description: item.description.trim(),
        quantity: Number(item.quantity),
        unit_rate: Number(item.unit_rate),
        gst_rate: Number(item.gst_rate),
        discount: Number(item.discount),
        hsn_sac: item.hsn_sac.trim() || null,
        batch_number: item.batch_number.trim() || null,
        expiry_date: item.expiry_date || null,
      })),
    };

    try {
      if (editing) await api.put(`/purchases/${purchaseId}`, payload);
      else await api.post('/purchases', payload);
      navigate('/purchases', {
        state: { notice: editing ? 'Purchase updated and books adjusted.' : 'Purchase saved and posted to the books.' },
      });
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not save this purchase.'));
    } finally {
      setSaving(false);
    }
  };

  const supplierSaved = (supplier) => {
    setSuppliers((current) => [supplier, ...current.filter((item) => item.id !== supplier.id)]);
    setForm((current) => ({ ...current, supplier_id: String(supplier.id) }));
    setDrawerOpen(false);
  };

  const selectProduct = (index, productId) => {
    const product = products.find((item) => String(item.id) === productId);
    setForm((current) => ({
      ...current,
      items: current.items.map((item, itemIndex) => itemIndex === index
        ? {
          ...item,
          product_id: productId,
          ...(product ? {
            description: product.name,
            unit_rate: String(product.purchase_price),
            gst_rate: String(product.gst_rate),
            hsn_sac: product.hsn_sac || '',
          } : {}),
        }
        : item),
    }));
  };

  if (loading) {
    return <div className="loading-state"><span className="spinner-border spinner-border-sm text-primary" /> Loading purchase…</div>;
  }

  return (
    <section className="page-stack">
      <div className="breadcrumb-line">
        <Link to="/purchases"><i className="bx bx-chevron-left" /> Purchases</Link>
      </div>
      <div className="page-heading sale-page-heading">
        <div>
          <span className="eyebrow">{editing ? 'UPDATE TRANSACTION' : 'SUPPLIER BILL ENTRY'}</span>
          <h1>{editing ? 'Edit purchase' : 'New purchase'}</h1>
          <p>Record the supplier bill once. Amounts, input GST and payables update automatically.</p>
        </div>
      </div>

      {error && <div className="alert alert-danger py-2" role="alert">{error}</div>}

      <form className="sale-entry-layout" onSubmit={savePurchase}>
        <div className="sale-entry-main">
          <section className="form-panel">
            <div className="customer-select-row">
              <label className="field-label flex-grow-1" htmlFor="purchase-supplier">
                Supplier
                <select id="purchase-supplier" name="supplier_id" value={form.supplier_id} onChange={updateField}>
                  <option value="">No supplier (paid now)</option>
                  {suppliers.map((supplier) => (
                    <option key={supplier.id} value={supplier.id}>{supplier.name}{supplier.mobile ? ` · ${supplier.mobile}` : ''}</option>
                  ))}
                </select>
              </label>
              <button type="button" className="icon-button add-customer-button" onClick={() => setDrawerOpen(true)} aria-label="Add supplier">
                <i className="bx bx-user-plus" />
              </button>
            </div>

            <div className="field-label payment-field">
              <span>Paid by</span>
              <div className="payment-options" role="group" aria-label="Payment method">
                {paymentMethods.map((method) => (
                  <button
                    type="button"
                    key={method.value}
                    className={`payment-option${form.payment_method === method.value ? ' is-selected' : ''}`}
                    aria-pressed={form.payment_method === method.value}
                    onClick={() => setForm((current) => ({ ...current, payment_method: method.value }))}
                  >
                    <i className={`bx ${method.icon}`} />
                    {method.label}
                  </button>
                ))}
              </div>
              {form.payment_method === 'credit' && !form.supplier_id && (
                <small className="field-hint">Choose a saved supplier before recording a credit purchase.</small>
              )}
              {form.payment_method === 'bank' && <label className="field-label bank-account-select">Bank account
                <select required value={form.bank_account_id} onChange={(event) => setForm((current) => ({ ...current, bank_account_id: event.target.value }))}>
                  <option value="">Select a bank account</option>
                  {bankAccounts.map((account) => <option key={account.id} value={account.id}>{account.name} · {account.bank_name}{account.account_last4 ? ` ·••${account.account_last4}` : ''}</option>)}
                </select>
                {bankAccounts.length === 0 && <small className="field-hint">Add a bank account in Banking before choosing bank payment.</small>}
              </label>}
            </div>

            <div className="form-grid-two purchase-date-grid">
              <label className="field-label">
                Date <span className="required-mark">*</span>
                <input type="date" name="purchase_date" value={form.purchase_date} onChange={updateField} required />
              </label>
              <label className="field-label">
                Supplier bill no.
                <input name="supplier_bill_number" value={form.supplier_bill_number} onChange={updateField} maxLength="60" placeholder="Optional" />
              </label>
              <label className="field-label">
                Supplier bill date
                <input type="date" name="supplier_bill_date" value={form.supplier_bill_date} onChange={updateField} />
              </label>
            </div>
          </section>

          <section className="items-section">
            <div className="section-heading-row">
              <div>
                <h2>Items</h2>
                <span className="muted-copy">Add the goods or services on this supplier bill.</span>
              </div>
              <label className={`switch-label${form.reverse_charge ? ' is-disabled' : ''}`}>
                <span>Prices include GST</span>
                <input
                  type="checkbox"
                  name="prices_include_gst"
                  checked={form.prices_include_gst}
                  onChange={updateField}
                  disabled={form.reverse_charge}
                />
                <span className="switch-control" aria-hidden="true" />
              </label>
            </div>

            <div className="sale-items-list">
              {form.items.map((item, index) => (
                <article className="sale-item-card" key={`purchase-item-${index}`}>
                  <label className="field-label inventory-link-field">
                    Product from inventory
                    <select value={item.product_id} onChange={(event) => selectProduct(index, event.target.value)}>
                      <option value="">Free-text item</option>
                      {products.map((product) => <option key={product.id} value={product.id}>{product.name}{product.track_stock ? ` · ${Number(product.current_stock).toLocaleString()} ${product.unit_code || ''} in stock` : ' · service / untracked'}</option>)}
                    </select>
                  </label>
                  <div className="sale-item-description">
                    <label className="sr-only" htmlFor={`purchase-item-description-${index}`}>Item {index + 1} description</label>
                    <input
                      id={`purchase-item-description-${index}`}
                      name="description"
                      value={item.description}
                      onChange={(event) => updateItem(index, event)}
                      maxLength="255"
                      placeholder={`Item ${index + 1} — e.g. Basmati rice 25kg`}
                      required
                    />
                    <button type="button" className="icon-button danger-icon" onClick={() => removeItem(index)} disabled={form.items.length === 1} aria-label={`Remove item ${index + 1}`} title="Remove item">
                      <i className="bx bx-trash" />
                    </button>
                  </div>
                  <div className="item-fields-grid">
                    <label className="field-label">
                      Qty
                      <input name="quantity" type="number" min="0.001" step="0.001" value={item.quantity} onChange={(event) => updateItem(index, event)} required />
                    </label>
                    <label className="field-label">
                      Rate ₹
                      <input name="unit_rate" type="number" min="0" step="0.01" value={item.unit_rate} onChange={(event) => updateItem(index, event)} required />
                    </label>
                    <label className="field-label">
                      GST %
                      <select name="gst_rate" value={item.gst_rate} onChange={(event) => updateItem(index, event)}>
                        {[0, 0.25, 3, 5, 12, 18, 28, 40].map((rate) => <option key={rate} value={rate}>{rate}%</option>)}
                      </select>
                    </label>
                    {item.product_id && products.find((product) => String(product.id) === item.product_id)?.batch_tracking && <>
                      <label className="field-label">Batch number<input name="batch_number" value={item.batch_number} onChange={(event) => updateItem(index, event)} maxLength="80" placeholder="Optional" /></label>
                      <label className="field-label">Expiry date<input type="date" name="expiry_date" value={item.expiry_date} onChange={(event) => updateItem(index, event)} /></label>
                    </>}
                    <label className="field-label">
                      Discount ₹
                      <input name="discount" type="number" min="0" step="0.01" value={item.discount} onChange={(event) => updateItem(index, event)} />
                    </label>
                    <label className="field-label">
                      HSN/SAC
                      <input name="hsn_sac" value={item.hsn_sac} onChange={(event) => updateItem(index, event)} maxLength="20" placeholder="Optional" />
                    </label>
                  </div>
                  <div className="sale-item-total">
                    <span>Line total</span>
                    <strong>{formatMoney(calculateTotals([item], form.prices_include_gst).total)}</strong>
                  </div>
                </article>
              ))}
            </div>

            <button type="button" className="btn btn-outline-secondary add-item-button" onClick={addItem}>
              <i className="bx bx-plus me-1" /> Add item
            </button>
          </section>

          <label className="field-label notes-field">
            Notes <span className="optional-label">Optional</span>
            <textarea name="notes" value={form.notes} onChange={updateField} maxLength="2000" rows="2" placeholder="Add a note for your records" />
          </label>

          <label className="lut-switch-card">
            <span className="lut-icon"><i className="bx bx-check-shield" /></span>
            <span className="lut-copy">
              <strong>Claim input tax credit</strong>
              <small>Turn off if this bill is not eligible for ITC</small>
            </span>
            <input type="checkbox" name="claim_input_tax_credit" checked={form.claim_input_tax_credit} onChange={updateField} />
            <span className="switch-control" aria-hidden="true" />
          </label>

          <label className="lut-switch-card">
            <span className="lut-icon"><i className="bx bx-transfer-alt" /></span>
            <span className="lut-copy">
              <strong>Reverse charge (RCM)</strong>
              <small>Unregistered supplier of notified goods/services (e.g. GTA freight, legal fees)</small>
            </span>
            <input type="checkbox" name="reverse_charge" checked={form.reverse_charge} onChange={updateField} />
            <span className="switch-control" aria-hidden="true" />
          </label>
        </div>

        <aside className="sale-summary-panel">
          <span className="eyebrow">PURCHASE SUMMARY</span>
          <h2>Purchase total</h2>
          <div className="summary-total">{formatMoney(totals.total)}</div>
          <div className="summary-breakdown">
            <div><span>Subtotal</span><strong>{formatMoney(totals.subtotal)}</strong></div>
            <div><span>Discount</span><strong>− {formatMoney(totals.discount)}</strong></div>
            <div><span>Taxable amount</span><strong>{formatMoney(totals.taxable)}</strong></div>
            <div><span>{form.reverse_charge ? 'GST under RCM' : form.claim_input_tax_credit ? 'Input GST' : 'GST in purchase cost'}</span><strong>{formatMoney(totals.tax)}</strong></div>
            {form.reverse_charge && (
              <div><span>Amount to supplier</span><strong>{formatMoney(supplierAmount)}</strong></div>
            )}
            <div className="summary-grand-total"><span>Total</span><strong>{formatMoney(totals.total)}</strong></div>
            {form.payment_method === 'credit' && (
              <div><span>Supplier balance due</span><strong>{formatMoney(balanceDue)}</strong></div>
            )}
          </div>
          <div className={`payment-status-note${form.payment_method === 'credit' ? ' is-credit' : ''}`}>
            <i className={`bx ${form.payment_method === 'credit' ? 'bx-time-five' : 'bx-check-circle'}`} />
            {form.reverse_charge
              ? 'RCM GST is recorded separately from the supplier amount.'
              : form.payment_method === 'credit'
                ? 'This amount will be added to supplier payables.'
                : 'This purchase will be recorded as paid to the supplier.'}
          </div>
          <button
            type="submit"
            className="btn btn-primary save-sale-button"
            disabled={saving || totals.total <= 0 || (form.payment_method === 'credit' && !form.supplier_id) || (form.payment_method === 'bank' && !form.bank_account_id)}
          >
            {saving ? 'Saving purchase…' : editing ? 'Save changes' : 'Save purchase'}
          </button>
          <Link className="cancel-sale-link" to="/purchases">Cancel</Link>
        </aside>
      </form>

      {drawerOpen && (
        <SupplierFormDrawer
          onClose={() => setDrawerOpen(false)}
          onSaved={supplierSaved}
        />
      )}
    </section>
  );
}
