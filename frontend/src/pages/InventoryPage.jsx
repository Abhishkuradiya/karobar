import { useCallback, useEffect, useState } from 'react';
import { useLocation } from 'react-router-dom';
import api from '../api/axios';
import { formatMoney, getErrorMessage } from '../utils/format';
import PaginationControls from '../components/PaginationControls';
import './inventory-banking-invoices.css';
import { useFeedback } from '../context/FeedbackContext';
import MessageBanner from '../components/MessageBanner';

const gstRates = ['0', '0.25', '3', '5', '12', '18', '28', '40'];
const emptyProduct = () => ({
  name: '', sku: '', barcode: '', hsn_sac: '', category_id: '', unit_code: 'pcs',
  gst_rate: '18', purchase_price: '0', selling_price: '0', is_service: false,
  track_stock: true, batch_tracking: false, low_stock_threshold: '0',
  opening_quantity: '0', opening_unit_cost: '0', opening_batch_number: '', opening_expiry_date: '',
});
const tabs = [
  { id: 'products', label: 'Products & services', icon: 'bx-cube' },
  { id: 'reorder', label: 'Reorder list', icon: 'bx-cart' },
  { id: 'expiring', label: 'Expiring stock', icon: 'bx-calendar-exclamation' },
  { id: 'transfers', label: 'Branch transfers', icon: 'bx-transfer-alt' },
];

export default function InventoryPage({ initialTab = 'products' }) {
  const { confirm } = useFeedback();
  const { pathname } = useLocation();
  const routeTab = pathname === '/inventory/reorder' ? 'reorder' : pathname === '/inventory/expiry' ? 'expiring' : initialTab;
  const [tab, setTab] = useState(routeTab);
  const [products, setProducts] = useState([]);
  const [categories, setCategories] = useState([]);
  const [summary, setSummary] = useState({ products: 0, low_stock: 0, stock_value: 0 });
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 });
  const [page, setPage] = useState(1);
  const [reorder, setReorder] = useState([]);
  const [expiring, setExpiring] = useState([]);
  const [days, setDays] = useState(30);
  const [search, setSearch] = useState('');
  const [categoryFilter, setCategoryFilter] = useState('');
  const [productModal, setProductModal] = useState(null);
  const [categoryModal, setCategoryModal] = useState(false);
  const [stockProduct, setStockProduct] = useState(null);
  const [categoryName, setCategoryName] = useState('');
  const [stockCount, setStockCount] = useState('');
  const [stockNotes, setStockNotes] = useState('');
  const [productForm, setProductForm] = useState(emptyProduct());
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  useEffect(() => {
    setTab(routeTab);
  }, [routeTab]);

  const loadProducts = useCallback(async () => {
    const response = await api.get('/inventory/products', {
      params: { search: search || undefined, category_id: categoryFilter || undefined, page, per_page: 25 },
    });
    setProducts(response.data.data);
    setMeta(response.data.meta || { current_page: page, last_page: 1, total: response.data.data.length });
    setCategories(response.data.categories);
    setSummary(response.data.summary);
  }, [categoryFilter, page, search]);

  const loadList = useCallback(async () => {
    if (tab === 'products') return loadProducts();
    if (tab === 'reorder') {
      const response = await api.get('/inventory/reorder');
      setReorder(response.data.data);
    }
    if (tab === 'expiring') {
      const response = await api.get('/inventory/expiring', { params: { days } });
      setExpiring(response.data.data);
    }
  }, [days, loadProducts, tab]);

  useEffect(() => {
    let active = true;
    Promise.resolve()
      .then(() => loadList())
      .catch((requestError) => { if (active) setError(getErrorMessage(requestError, 'Could not load inventory.')); })
      .finally(() => { if (active) setLoading(false); });
    return () => { active = false; };
  }, [loadList]);

  const updateProductField = (event) => {
    const { name, value, type, checked } = event.target;
    setProductForm((current) => ({ ...current, [name]: type === 'checkbox' ? checked : name === 'sku' ? value.toUpperCase() : value }));
  };

  const openNewProduct = () => {
    setProductForm(emptyProduct());
    setProductModal({ mode: 'create' });
    setError('');
  };

  const openEditProduct = (product) => {
    setProductForm({
      name: product.name || '', sku: product.sku || '', barcode: product.barcode || '',
      hsn_sac: product.hsn_sac || '', category_id: product.category_id ? String(product.category_id) : '',
      unit_code: product.unit_code || 'pcs', gst_rate: String(product.gst_rate),
      purchase_price: String(product.purchase_price), selling_price: String(product.selling_price),
      is_service: Boolean(product.is_service), track_stock: Boolean(product.track_stock),
      batch_tracking: Boolean(product.batch_tracking),
      low_stock_threshold: product.low_stock_threshold === null ? '' : String(product.low_stock_threshold),
      opening_quantity: '0', opening_unit_cost: '0', opening_batch_number: '', opening_expiry_date: '',
    });
    setProductModal({ mode: 'edit', product });
    setError('');
  };

  const saveProduct = async (event) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    const payload = {
      ...productForm,
      category_id: productForm.category_id ? Number(productForm.category_id) : null,
      gst_rate: Number(productForm.gst_rate),
      purchase_price: Number(productForm.purchase_price || 0),
      selling_price: Number(productForm.selling_price || 0),
      low_stock_threshold: productForm.low_stock_threshold === '' ? null : Number(productForm.low_stock_threshold),
      ...(productModal.mode === 'create' ? {
        opening_quantity: Number(productForm.opening_quantity || 0),
        opening_unit_cost: Number(productForm.opening_unit_cost || 0),
        opening_batch_number: productForm.opening_batch_number || null,
        opening_expiry_date: productForm.opening_expiry_date || null,
      } : {}),
    };
    try {
      const response = productModal.mode === 'edit'
        ? await api.patch(`/inventory/products/${productModal.product.id}`, payload)
        : await api.post('/inventory/products', payload);
      setProductModal(null);
      setNotice(`${response.data.name} ${productModal.mode === 'edit' ? 'updated' : 'added'} successfully.`);
      await loadProducts();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not save this product.'));
    } finally {
      setSaving(false);
    }
  };

  const saveCategory = async (event) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      await api.post('/inventory/categories', { name: categoryName.trim() });
      setCategoryName('');
      const response = await api.get('/inventory/categories');
      setCategories(response.data.data);
      setNotice('Category added.');
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not add this category.'));
    } finally {
      setSaving(false);
    }
  };

  const deleteCategory = async (category) => {
    if (!await confirm({ title: 'Delete this category?', message: `Products in ${category.name} will remain in inventory.`, confirmLabel: 'Delete category' })) return;
    try {
      await api.delete(`/inventory/categories/${category.id}`);
      setCategories((current) => current.filter((item) => item.id !== category.id));
      await loadProducts();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not remove this category.'));
    }
  };

  const deleteProduct = async (product) => {
    if (!await confirm({ title: 'Delete this product?', message: `${product.name} can be deleted only when its stock is zero.`, confirmLabel: 'Delete product' })) return;
    setError('');
    try {
      await api.delete(`/inventory/products/${product.id}`);
      setNotice(`${product.name} was deleted.`);
      await loadProducts();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not delete this product.'));
    }
  };

  const submitStockCount = async (event) => {
    event.preventDefault();
    setSaving(true);
    setError('');
    try {
      await api.patch(`/inventory/products/${stockProduct.id}/stock`, {
        counted_quantity: Number(stockCount),
        notes: stockNotes.trim() || null,
      });
      setStockProduct(null);
      setNotice(`${stockProduct.name} stock count was updated.`);
      await loadProducts();
    } catch (requestError) {
      setError(getErrorMessage(requestError, 'Could not adjust stock.'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <section className="page-stack">
      <div className="page-heading">
        <div>
          <span className="eyebrow">PRODUCTS & STOCK CONTROL</span>
          <h1>Inventory</h1>
          <p>Keep product details, on-hand quantities and reorder needs up to date.</p>
        </div>
        {tab === 'products' && <button className="btn btn-primary" type="button" onClick={openNewProduct}><i className="bx bx-plus me-1" /> Add product</button>}
      </div>

      <MessageBanner message={notice} onDismiss={() => setNotice('')} />
      <MessageBanner message={error} type="error" onDismiss={() => setError('')} />

      <div className="inventory-tabs" role="tablist" aria-label="Inventory views">
        {tabs.map((item) => (
          <button key={item.id} type="button" role="tab" aria-selected={tab === item.id} className={tab === item.id ? 'is-active' : ''} onClick={() => { setLoading(true); setTab(item.id); setError(''); }}>
            <i className={`bx ${item.icon}`} /> {item.label}
          </button>
        ))}
        <button type="button" className="inventory-category-button" onClick={() => { setCategoryModal(true); setError(''); }}>
          <i className="bx bx-category" /> Categories
        </button>
      </div>

      {tab === 'products' && (
        <>
          <div className="summary-grid">
            <article className="summary-card"><span className="summary-icon summary-icon-violet"><i className="bx bx-cube" /></span><div><small>Active items</small><strong>{loading ? '—' : summary.products}</strong></div></article>
            <article className="summary-card"><span className="summary-icon summary-icon-amber"><i className="bx bx-error-circle" /></span><div><small>At or below reorder level</small><strong>{loading ? '—' : summary.low_stock}</strong></div></article>
            <article className="summary-card"><span className="summary-icon summary-icon-green"><i className="bx bx-wallet" /></span><div><small>Stock value</small><strong>{loading ? '—' : formatMoney(summary.stock_value)}</strong></div></article>
          </div>
          <section className="data-card">
            <div className="data-card-toolbar inventory-toolbar">
              <div><h2>Product list</h2><span className="muted-copy">Stock is valued at the recorded purchase cost.</span></div>
              <div className="inventory-filters">
                <label className="search-box"><i className="bx bx-search" /><input aria-label="Search products" value={search} onChange={(event) => { setLoading(true); setError(''); setPage(1); setSearch(event.target.value); }} placeholder="Name, SKU or barcode" /></label>
                <select aria-label="Filter by category" value={categoryFilter} onChange={(event) => { setLoading(true); setError(''); setPage(1); setCategoryFilter(event.target.value); }}><option value="">All categories</option>{categories.map((category) => <option value={category.id} key={category.id}>{category.name}</option>)}</select>
              </div>
            </div>
            {loading ? <div className="loading-state"><span className="spinner-border spinner-border-sm text-primary" /> Loading products…</div> : products.length === 0 ? (
              <div className="empty-state"><span className="empty-state-icon"><i className="bx bx-cube" /></span><h3>No products found</h3><p>Add goods or services to track their price, GST and stock status.</p><button className="btn btn-primary" type="button" onClick={openNewProduct}>Add your first product</button></div>
            ) : (
              <><div className="table-responsive"><table className="business-table"><thead><tr><th>S.No.</th><th>Product</th><th>Category</th><th>GST</th><th className="text-end">Purchase</th><th className="text-end">Selling</th><th className="text-end">On hand</th><th>Status</th><th /></tr></thead><tbody>
                {products.map((product, index) => {
                  const low = product.track_stock && product.low_stock_threshold !== null && Number(product.current_stock) <= Number(product.low_stock_threshold);
                  return <tr key={product.id}><td>{(page - 1) * (meta.per_page || 50) + index + 1}</td>
                    <td><strong>{product.name}</strong><small className="table-subtext">{product.sku ? `SKU ${product.sku}` : product.hsn_sac ? `HSN ${product.hsn_sac}` : product.is_service ? 'Service' : 'No SKU'}</small></td>
                    <td>{product.category?.name || '—'}</td><td>{Number(product.gst_rate)}%</td>
                    <td className="text-end">{formatMoney(product.purchase_price)}</td><td className="text-end">{formatMoney(product.selling_price)}</td>
                    <td className="text-end">{product.track_stock ? `${Number(product.current_stock).toLocaleString()} ${product.unit_code || ''}` : 'Not tracked'}</td>
                    <td><span className={`inventory-status ${low ? 'is-low' : 'is-good'}`}>{product.is_service ? 'Service' : low ? 'Reorder' : 'In stock'}</span></td>
                    <td><div className="table-actions">{product.track_stock && <button className="icon-button" type="button" title="Adjust stock" aria-label={`Adjust ${product.name} stock`} onClick={() => { setStockProduct(product); setStockCount(String(product.current_stock)); setStockNotes(''); }}><i className="bx bx-transfer" /></button>}<button className="icon-button" type="button" title="Edit product" aria-label={`Edit ${product.name}`} onClick={() => openEditProduct(product)}><i className="bx bx-edit-alt" /></button><button className="icon-button danger-icon" type="button" title="Delete product" aria-label={`Delete ${product.name}`} onClick={() => deleteProduct(product)}><i className="bx bx-trash" /></button></div></td>
                  </tr>;
                })}
              </tbody></table></div><PaginationControls page={page} meta={meta} onChange={(nextPage) => { setLoading(true); setPage(nextPage); }} /></>
            )}
          </section>
        </>
      )}

      {tab === 'reorder' && <section className="data-card"><div className="data-card-toolbar"><div><h2>Reorder suggestions</h2><span className="muted-copy">Target is the greater of two alert levels or recent 30-day sales, less stock on hand.</span></div></div>{loading ? <div className="loading-state">Loading reorder list…</div> : reorder.length === 0 ? <div className="empty-state"><h3>Nothing needs reordering</h3><p>Products below their alert level will appear here.</p></div> : <div className="table-responsive"><table className="business-table"><thead><tr><th>Product</th><th>Supplier last purchased from</th><th className="text-end">On hand</th><th className="text-end">Sold in 30 days</th><th className="text-end">Suggested order</th></tr></thead><tbody>{reorder.map((product) => <tr key={product.id}><td><strong>{product.name}</strong><small className="table-subtext">{product.sku || product.category?.name || '—'}</small></td><td>{product.last_supplier || 'No purchase history'}</td><td className="text-end">{Number(product.current_stock).toLocaleString()} {product.unit_code}</td><td className="text-end">{Number(product.sold_last_30_days).toLocaleString()}</td><td className="text-end fw-semibold">{Number(product.suggested_order_quantity).toLocaleString()} {product.unit_code}</td></tr>)}</tbody></table></div>}</section>}

      {tab === 'expiring' && <section className="data-card"><div className="data-card-toolbar"><div><h2>Expiry watch</h2><span className="muted-copy">Stock with an expiry date inside the selected window.</span></div><label className="compact-field"><span>Show next</span><select value={days} onChange={(event) => { setLoading(true); setDays(Number(event.target.value)); }}><option value={30}>30 days</option><option value={60}>60 days</option><option value={90}>90 days</option></select></label></div>{loading ? <div className="loading-state">Loading expiring batches…</div> : expiring.length === 0 ? <div className="empty-state"><h3>No stock expiring soon</h3><p>Batches with an expiry date in the next {days} days appear here.</p></div> : <div className="table-responsive"><table className="business-table"><thead><tr><th>Product</th><th>Batch</th><th>Expiry date</th><th className="text-end">Remaining</th></tr></thead><tbody>{expiring.map((batch) => <tr key={batch.id}><td><strong>{batch.product?.name}</strong><small className="table-subtext">{batch.product?.sku || '—'}</small></td><td>{batch.batch_number || '—'}</td><td><span className="inventory-status is-low">{batch.expiry_date}</span></td><td className="text-end">{Number(batch.quantity_remaining).toLocaleString()} {batch.product?.unit_code}</td></tr>)}</tbody></table></div>}</section>}

      {tab === 'transfers' && <section className="data-card branch-transfer-notice"><span className="summary-icon summary-icon-violet"><i className="bx bx-transfer-alt" /></span><div><h2>Branch transfers are not enabled</h2><p>Enable multi-branch support for this business before transferring stock between locations. Your stock remains available in this business inventory.</p></div><span className="inventory-status">Unavailable</span></section>}

      {productModal && <div className="inventory-modal-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setProductModal(null); }}><section className="inventory-modal" role="dialog" aria-modal="true" aria-labelledby="product-modal-title"><div className="inventory-modal-header"><div><span className="eyebrow">ITEM DETAILS</span><h2 id="product-modal-title">{productModal.mode === 'edit' ? 'Edit product' : 'Add product'}</h2></div><button className="icon-button" type="button" aria-label="Close" onClick={() => setProductModal(null)}><i className="bx bx-x" /></button></div><form onSubmit={saveProduct}><div className="inventory-modal-body">
        <div className="inventory-form-grid"><label className="inventory-field inventory-field-wide">Product or service name<input required name="name" maxLength="180" value={productForm.name} onChange={updateProductField} /></label>
          <label className="inventory-field">SKU<input name="sku" maxLength="60" value={productForm.sku} onChange={updateProductField} /></label><label className="inventory-field">Barcode<input name="barcode" maxLength="80" value={productForm.barcode} onChange={updateProductField} /></label>
          <label className="inventory-field">HSN / SAC<input name="hsn_sac" maxLength="20" value={productForm.hsn_sac} onChange={updateProductField} /></label><label className="inventory-field">Category<select name="category_id" value={productForm.category_id} onChange={updateProductField}><option value="">No category</option>{categories.map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select></label>
          <label className="inventory-field">Unit<select name="unit_code" value={productForm.unit_code} onChange={updateProductField}>{[['pcs','Pieces'],['kg','Kilograms'],['g','Grams'],['l','Litres'],['ml','Millilitres'],['m','Metres'],['box','Boxes'],['set','Sets'],['hr','Hours'],['day','Days']].map(([value,label]) => <option value={value} key={value}>{label}</option>)}</select></label><label className="inventory-field">GST rate<select name="gst_rate" value={productForm.gst_rate} onChange={updateProductField}>{gstRates.map((rate) => <option key={rate} value={rate}>{rate}%</option>)}</select></label>
          <label className="inventory-field">Purchase price<input type="number" min="0" step="0.01" name="purchase_price" value={productForm.purchase_price} onChange={updateProductField} /></label><label className="inventory-field">Selling price<input type="number" min="0" step="0.01" name="selling_price" value={productForm.selling_price} onChange={updateProductField} /></label>
          <label className="inventory-field">Low-stock alert<input type="number" min="0" step="0.001" name="low_stock_threshold" value={productForm.low_stock_threshold} onChange={updateProductField} /></label>
        </div>
        <div className="inventory-switches"><label><input type="checkbox" name="is_service" checked={productForm.is_service} onChange={(event) => setProductForm((current) => ({ ...current, is_service: event.target.checked, track_stock: event.target.checked ? false : current.track_stock }))} /><span><strong>This is a service</strong><small>Services do not use stock tracking.</small></span></label><label><input type="checkbox" name="track_stock" checked={productForm.track_stock && !productForm.is_service} disabled={productForm.is_service} onChange={updateProductField} /><span><strong>Track stock</strong><small>Deduct quantities when sold and receive stock on purchases.</small></span></label><label><input type="checkbox" name="batch_tracking" checked={productForm.batch_tracking} onChange={updateProductField} /><span><strong>Track batch and expiry</strong><small>Record batch numbers and expiry dates on purchases.</small></span></label></div>
        {productModal.mode === 'create' && productForm.track_stock && !productForm.is_service && <div className="inventory-opening-stock"><h3>Opening stock</h3><div className="inventory-form-grid"><label className="inventory-field">Quantity on hand<input type="number" min="0" step="0.001" name="opening_quantity" value={productForm.opening_quantity} onChange={updateProductField} /></label><label className="inventory-field">Unit cost<input type="number" min="0" step="0.01" name="opening_unit_cost" value={productForm.opening_unit_cost} onChange={updateProductField} /></label><label className="inventory-field">Batch number<input name="opening_batch_number" value={productForm.opening_batch_number} onChange={updateProductField} /></label><label className="inventory-field">Expiry date<input type="date" name="opening_expiry_date" value={productForm.opening_expiry_date} onChange={updateProductField} /></label></div><small>Opening stock is posted against opening-balance equity.</small></div>}
        {error && <div className="alert alert-danger py-2 mt-3">{error}</div>}
      </div><div className="inventory-modal-footer"><button className="btn btn-outline-secondary" type="button" onClick={() => setProductModal(null)}>Cancel</button><button className="btn btn-primary" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save product'}</button></div></form></section></div>}

      {categoryModal && <div className="inventory-modal-backdrop"><section className="inventory-modal inventory-modal-narrow" role="dialog" aria-modal="true" aria-labelledby="category-title"><div className="inventory-modal-header"><div><span className="eyebrow">ORGANIZE ITEMS</span><h2 id="category-title">Categories</h2></div><button className="icon-button" type="button" aria-label="Close" onClick={() => setCategoryModal(false)}><i className="bx bx-x" /></button></div><div className="inventory-modal-body"><form className="inventory-category-form" onSubmit={saveCategory}><label className="inventory-field">New category<input required maxLength="80" value={categoryName} onChange={(event) => setCategoryName(event.target.value)} placeholder="e.g. Raw materials" /></label><button className="btn btn-primary" type="submit" disabled={saving}>Add</button></form>{error && <div className="alert alert-danger py-2 mt-3">{error}</div>}<div className="inventory-category-list">{categories.map((category) => <div key={category.id}><span><strong>{category.name}</strong><small>{category.products_count ?? '—'} products</small></span><button className="icon-button danger-icon" type="button" onClick={() => deleteCategory(category)} aria-label={`Remove ${category.name}`}><i className="bx bx-trash" /></button></div>)}{categories.length === 0 && <p className="muted-copy">No categories created yet.</p>}</div></div></section></div>}

      {stockProduct && <div className="inventory-modal-backdrop"><section className="inventory-modal inventory-modal-narrow" role="dialog" aria-modal="true" aria-labelledby="stock-title"><div className="inventory-modal-header"><div><span className="eyebrow">STOCK COUNT</span><h2 id="stock-title">Adjust {stockProduct.name}</h2></div><button className="icon-button" type="button" aria-label="Close" onClick={() => setStockProduct(null)}><i className="bx bx-x" /></button></div><form onSubmit={submitStockCount}><div className="inventory-modal-body"><p className="muted-copy">Current quantity: {Number(stockProduct.current_stock).toLocaleString()} {stockProduct.unit_code}</p><label className="inventory-field">Counted quantity<input required type="number" min="0" step="0.001" value={stockCount} onChange={(event) => setStockCount(event.target.value)} /></label><label className="inventory-field mt-3">Reason or note<textarea rows="3" maxLength="255" value={stockNotes} onChange={(event) => setStockNotes(event.target.value)} placeholder="Stock count, damage, correction…" /></label>{error && <div className="alert alert-danger py-2 mt-3">{error}</div>}</div><div className="inventory-modal-footer"><button className="btn btn-outline-secondary" type="button" onClick={() => setStockProduct(null)}>Cancel</button><button className="btn btn-primary" type="submit" disabled={saving}>{saving ? 'Saving…' : 'Save count'}</button></div></form></section></div>}
    </section>
  );
}
