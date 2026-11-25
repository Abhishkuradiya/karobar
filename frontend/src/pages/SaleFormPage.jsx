import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import CustomerFormDrawer from '../components/CustomerFormDrawer';
import api from '../api/axios';
import { formatMoney, getErrorMessage } from '../utils/format';

const paymentMethods = [
  { value: 'cash', label: 'Cash', icon: 'bx-money' },
  { value: 'upi', label: 'UPI', icon: 'bx-mobile-alt' },
  { value: 'card', label: 'Card', icon: 'bx-credit-card' },
  { value: 'bank', label: 'Bank', icon: 'bx-building-house' },
  { value: 'credit', label: 'Credit (udhaar)', icon: 'bx-time-five' },
];

const makeEmptyItem = () => ({
  product_id: '',
  description: '',
  quantity: '1',
  unit_rate: '',
  gst_rate: '18',
  discount: '0',
  hsn_sac: '',
});

const today = () => {
  const date = new Date();
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
};

const roundMoney = (amount) => Math.round((amount + Number.EPSILON) * 100) / 100;

function calculateTotals(items, pricesIncludeGst, exportUnderLut) {
  const totals = { subtotal: 0, discount: 0, taxable: 0, tax: 0, total: 0 };

  items.forEach((item) => {
    const gross = roundMoney((Number(item.quantity) || 0) * (Number(item.unit_rate) || 0));
    const discount = Math.min(gross, Number(item.discount) || 0);
    const afterDiscount = roundMoney(gross - discount);
    const rate = Number(item.gst_rate) || 0;
    const tax = exportUnderLut
      ? 0
      : roundMoney(pricesIncludeGst ? afterDiscount * rate / (100 + rate) : afterDiscount * rate / 100);
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

function toFormSale(sale) {
  return {
    customer_id: sale.customer_id ? String(sale.customer_id) : '',
    sale_date: sale.sale_date,
    payment_method: sale.payment_method,
    bank_account_id: sale.bank_account_id ? String(sale.bank_account_id) : '',
    prices_include_gst: sale.prices_include_gst,
    export_under_lut: sale.export_under_lut,
    notes: sale.notes || '',
    items: sale.items.map((item) => ({
      product_id: item.product_id ? String(item.product_id) : '',
      description: item.description,
      quantity: item.quantity,
      unit_rate: item.unit_rate,
      gst_rate: item.gst_rate,
      discount: item.discount,
      hsn_sac: item.hsn_sac || '',
    })),
  };
}

export default function SaleFormPage() {
  const { saleId } = useParams();
  const navigate = useNavigate();
  const editing = Boolean(saleId);
  const [form, setForm] = useState({
    customer_id: '',
    sale_date: today(),
    payment_method: 'cash',
    bank_account_id: '',
    prices_include_gst: false,
    export_under_lut: false,
    notes: '',
    items: [makeEmptyItem()],
  });
  const [customers, setCustomers] = useState([]);
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
        const [customerResponse, productResponse, bankResponse, saleResponse] = await Promise.all([
          api.get('/customers', { params: { per_page: 50 } }),
          api.get('/inventory/products', { params: { per_page: 100 } }),
          api.get('/banking/accounts'),
          editing ? api.get(`/sales/${saleId}`) : Promise.resolve(null),
        ]);
        if (!active) return;
        setCustomers(customerResponse.data.data);
        setProducts(productResponse.data.data);
        setBankAccounts(bankResponse.data.data);
        if (saleResponse) setForm(toFormSale(saleResponse.data));
      } catch (requestError) {
        if (active) setError(getErrorMessage(requestError, 'Could not load sale details.'));
      } finally {
        if (active) setLoading(false);
      }
    };

    loadFormData();
    return () => { active = false; };
  }, [editing, saleId]);

  const totals = useMemo(
    () => calculateTotals(form.items, form.prices_include_gst, form.export_under_lut),
    [form.items, form.prices_include_gst, form.export_under_lut],
  );

  const updateField = (event) => {
    const { name, value, type, checked } = event.target;
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

  const saveSale = async (event) => {
    event.preventDefault();
    setError('');
    setSaving(true);
    const payload = {
      customer_id: form.customer_id ? Number(form.customer_id) : null,
      sale_date: form.sale_date,
      payment_method: form.payment_method,
      bank_account_id: form.bank_account_id ? Number(form.bank_account_id) : null,
      prices_include_gst: form.prices_include_gst,
      export_under_lut: form.export_under_lut,
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
      })),
    };

    try {
      if (editing) await api.put(`/sales/${saleId}`, payload);
      else await api.post('/sales', payload);
      navigate('/sales', { state: { notice: editing ? 'Sale updated and books adjusted.' : 'Sale saved and posted to the books.' } });
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not save this sale.'));
    } finally {
      setSaving(false);
    }
  };

  const customerSaved = (customer) => {
    setCustomers((current) => [customer, ...current.filter((item) => item.id !== customer.id)]);
    setForm((current) => ({ ...current, customer_id: String(customer.id) }));
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
            unit_rate: String(product.selling_price),
            gst_rate: String(product.gst_rate),
            hsn_sac: product.hsn_sac || '',
          } : {}),
        }
        : item),
    }));
  };

  if (loading) {
    return <div className="loading-state"><span className="spinner-border spinner-border-sm text-primary" /> Loading sale…</div>;
  }

  return (
    <section className="page-stack">
      <div className="breadcrumb-line">
        <Link to="/sales"><i className="bx bx-chevron-left" /> Sales</Link>
      </div>
      <div className="page-heading sale-page-heading">
        <div>
          <span className="eyebrow">{editing ? 'UPDATE TRANSACTION' : 'REVENUE ENTRY'}</span>
          <h1>{editing ? 'Edit sale' : 'New sale'}</h1>
          <p>Enter the sale once. Amounts and accounting entries are calculated automatically.</p>
        </div>
      </div>

      {error && <div className="alert alert-danger py-2" role="alert">{error}</div>}

      <form className="sale-entry-layout" onSubmit={saveSale}>
        <div className="sale-entry-main">
          <section className="form-panel">
            <div className="customer-select-row">
              <label className="field-label flex-grow-1" htmlFor="sale-customer">
                Customer
                <select id="sale-customer" name="customer_id" value={form.customer_id} onChange={updateField}>
                  <option value="">Walk-in customer (paid now)</option>
                  {customers.map((customer) => (
                    <option key={customer.id} value={customer.id}>{customer.name}{customer.mobile ? ` · ${customer.mobile}` : ''}</option>
                  ))}
                </select>
              </label>
              <button type="button" className="icon-button add-customer-button" onClick={() => setDrawerOpen(true)} aria-label="Add customer">
                <i className="bx bx-user-plus" />
              </button>
            </div>

            <div className="field-label payment-field">
              <span>Payment</span>
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
              {form.payment_method === 'credit' && !form.customer_id && (
                <small className="field-hint">Choose a saved customer before recording a credit sale.</small>
              )}
              {form.payment_method === 'bank' && <label className="field-label bank-account-select">Bank account
                <select required value={form.bank_account_id} onChange={(event) => setForm((current) => ({ ...current, bank_account_id: event.target.value }))}>
                  <option value="">Select a bank account</option>
                  {bankAccounts.map((account) => <option key={account.id} value={account.id}>{account.name} · {account.bank_name}{account.account_last4 ? ` ·••${account.account_last4}` : ''}</option>)}
                </select>
                {bankAccounts.length === 0 && <small className="field-hint">Add a bank account in Banking before choosing bank payment.</small>}
              </label>}
            </div>

            <label className="field-label sale-date-field">
              Date
              <input type="date" name="sale_date" value={form.sale_date} onChange={updateField} required />
            </label>
          </section>

          <section className="items-section">
            <div className="section-heading-row">
              <div>
                <h2>Items</h2>
                <span className="muted-copy">Add the goods or services on this sale.</span>
              </div>
              <label className="switch-label">
                <span>Prices include GST</span>
                <input type="checkbox" name="prices_include_gst" checked={form.prices_include_gst} onChange={updateField} />
                <span className="switch-control" aria-hidden="true" />
              </label>
            </div>

            <div className="sale-items-list">
              {form.items.map((item, index) => (
                <article className="sale-item-card" key={`sale-item-${index}`}>
                  <label className="field-label inventory-link-field">
                    Product from inventory
                    <select value={item.product_id} onChange={(event) => selectProduct(index, event.target.value)}>
                      <option value="">Free-text item</option>
                      {products.map((product) => <option key={product.id} value={product.id}>{product.name}{product.track_stock ? ` · ${Number(product.current_stock).toLocaleString()} ${product.unit_code || ''} in stock` : ' · service / untracked'}</option>)}
                    </select>
                  </label>
                  <div className="sale-item-description">
                    <label className="sr-only" htmlFor={`item-description-${index}`}>Item {index + 1} description</label>
                    <input
                      id={`item-description-${index}`}
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
                    <strong>{formatMoney(calculateTotals([item], form.prices_include_gst, form.export_under_lut).total)}</strong>
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
            <span className="lut-icon"><i className="bx bx-globe" /></span>
            <span className="lut-copy"><strong>Export / SEZ under LUT</strong><small>Zero-rated supply without payment of IGST</small></span>
            <input type="checkbox" name="export_under_lut" checked={form.export_under_lut} onChange={updateField} />
            <span className="switch-control" aria-hidden="true" />
          </label>
        </div>

        <aside className="sale-summary-panel">
          <span className="eyebrow">SALE SUMMARY</span>
          <h2>Amount due</h2>
          <div className="summary-total">{formatMoney(totals.total)}</div>
          <div className="summary-breakdown">
            <div><span>Subtotal</span><strong>{formatMoney(totals.subtotal)}</strong></div>
            <div><span>Discount</span><strong>− {formatMoney(totals.discount)}</strong></div>
            <div><span>Taxable amount</span><strong>{formatMoney(totals.taxable)}</strong></div>
            <div><span>GST</span><strong>{formatMoney(totals.tax)}</strong></div>
            <div className="summary-grand-total"><span>Total</span><strong>{formatMoney(totals.total)}</strong></div>
          </div>
          <div className={`payment-status-note${form.payment_method === 'credit' ? ' is-credit' : ''}`}>
            <i className={`bx ${form.payment_method === 'credit' ? 'bx-time-five' : 'bx-check-circle'}`} />
            {form.payment_method === 'credit' ? 'This amount will be added to customer receivables.' : 'This sale will be recorded as paid.'}
          </div>
          <button type="submit" className="btn btn-primary save-sale-button" disabled={saving || totals.total <= 0 || (form.payment_method === 'bank' && !form.bank_account_id)}>
            {saving ? 'Saving sale…' : editing ? 'Save changes' : 'Save sale'}
          </button>
          <Link className="cancel-sale-link" to="/sales">Cancel</Link>
        </aside>
      </form>

      {drawerOpen && (
        <CustomerFormDrawer
          onClose={() => setDrawerOpen(false)}
          onSaved={customerSaved}
        />
      )}
    </section>
  );
}
