import { useEffect, useRef, useState } from 'react';
import { useAuth } from '../context/useAuth';
import api from '../api/axios';
import { useFeedback } from '../context/FeedbackContext';
import { getErrorMessage } from '../utils/format';
import MessageBanner from '../components/MessageBanner';
import '../business.css';
import './ai-accountant.css';

const prompts = [
  'Aaj ka update batao', 'Koi galti ya suggestion?', 'Kaun sabse late paise deta hai?',
  'Month-end checklist batao', 'Sharma ko 25 hazaar ka maal becha, cash liya',
  'Aaj ki sale kitni hui?', 'Is mahine ka profit kitna hai?', 'Show my balance sheet',
];
const expenseCategories = ['rent', 'electricity', 'salary', 'fuel', 'transport', 'internet', 'office_expenses', 'repairs', 'advertising', 'travel', 'bank_charges', 'miscellaneous', 'insurance', 'telephone', 'maintenance', 'professional_fees', 'packaging', 'taxes_licenses', 'other'];

function validateProposal(action) {
  const payload = action?.payload || {};
  if (action.type === 'product') {
    if (!String(payload.name || '').trim()) return 'Enter a product or service name.';
    if (!['pcs', 'kg', 'g', 'l', 'ml', 'm', 'box', 'set', 'hr', 'day'].includes(payload.unit_code)) return 'Select a valid unit.';
    if (![0, 0.25, 3, 5, 12, 18, 28, 40].includes(Number(payload.gst_rate))) return 'Select a supported GST rate.';
    if (Number(payload.opening_quantity || 0) > 0 && Number(payload.opening_unit_cost || 0) < 0) return 'Enter a valid opening unit cost.';
    return '';
  }
  if (!payload.payment_method) return 'Select a payment mode.';
  if (payload.payment_method === 'bank' && !payload.bank_account_id) return 'Select the bank account used for this entry.';
  if (action.type === 'sale' && payload.payment_method === 'credit' && !payload.customer_id) return 'Select a customer for a credit sale.';
  if (action.type === 'sale' && payload.customer_name && !payload.customer_id) return 'Select the mentioned customer or add them before confirming the sale.';
  if (action.type === 'purchase' && payload.payment_method === 'credit' && !payload.supplier_id) return 'Select a supplier for a credit purchase.';
  if (action.type === 'purchase' && payload.reverse_charge && payload.prices_include_gst) return 'Prices cannot include GST when reverse charge is enabled.';
  if (action.type === 'expense') {
    if (!expenseCategories.includes(payload.category)) return 'Select a valid expense category.';
    if (payload.amount === '' || Number(payload.amount) <= 0) return 'Enter the expense amount.';
    return '';
  }
  if (!(payload.sale_date || payload.purchase_date)) return 'Select the transaction date.';
  if (!Array.isArray(payload.items) || payload.items.length === 0) return 'Add at least one item.';
  for (const item of payload.items) {
    if (!String(item.description || '').trim()) return 'Enter a name for every item.';
    if (Number(item.quantity) <= 0) return 'Quantity must be greater than zero.';
    if (item.unit_rate === '' || item.unit_rate === null || item.unit_rate === undefined || Number(item.unit_rate) < 0) return 'Enter the price/rate for every item. The AI will never guess a price.';
    if (Number(item.discount) < 0 || Number(item.discount) > Number(item.quantity) * Number(item.unit_rate)) return 'Discount cannot exceed the item value.';
  }
  return '';
}

function CustomerResolution({ action, lookups, customerMatches, onChange, onCustomerAdded }) {
  const payload = action.payload || {};
  const customerQuery = String(payload.customer_name || '').trim();
  const matchingCustomers = customerMatches || lookups.customers.filter((item) => customerQuery && item.name.toLowerCase().includes(customerQuery.toLowerCase()));
  const [newCustomer, setNewCustomer] = useState({ name: customerQuery, mobile: '', gstin: '' });
  const [addingCustomer, setAddingCustomer] = useState(false);
  const [customerError, setCustomerError] = useState('');
  if (action.type !== 'sale' || !customerQuery || payload.customer_id) return null;
  const addCustomer = async (event) => {
    event.preventDefault();
    const name = newCustomer.name.trim();
    const mobile = newCustomer.mobile.trim();
    const gstin = newCustomer.gstin.trim().toUpperCase();
    if (!name) { setCustomerError('Customer name is required.'); return; }
    if (mobile && !/^\d{10}$/.test(mobile)) { setCustomerError('Enter a valid 10-digit mobile number.'); return; }
    if (gstin && !/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/.test(gstin)) { setCustomerError('Enter a valid 15-character GSTIN.'); return; }
    setAddingCustomer(true); setCustomerError('');
    try {
      const { data } = await api.post('/customers', { name, mobile: mobile || null, gstin: gstin || null, credit_days: 0, credit_limit: null, state: null, email: null, address: null });
      onCustomerAdded(data);
    } catch (error) { setCustomerError(getErrorMessage(error, 'Could not add this customer.')); }
    finally { setAddingCustomer(false); }
  };
  return <section className="ai-customer-resolution ai-customer-resolution-top"><div className="ai-customer-resolution-heading"><strong>Customer mentioned: {customerQuery}</strong><small>{matchingCustomers.length ? 'Select the correct customer or add a new one.' : 'No matching customer found. Add them before confirming the sale.'}</small></div>{matchingCustomers.length > 0 && <div className="ai-customer-matches">{matchingCustomers.map((customer) => <button type="button" key={customer.id} onClick={() => onChange({ ...action, payload: { ...payload, customer_id: customer.id, customer_name: customer.name } })}><span>{customer.name}</span><small>{customer.mobile || 'No mobile saved'}{customer.gstin ? ` · ${customer.gstin}` : ''}</small><i className="bx bx-chevron-right" /></button>)}</div>}<form className="ai-add-customer-form" onSubmit={addCustomer}><label>Customer name<input value={newCustomer.name} onChange={(event) => setNewCustomer((current) => ({ ...current, name: event.target.value }))} placeholder="Customer name" required /></label><label>Mobile<input value={newCustomer.mobile} onChange={(event) => setNewCustomer((current) => ({ ...current, mobile: event.target.value }))} placeholder="Mobile number" /></label><label>GSTIN<input value={newCustomer.gstin} maxLength="15" onChange={(event) => setNewCustomer((current) => ({ ...current, gstin: event.target.value.toUpperCase() }))} placeholder="15-character GSTIN" /></label><button type="submit" className="secondary-button" disabled={addingCustomer}>{addingCustomer ? 'Adding…' : 'Add customer'}</button></form>{customerError && <small className="ai-validation-error">{customerError}</small>}</section>;
}

function ProposalCard({ action, onChange, onConfirm, onCancel, busy, lookups, customerMatches, onCustomerAdded }) {
  const payload = action.payload || {};
  const isProduct = action.type === 'product';
  const isExpense = action.type === 'expense';
  const dateKey = action.type === 'sale' ? 'sale_date' : action.type === 'purchase' ? 'purchase_date' : 'expense_date';
  const items = Array.isArray(payload.items) && payload.items.length ? payload.items : [{ description: '', quantity: '', unit_rate: '', gst_rate: 0, discount: 0 }];
  const update = (key, value) => onChange({ ...action, payload: { ...payload, [key]: value } });
  const updateItem = (index, key, value) => onChange({ ...action, payload: { ...payload, items: items.map((item, itemIndex) => itemIndex === index ? { ...item, [key]: value } : item) } });
  const updateGst = (value) => onChange({ ...action, payload: { ...payload, items: items.map((item) => ({ ...item, gst_rate: Number(value) })) } });
  const updateProduct = (index, productId) => { const product = lookups.products.find((item) => String(item.id) === String(productId)); onChange({ ...action, payload: { ...payload, items: items.map((item, itemIndex) => itemIndex === index ? { ...item, product_id: productId, ...(product ? { description: product.name, unit_rate: action.type === 'purchase' ? product.purchase_price : product.selling_price, gst_rate: product.gst_rate, hsn_sac: product.hsn_sac || '' } : {}) } : item) } }); };
  const addItem = () => onChange({ ...action, payload: { ...payload, items: [...items, { description: '', quantity: 1, unit_rate: '', gst_rate: 0, discount: 0 }] } });
  const removeItem = (index) => onChange({ ...action, payload: { ...payload, items: items.filter((_, itemIndex) => itemIndex !== index) } });
  const total = isExpense ? Number(payload.amount || 0) : items.reduce((sum, item) => sum + (Number(item.quantity) || 0) * (Number(item.unit_rate ?? item.unit_price) || 0) - (Number(item.discount) || 0), 0);

  if (isProduct) return <div className="ai-proposal-card ai-product-proposal"><div className="ai-proposal-heading"><div><span className="eyebrow">PRODUCT · NEEDS REVIEW</span><strong>Add product or service</strong></div><i className="bx bx-cube" /></div><div className="ai-product-form-grid"><label className="ai-wide-field">Product or service name<input required value={payload.name || ''} onChange={(event) => update('name', event.target.value)} placeholder="e.g. Washing machine" /></label><label>SKU<input value={payload.sku || ''} onChange={(event) => update('sku', event.target.value)} /></label><label>Barcode<input value={payload.barcode || ''} onChange={(event) => update('barcode', event.target.value)} /></label><label>HSN / SAC<input value={payload.hsn_sac || ''} onChange={(event) => update('hsn_sac', event.target.value)} /></label><label>Category<select value={payload.category_id || ''} onChange={(event) => update('category_id', event.target.value ? Number(event.target.value) : null)}><option value="">No category</option>{(lookups.categories || []).map((category) => <option key={category.id} value={category.id}>{category.name}</option>)}</select></label><label>Unit<select value={payload.unit_code || 'pcs'} onChange={(event) => update('unit_code', event.target.value)}>{[['pcs', 'Pieces'], ['kg', 'Kilograms'], ['g', 'Grams'], ['l', 'Litres'], ['ml', 'Millilitres'], ['m', 'Metres'], ['box', 'Boxes'], ['set', 'Sets'], ['hr', 'Hours'], ['day', 'Days']].map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></label><label>GST rate<select value={payload.gst_rate ?? 0} onChange={(event) => update('gst_rate', Number(event.target.value))}>{[0, 0.25, 3, 5, 12, 18, 28, 40].map((rate) => <option key={rate} value={rate}>{rate}%</option>)}</select></label><label>Purchase price<input type="number" min="0" step="0.01" value={payload.purchase_price ?? ''} onChange={(event) => update('purchase_price', event.target.value)} /></label><label>Selling price<input type="number" min="0" step="0.01" value={payload.selling_price ?? ''} onChange={(event) => update('selling_price', event.target.value)} /></label><label>Low-stock alert<input type="number" min="0" step="0.001" value={payload.low_stock_threshold ?? ''} onChange={(event) => update('low_stock_threshold', event.target.value)} /></label></div><div className="ai-options-row"><label><input type="checkbox" checked={payload.is_service !== false && Boolean(payload.is_service)} onChange={(event) => onChange({ ...action, payload: { ...payload, is_service: event.target.checked, track_stock: event.target.checked ? false : payload.track_stock } })} /> This is a service</label><label><input type="checkbox" checked={Boolean(payload.track_stock) && !payload.is_service} disabled={Boolean(payload.is_service)} onChange={(event) => update('track_stock', event.target.checked)} /> Track stock</label><label><input type="checkbox" checked={Boolean(payload.batch_tracking)} onChange={(event) => update('batch_tracking', event.target.checked)} /> Track batches</label></div>{payload.track_stock && !payload.is_service && <div className="ai-opening-stock"><strong>Opening stock</strong><div className="ai-product-form-grid"><label>Quantity on hand<input type="number" min="0" step="0.001" value={payload.opening_quantity ?? ''} onChange={(event) => update('opening_quantity', event.target.value)} /></label><label>Unit cost<input type="number" min="0" step="0.01" value={payload.opening_unit_cost ?? ''} onChange={(event) => update('opening_unit_cost', event.target.value)} /></label></div></div>}<div className="ai-proposal-footer"><span>Unknown fields can be completed before saving.</span><div><button type="button" className="secondary-button" onClick={onCancel}>× Cancel</button><button type="button" className="primary-button" onClick={onConfirm} disabled={busy}>✓ Save product</button></div></div></div>;

  return <div className="ai-proposal-card">
    <div className="ai-proposal-heading"><div><span className="eyebrow">{action.type.toUpperCase()} · NEEDS REVIEW</span><strong>{action.type === 'sale' ? 'Sale' : action.type === 'purchase' ? 'Purchase' : 'Expense'} · {isExpense ? (payload.category || 'Uncategorised') : (action.type === 'sale' ? (payload.customer_name || 'Walk-in customer') : (payload.supplier_name || 'No supplier'))}</strong></div><i className="bx bx-edit-alt" /></div>
    <div className="ai-proposal-grid">
      <label>Date<input type="date" value={payload[dateKey] || new Date().toISOString().slice(0, 10)} onChange={(event) => update(dateKey, event.target.value)} /></label>
      {!isExpense && <label>GST %<select value={items[0]?.gst_rate ?? 0} onChange={(event) => updateGst(event.target.value)}>{[0, 0.25, 3, 5, 12, 18, 28, 40].map((rate) => <option key={rate} value={rate}>{rate}%</option>)}</select></label>}
      {!isExpense && <label>{action.type === 'sale' ? 'Customer' : 'Supplier'}{action.type === 'sale' ? <select value={payload.customer_id || ''} onChange={(event) => { const selected = lookups.customers.find((item) => String(item.id) === event.target.value); onChange({ ...action, payload: { ...payload, customer_id: event.target.value ? Number(event.target.value) : null, customer_name: selected?.name || payload.customer_name || null } }); }}><option value="">Walk-in customer</option>{lookups.customers.map((item) => <option key={item.id} value={item.id}>{item.name}{item.mobile ? ` · ${item.mobile}` : ''}</option>)}</select> : <select value={payload.supplier_id || ''} onChange={(event) => update('supplier_id', event.target.value ? Number(event.target.value) : null)}><option value="">Select supplier</option>{lookups.suppliers.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>}</label>}
      {action.type === 'purchase' && <><label>Supplier bill no.<input value={payload.supplier_bill_number || ''} onChange={(event) => update('supplier_bill_number', event.target.value)} placeholder="Supplier bill number" /></label><label>Bill date<input type="date" value={payload.supplier_bill_date || ''} onChange={(event) => update('supplier_bill_date', event.target.value)} /></label></>}
    </div>
    <div className="ai-payment-row"><span>Payment mode</span><div>{(isExpense ? ['cash', 'upi', 'bank', 'card', 'unpaid'] : ['cash', 'upi', 'card', 'bank', 'credit']).map((method) => <button type="button" className={payload.payment_method === method ? 'selected' : ''} key={method} onClick={() => update('payment_method', method)}>{method === 'credit' ? 'Credit (udhaar)' : method.charAt(0).toUpperCase() + method.slice(1)}</button>)}</div></div>
    {payload.payment_method === 'bank' && <label className="ai-bank-field">Bank account<select value={payload.bank_account_id || ''} onChange={(event) => update('bank_account_id', event.target.value ? Number(event.target.value) : null)}><option value="">Select bank account</option>{lookups.bankAccounts.map((item) => <option key={item.id} value={item.id}>{item.name}{item.bank_name ? ` · ${item.bank_name}` : ''}</option>)}</select></label>}
    {isExpense ? <div className="ai-expense-fields"><label>Category<select value={payload.category || 'other'} onChange={(event) => update('category', event.target.value)}>{expenseCategories.map((category) => <option key={category} value={category}>{category.replaceAll('_', ' ')}</option>)}</select></label><label>Supplier<select value={payload.supplier_id || ''} onChange={(event) => update('supplier_id', event.target.value ? Number(event.target.value) : null)}><option value="">No supplier</option>{lookups.suppliers.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label>Amount (₹)<input type="number" min="0" value={payload.amount ?? ''} onChange={(event) => update('amount', Number(event.target.value))} /></label><label>GST %<select value={payload.gst_rate ?? 0} onChange={(event) => update('gst_rate', Number(event.target.value))}><option value="0">0%</option><option value="5">5%</option><option value="12">12%</option><option value="18">18%</option><option value="28">28%</option></select></label><label className="ai-wide-field">Note<input value={payload.note || ''} onChange={(event) => update('note', event.target.value)} placeholder="Add a note" /></label></div> : <div className="ai-items-box"><div className="ai-items-header"><span>Product / item</span><span>Qty</span><span>Rate ₹</span><span /></div>{items.map((item, index) => <div className="ai-item-group" key={index}><label className="ai-product-select">Product from inventory<select value={item.product_id || ''} onChange={(event) => updateProduct(index, event.target.value)}><option value="">Choose product or enter below</option>{lookups.products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}</select></label><div className="ai-item-row"><label>Item name<input value={item.description || ''} onChange={(event) => updateItem(index, 'description', event.target.value)} placeholder="e.g. Washing machine" /></label><label>Quantity<input type="number" min="0" value={item.quantity ?? ''} onChange={(event) => updateItem(index, 'quantity', Number(event.target.value))} /></label><label>Unit rate (₹)<input type="number" min="0" value={item.unit_rate ?? item.unit_price ?? ''} onChange={(event) => updateItem(index, 'unit_rate', Number(event.target.value))} placeholder="Enter price" /></label><button type="button" onClick={() => removeItem(index)} disabled={items.length === 1} aria-label="Remove item">×</button></div><div className="ai-item-extra"><label>Discount<input type="number" min="0" value={item.discount ?? 0} onChange={(event) => updateItem(index, 'discount', Number(event.target.value))} /></label><label>HSN/SAC<input value={item.hsn_sac || ''} onChange={(event) => updateItem(index, 'hsn_sac', event.target.value)} /></label>{action.type === 'purchase' && lookups.products.find((product) => String(product.id) === String(item.product_id))?.batch_tracking && <><label>Batch no.<input value={item.batch_number || ''} onChange={(event) => updateItem(index, 'batch_number', event.target.value)} /></label><label>Expiry<input type="date" value={item.expiry_date || ''} onChange={(event) => updateItem(index, 'expiry_date', event.target.value)} /></label></>}</div></div>)}<button type="button" className="ai-add-item" onClick={addItem}>＋ Add item</button></div>}
    <div className="ai-options-row">{!isExpense && <label><input type="checkbox" checked={Boolean(payload.prices_include_gst)} onChange={(event) => update('prices_include_gst', event.target.checked)} /> Prices include GST</label>}{action.type === 'sale' && <label><input type="checkbox" checked={Boolean(payload.export_under_lut)} onChange={(event) => update('export_under_lut', event.target.checked)} /> Export under LUT</label>}{(action.type === 'purchase' || (isExpense && Number(payload.gst_rate) > 0)) && <label><input type="checkbox" checked={Boolean(payload.claim_input_tax_credit)} onChange={(event) => update('claim_input_tax_credit', event.target.checked)} /> Claim input GST</label>}{action.type === 'purchase' && <label><input type="checkbox" checked={Boolean(payload.reverse_charge)} onChange={(event) => update('reverse_charge', event.target.checked)} /> Reverse charge</label>}</div>
    {!isExpense && <label className="ai-notes-field">Notes<input value={payload.notes || ''} onChange={(event) => update('notes', event.target.value)} placeholder="Add a note" /></label>}
    <div className="ai-proposal-footer"><span>{total > 0 ? `Estimated total ₹${total.toLocaleString('en-IN', { maximumFractionDigits: 2 })}` : 'Needs: amount, payment mode'}</span><div><button type="button" className="secondary-button" onClick={onCancel}>× Cancel</button><button type="button" className="primary-button" onClick={onConfirm} disabled={busy || total <= 0}>✓ Confirm</button></div></div>
  </div>;
}
export default function AiAccountantPage() {
  const { activeBusiness } = useAuth();
  const { confirm: confirmDialog } = useFeedback();
  const [settings, setSettings] = useState(null);
  const [conversations, setConversations] = useState([]);
  const [historyOpen, setHistoryOpen] = useState(false);
  const [conversationId, setConversationId] = useState(null);
  const [lookups, setLookups] = useState({ customers: [], suppliers: [], products: [], bankAccounts: [], categories: [] });
  const [customerMatches, setCustomerMatches] = useState([]);
  const [input, setInput] = useState('');
  const [attachedImage, setAttachedImage] = useState('');
  const [messages, setMessages] = useState([]);
  const [pending, setPending] = useState(null);
  const [busy, setBusy] = useState(false);
  const [listening, setListening] = useState(false);
  const [voiceLanguage, setVoiceLanguage] = useState('en-IN');
  const [voiceReplies, setVoiceReplies] = useState(false);
  const [error, setError] = useState('');
  const [proposalError, setProposalError] = useState('');
  const fileRef = useRef(null);
  const recognitionRef = useRef(null);

  useEffect(() => {
    setMessages([]); setPending(null); setConversationId(null); setError('');
    Promise.all([api.get('/ai-accountant/settings'), api.get('/ai-accountant/conversations'), api.get('/customers', { params: { per_page: 50 } }), api.get('/suppliers', { params: { per_page: 50 } }), api.get('/inventory/products', { params: { per_page: 50 } }), api.get('/inventory/categories'), api.get('/banking/accounts')]).then(([settingsResponse, conversationsResponse, customersResponse, suppliersResponse, productsResponse, categoriesResponse, banksResponse]) => { setSettings(settingsResponse.data); setConversations(conversationsResponse.data.data || []); setLookups({ customers: customersResponse.data.data || [], suppliers: suppliersResponse.data.data || [], products: productsResponse.data.data || [], categories: categoriesResponse.data.data || [], bankAccounts: banksResponse.data.data || [] }); }).catch(() => setSettings({ configured: false }));
  }, [activeBusiness?.id]);
  useEffect(() => () => { recognitionRef.current?.stop(); window.speechSynthesis?.cancel(); }, []);
  useEffect(() => {
    const query = pending?.type === 'sale' ? String(pending.payload?.customer_name || '').trim() : '';
    if (!query) { setCustomerMatches([]); return undefined; }
    let active = true;
    api.get('/customers', { params: { search: query, per_page: 50 } }).then(({ data }) => { if (active) setCustomerMatches(data.data || []); }).catch(() => { if (active) setCustomerMatches([]); });
    return () => { active = false; };
  }, [pending?.type, pending?.payload?.customer_name]);

  const startNewChat = () => { recognitionRef.current?.stop(); window.speechSynthesis?.cancel(); setListening(false); setConversationId(null); setMessages([]); setPending(null); setProposalError(''); setError(''); setHistoryOpen(false); };
  const speakReply = (text) => { if (!voiceReplies || !('speechSynthesis' in window) || !text) return; window.speechSynthesis.cancel(); const utterance = new SpeechSynthesisUtterance(text); utterance.lang = voiceLanguage; utterance.rate = 0.94; utterance.pitch = 1; window.speechSynthesis.speak(utterance); };
  const toggleVoiceReplies = () => { if (!('speechSynthesis' in window)) { setError('Voice replies are not supported in this browser.'); return; } setVoiceReplies((enabled) => { if (enabled) window.speechSynthesis.cancel(); return !enabled; }); };
  const openConversation = async (id) => { try { const { data } = await api.get(`/ai-accountant/conversations/${id}`); const loaded = data.messages || []; setConversationId(data.id); setMessages(loaded.map((message) => ({ role: message.role, content: message.content, action: message.action }))); const lastAction = [...loaded].reverse().find((message) => message.role === 'assistant' && message.action)?.action; setPending(lastAction || null); setHistoryOpen(false); } catch { setError('Could not open this conversation.'); } };
  const deleteConversation = async (id) => { if (!await confirmDialog({ title: 'Delete chat history?', message: 'This conversation will be permanently removed.', confirmLabel: 'Delete chat' })) return; try { await api.delete(`/ai-accountant/conversations/${id}`); setConversations((items) => items.filter((item) => item.id !== id)); if (conversationId === id) startNewChat(); } catch { setError('Could not delete this conversation.'); } };

  const send = async (value = input) => {
    const message = value.trim(); if (!message || busy) return;
    setInput(''); setError(''); setProposalError(''); setPending(null); setBusy(true); setMessages((items) => [...items, { role: 'user', content: message }]);
    try {
      const { data } = await api.post('/ai-accountant/chat', { message, history: messages, conversation_id: conversationId, image: attachedImage || undefined });
      setAttachedImage('');
      setConversationId(data.conversation_id || conversationId);
      setMessages((items) => [...items, { role: 'assistant', content: data.message, kind: data.kind, action: data.action, suggestions: data.suggestions || [] }]);
      speakReply(data.message);
      if (data.action) setPending(data.action);
      api.get('/ai-accountant/conversations').then(({ data: historyData }) => setConversations(historyData.data || [])).catch(() => {});
    } catch (err) { setError(err.response?.data?.message || 'The AI service could not respond. Your books were not changed.'); }
    finally { setBusy(false); }
  };

  const confirm = async () => {
    if (!pending) return;
    const validationError = validateProposal(pending);
    if (validationError) { setProposalError(validationError); return; }
    setBusy(true); setError(''); setProposalError('');
    try { const { data } = await api.post('/ai-accountant/confirm', { action: pending }); setMessages((items) => [...items, { role: 'assistant', content: data.message || 'Entry posted successfully.' }]); setPending(null); }
    catch (err) { setError(err.response?.data?.message || 'The entry needs more details before it can be posted.'); }
    finally { setBusy(false); }
  };

  const updatePending = (action) => { setPending(action); setProposalError(''); };
  const handleCustomerAdded = (customer) => { setLookups((current) => ({ ...current, customers: [...current.customers.filter((item) => item.id !== customer.id), customer].sort((a, b) => a.name.localeCompare(b.name)) })); setPending((current) => current ? { ...current, payload: { ...current.payload, customer_id: customer.id, customer_name: customer.name } } : current); };

  const toggleVoiceLanguage = () => { setVoiceLanguage((language) => language === 'en-IN' ? 'hi-IN' : 'en-IN'); };
  const toggleMic = () => {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) { setError('Voice input is not supported in this browser. You can still type your message.'); return; }
    if (listening) { recognitionRef.current?.stop(); return; }
    const recognition = new SpeechRecognition(); recognition.lang = voiceLanguage; recognition.continuous = false; recognition.interimResults = true; recognition.maxAlternatives = 3;
    recognition.onstart = () => setListening(true); recognition.onend = () => setListening(false);
    recognition.onerror = (event) => { setListening(false); if (event.error !== 'aborted' && event.error !== 'no-speech') setError('Voice input stopped. Please try again or type instead.'); };
    recognition.onresult = (event) => setInput(Array.from(event.results).map((result) => result[0].transcript).join(''));
    recognitionRef.current = recognition;
    try { recognition.start(); } catch { setListening(false); setError('Could not start voice input. Please try again.'); }
  };

  const attachImage = (event) => { const file = event.target.files?.[0]; if (!file) return; const reader = new FileReader(); reader.onload = () => { setAttachedImage(String(reader.result || '')); setInput((value) => `${value}${value ? ' ' : ''}[Receipt attached: ${file.name}]`); }; reader.readAsDataURL(file); };

  return <div className="ai-accountant-page">
    <div className="ai-accountant-heading"><div><span className="eyebrow">KAROBAR ASSISTANT</span><h1>AI Accountant</h1><p>Speak or type in your language. You confirm before anything is posted.</p></div><div className="ai-heading-actions"><button type="button" className="secondary-button" onClick={() => setHistoryOpen(true)}><i className="bx bx-history" /> History</button><button type="button" className="secondary-button" onClick={startNewChat}><i className="bx bx-plus" /> New chat</button><div className="ai-round-actions"><button className="ai-language-toggle" type="button" onClick={toggleVoiceLanguage} title="Switch voice language" aria-label={`Voice language: ${voiceLanguage === 'en-IN' ? 'English India' : 'Hindi India'}`}>{voiceLanguage === 'en-IN' ? 'EN' : 'HI'}</button><button className={voiceReplies ? 'is-active' : ''} title={voiceReplies ? 'Turn off voice replies' : 'Turn on voice replies'} aria-label={voiceReplies ? 'Turn off voice replies' : 'Turn on voice replies'} aria-pressed={voiceReplies} type="button" onClick={toggleVoiceReplies}><i className={`bx ${voiceReplies ? 'bx-volume-full' : 'bx-volume-mute'}`} /></button><button className={listening ? 'is-active is-listening' : ''} title={listening ? 'Stop listening' : 'Start voice input'} aria-label={listening ? 'Stop listening' : 'Start voice input'} aria-pressed={listening} type="button" onClick={toggleMic}><i className="bx bx-microphone" /></button></div></div></div>
    {historyOpen && <div className="ai-history-panel"><div className="ai-history-header"><div><span className="eyebrow">SAVED CONVERSATIONS</span><strong>Your AI Accountant history</strong></div><button type="button" onClick={() => setHistoryOpen(false)}>×</button></div>{conversations.length === 0 ? <p className="ai-history-empty">No saved chats yet.</p> : conversations.map((conversation) => <div className="ai-history-row" key={conversation.id}><button type="button" onClick={() => openConversation(conversation.id)}><strong>{conversation.title}</strong><small>{new Date(conversation.updated_at).toLocaleString()}</small></button><button type="button" className="ai-history-delete" onClick={() => deleteConversation(conversation.id)} title="Delete chat"><i className="bx bx-trash" /></button></div>)}</div>}
    {!settings?.configured && <div className="ai-setup-card"><div><strong>AI Accountant is not configured</strong><p>Ask your system administrator to configure Omega Plus securely on the server. API credentials are never managed or exposed in this dashboard.</p></div><span className="ai-secure-badge"><i className="bx bx-lock-alt" /> Server-managed</span></div>}
    <section className="ai-conversation-card">
      {messages.length === 0 && <div className="ai-welcome"><div className={`ai-mic-hero ${listening ? 'is-listening' : ''}`} onClick={toggleMic} role="button" tabIndex="0" aria-label={listening ? 'Stop listening' : 'Start voice input'}><span className="ai-mic-circle"><i className="bx bx-microphone" /></span><span className={`ai-wave ${listening ? 'is-active' : ''}`} aria-hidden="true">▂▅▃▆▃▇▂▅▃▆▂▅</span><span className="ai-speak-label">{listening ? 'LISTENING…' : 'TAP TO SPEAK'}</span></div><h2>Tell me what happened today.</h2><p>Sales, purchases, expenses, payments — or ask anything about your business.</p><div className="ai-prompts"><span>TRY SAYING…</span><div>{prompts.map((prompt) => <button type="button" key={prompt} onClick={() => send(prompt)}>{prompt}</button>)}</div></div></div>}
      <div className="ai-messages">{messages.map((message, index) => <div key={`${message.role}-${index}`} className={`ai-message-wrap ${message.role}`}><div className={`ai-message ${message.role}`}>{message.content}</div>{message.role === 'assistant' && message.suggestions?.length > 0 && <div className="ai-follow-up"><span>Continue with</span>{message.suggestions.map((suggestion) => <button type="button" key={suggestion} onClick={() => send(suggestion)} disabled={busy}>{suggestion}</button>)}</div>}</div>)}{busy && <div className="ai-message assistant ai-thinking"><i className="bx bx-loader-alt bx-spin" /> Understanding…</div>}</div>
      {pending && <><CustomerResolution action={pending} lookups={lookups} customerMatches={customerMatches} onChange={updatePending} onCustomerAdded={handleCustomerAdded} /><ProposalCard action={pending} onChange={updatePending} onConfirm={confirm} onCancel={() => { setPending(null); setProposalError(''); }} busy={busy} lookups={lookups} /><div className="ai-validation-error" role="alert">{proposalError}</div></>}
    </section>
    <MessageBanner message={error} type="error" onDismiss={() => setError('')} />
    <div className="ai-input-bar"><button type="button" className={`ai-input-mic ${listening ? 'is-listening' : ''}`} onClick={toggleMic}><i className="bx bx-microphone" /></button><button type="button" className="ai-camera" onClick={() => fileRef.current?.click()} title="Attach bill or receipt"><i className="bx bx-camera" /></button><input value={input} onChange={(event) => setInput(event.target.value)} onKeyDown={(event) => event.key === 'Enter' && send()} placeholder="Type what happened in your business…" /><button type="button" className="ai-send" onClick={() => send()} disabled={!input.trim() || busy}><i className="bx bx-up-arrow-alt" /></button><input ref={fileRef} type="file" accept="image/*" capture="environment" hidden onChange={attachImage} /></div>
  </div>;
}
