import { useEffect, useRef, useState } from 'react';
import { useAuth } from '../context/useAuth';
import api from '../api/axios';

const prompts = [
  'Aaj ka update batao', 'Koi galti ya suggestion?', 'Kaun sabse late paise deta hai?',
  'Month-end checklist batao', 'Sharma ko 25 hazaar ka maal becha, cash liya',
  'Aaj ki sale kitni hui?', 'Is mahine ka profit kitna hai?', 'Show my balance sheet',
];
const expenseCategories = ['rent', 'electricity', 'salary', 'fuel', 'transport', 'internet', 'office_expenses', 'repairs', 'advertising', 'travel', 'bank_charges', 'miscellaneous', 'insurance', 'telephone', 'maintenance', 'professional_fees', 'packaging', 'taxes_licenses', 'other'];

function ProposalCard({ action, onChange, onConfirm, onCancel, busy, lookups }) {
  const payload = action.payload || {};
  const isExpense = action.type === 'expense';
  const dateKey = action.type === 'sale' ? 'sale_date' : action.type === 'purchase' ? 'purchase_date' : 'expense_date';
  const items = Array.isArray(payload.items) && payload.items.length ? payload.items : [{ description: '', quantity: 1, unit_rate: '', gst_rate: 0, discount: 0 }];
  const update = (key, value) => onChange({ ...action, payload: { ...payload, [key]: value } });
  const updateItem = (index, key, value) => onChange({ ...action, payload: { ...payload, items: items.map((item, itemIndex) => itemIndex === index ? { ...item, [key]: value } : item) } });
  const updateGst = (value) => onChange({ ...action, payload: { ...payload, items: items.map((item) => ({ ...item, gst_rate: Number(value) })) } });
  const updateProduct = (index, productId) => { const product = lookups.products.find((item) => String(item.id) === String(productId)); onChange({ ...action, payload: { ...payload, items: items.map((item, itemIndex) => itemIndex === index ? { ...item, product_id: productId, ...(product ? { description: product.name, unit_rate: action.type === 'purchase' ? product.purchase_price : product.selling_price, gst_rate: product.gst_rate, hsn_sac: product.hsn_sac || '' } : {}) } : item) } }); };
  const addItem = () => onChange({ ...action, payload: { ...payload, items: [...items, { description: '', quantity: 1, unit_rate: '', gst_rate: 0, discount: 0 }] } });
  const removeItem = (index) => onChange({ ...action, payload: { ...payload, items: items.filter((_, itemIndex) => itemIndex !== index) } });
  const total = isExpense ? Number(payload.amount || 0) : items.reduce((sum, item) => sum + (Number(item.quantity) || 0) * (Number(item.unit_rate ?? item.unit_price) || 0) - (Number(item.discount) || 0), 0);

  return <div className="ai-proposal-card">
    <div className="ai-proposal-heading"><div><span className="eyebrow">{action.type.toUpperCase()} · NEEDS REVIEW</span><strong>{action.type === 'sale' ? 'Sale' : action.type === 'purchase' ? 'Purchase' : 'Expense'} · {isExpense ? (payload.category || 'Uncategorised') : (action.type === 'sale' ? (payload.customer_name || 'Walk-in customer') : (payload.supplier_name || 'No supplier'))}</strong></div><i className="bx bx-edit-alt" /></div>
    <div className="ai-proposal-grid">
      <label>Date<input type="date" value={payload[dateKey] || new Date().toISOString().slice(0, 10)} onChange={(event) => update(dateKey, event.target.value)} /></label>
      {!isExpense && <label>GST %<select value={items[0]?.gst_rate ?? 0} onChange={(event) => updateGst(event.target.value)}><option value="0">0%</option><option value="5">5%</option><option value="12">12%</option><option value="18">18%</option><option value="28">28%</option></select></label>}
      {!isExpense && <label>{action.type === 'sale' ? 'Customer' : 'Supplier'}{action.type === 'sale' ? <select value={payload.customer_id || ''} onChange={(event) => update('customer_id', event.target.value ? Number(event.target.value) : null)}><option value="">Walk-in customer</option>{lookups.customers.map((item) => <option key={item.id} value={item.id}>{item.name}{item.mobile ? ` · ${item.mobile}` : ''}</option>)}</select> : <select value={payload.supplier_id || ''} onChange={(event) => update('supplier_id', event.target.value ? Number(event.target.value) : null)}><option value="">Select supplier</option>{lookups.suppliers.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>}</label>}
      {action.type === 'purchase' && <><label>Supplier bill no.<input value={payload.supplier_bill_number || ''} onChange={(event) => update('supplier_bill_number', event.target.value)} placeholder="Optional" /></label><label>Bill date<input type="date" value={payload.supplier_bill_date || ''} onChange={(event) => update('supplier_bill_date', event.target.value)} /></label></>}
    </div>
    <div className="ai-payment-row"><span>Payment mode</span><div>{(isExpense ? ['cash', 'upi', 'bank', 'card', 'unpaid'] : ['cash', 'upi', 'card', 'bank', 'credit']).map((method) => <button type="button" className={payload.payment_method === method ? 'selected' : ''} key={method} onClick={() => update('payment_method', method)}>{method === 'credit' ? 'Credit (udhaar)' : method.charAt(0).toUpperCase() + method.slice(1)}</button>)}</div></div>
    {payload.payment_method === 'bank' && <label className="ai-bank-field">Bank account<select value={payload.bank_account_id || ''} onChange={(event) => update('bank_account_id', event.target.value ? Number(event.target.value) : null)}><option value="">Select bank account</option>{lookups.bankAccounts.map((item) => <option key={item.id} value={item.id}>{item.name}{item.bank_name ? ` · ${item.bank_name}` : ''}</option>)}</select></label>}
    {isExpense ? <div className="ai-expense-fields"><label>Category<select value={payload.category || 'other'} onChange={(event) => update('category', event.target.value)}>{expenseCategories.map((category) => <option key={category} value={category}>{category.replaceAll('_', ' ')}</option>)}</select></label><label>Supplier (optional)<select value={payload.supplier_id || ''} onChange={(event) => update('supplier_id', event.target.value ? Number(event.target.value) : null)}><option value="">No supplier</option>{lookups.suppliers.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label><label>Amount (₹)<input type="number" min="0" value={payload.amount ?? ''} onChange={(event) => update('amount', Number(event.target.value))} /></label><label>GST %<select value={payload.gst_rate ?? 0} onChange={(event) => update('gst_rate', Number(event.target.value))}><option value="0">0%</option><option value="5">5%</option><option value="12">12%</option><option value="18">18%</option><option value="28">28%</option></select></label><label className="ai-wide-field">Note<input value={payload.note || ''} onChange={(event) => update('note', event.target.value)} placeholder="Optional note" /></label></div> : <div className="ai-items-box"><div className="ai-items-header"><span>Product / item</span><span>Qty</span><span>Rate ₹</span><span /></div>{items.map((item, index) => <div className="ai-item-group" key={index}><select value={item.product_id || ''} onChange={(event) => updateProduct(index, event.target.value)}><option value="">Choose product or enter below</option>{lookups.products.map((product) => <option key={product.id} value={product.id}>{product.name}</option>)}</select><div className="ai-item-row"><input value={item.description || ''} onChange={(event) => updateItem(index, 'description', event.target.value)} placeholder="Item name" /><input type="number" min="0" value={item.quantity ?? ''} onChange={(event) => updateItem(index, 'quantity', Number(event.target.value))} /><input type="number" min="0" value={item.unit_rate ?? item.unit_price ?? ''} onChange={(event) => updateItem(index, 'unit_rate', Number(event.target.value))} placeholder="0" /><button type="button" onClick={() => removeItem(index)} disabled={items.length === 1}>×</button></div><div className="ai-item-extra"><label>Discount<input type="number" min="0" value={item.discount ?? 0} onChange={(event) => updateItem(index, 'discount', Number(event.target.value))} /></label><label>HSN/SAC<input value={item.hsn_sac || ''} onChange={(event) => updateItem(index, 'hsn_sac', event.target.value)} /></label>{action.type === 'purchase' && <><label>Batch no.<input value={item.batch_number || ''} onChange={(event) => updateItem(index, 'batch_number', event.target.value)} /></label><label>Expiry<input type="date" value={item.expiry_date || ''} onChange={(event) => updateItem(index, 'expiry_date', event.target.value)} /></label></>}</div></div>)}<button type="button" className="ai-add-item" onClick={addItem}>＋ Add item</button></div>}
    <div className="ai-options-row">{!isExpense && <label><input type="checkbox" checked={Boolean(payload.prices_include_gst)} onChange={(event) => update('prices_include_gst', event.target.checked)} /> Prices include GST</label>}{action.type === 'sale' && <label><input type="checkbox" checked={Boolean(payload.export_under_lut)} onChange={(event) => update('export_under_lut', event.target.checked)} /> Export under LUT</label>}{(action.type === 'purchase' || isExpense) && <label><input type="checkbox" checked={Boolean(payload.claim_input_tax_credit)} onChange={(event) => update('claim_input_tax_credit', event.target.checked)} /> Claim input GST</label>}{action.type === 'purchase' && <label><input type="checkbox" checked={Boolean(payload.reverse_charge)} onChange={(event) => update('reverse_charge', event.target.checked)} /> Reverse charge</label>}</div>
    {!isExpense && <label className="ai-notes-field">Notes<input value={payload.notes || ''} onChange={(event) => update('notes', event.target.value)} placeholder="Optional notes" /></label>}
    <div className="ai-proposal-footer"><span>{total > 0 ? `Estimated total ₹${total.toLocaleString('en-IN', { maximumFractionDigits: 2 })}` : 'Needs: amount, payment mode'}</span><div><button type="button" className="secondary-button" onClick={onCancel}>× Cancel</button><button type="button" className="primary-button" onClick={onConfirm} disabled={busy || total <= 0}>✓ Confirm</button></div></div>
  </div>;
}

export default function AiAccountantPage() {
  const { activeBusiness } = useAuth();
  const [settings, setSettings] = useState(null);
  const [conversations, setConversations] = useState([]);
  const [historyOpen, setHistoryOpen] = useState(false);
  const [conversationId, setConversationId] = useState(null);
  const [lookups, setLookups] = useState({ customers: [], suppliers: [], products: [], bankAccounts: [] });
  const [input, setInput] = useState('');
  const [attachedImage, setAttachedImage] = useState('');
  const [messages, setMessages] = useState([]);
  const [pending, setPending] = useState(null);
  const [busy, setBusy] = useState(false);
  const [listening, setListening] = useState(false);
  const [error, setError] = useState('');
  const fileRef = useRef(null);
  const recognitionRef = useRef(null);

  useEffect(() => {
    setMessages([]); setPending(null); setConversationId(null); setError('');
    Promise.all([api.get('/ai-accountant/settings'), api.get('/ai-accountant/conversations'), api.get('/customers', { params: { per_page: 50 } }), api.get('/suppliers', { params: { per_page: 50 } }), api.get('/inventory/products', { params: { per_page: 50 } }), api.get('/banking/accounts')]).then(([settingsResponse, conversationsResponse, customersResponse, suppliersResponse, productsResponse, banksResponse]) => { setSettings(settingsResponse.data); setConversations(conversationsResponse.data.data || []); setLookups({ customers: customersResponse.data.data || [], suppliers: suppliersResponse.data.data || [], products: productsResponse.data.data || [], bankAccounts: banksResponse.data.data || [] }); }).catch(() => setSettings({ configured: false }));
  }, [activeBusiness?.id]);

  const startNewChat = () => { setConversationId(null); setMessages([]); setPending(null); setError(''); setHistoryOpen(false); };
  const openConversation = async (id) => { try { const { data } = await api.get(`/ai-accountant/conversations/${id}`); const loaded = data.messages || []; setConversationId(data.id); setMessages(loaded.map((message) => ({ role: message.role, content: message.content, action: message.action }))); const lastAction = [...loaded].reverse().find((message) => message.role === 'assistant' && message.action)?.action; setPending(lastAction || null); setHistoryOpen(false); } catch { setError('Could not open this conversation.'); } };
  const deleteConversation = async (id) => { if (!window.confirm('Delete this chat history?')) return; try { await api.delete(`/ai-accountant/conversations/${id}`); setConversations((items) => items.filter((item) => item.id !== id)); if (conversationId === id) startNewChat(); } catch { setError('Could not delete this conversation.'); } };

  const send = async (value = input) => {
    const message = value.trim(); if (!message || busy) return;
    setInput(''); setError(''); setBusy(true); setMessages((items) => [...items, { role: 'user', content: message }]);
    try {
      const { data } = await api.post('/ai-accountant/chat', { message, history: messages, conversation_id: conversationId, image: attachedImage || undefined });
      setAttachedImage('');
      setConversationId(data.conversation_id || conversationId);
      setMessages((items) => [...items, { role: 'assistant', content: data.message, kind: data.kind, action: data.action }]);
      if (data.action) setPending(data.action);
      api.get('/ai-accountant/conversations').then(({ data: historyData }) => setConversations(historyData.data || [])).catch(() => {});
    } catch (err) { setError(err.response?.data?.message || 'The AI service could not respond. Your books were not changed.'); }
    finally { setBusy(false); }
  };

  const confirm = async () => {
    if (!pending) return; setBusy(true); setError('');
    try { const { data } = await api.post('/ai-accountant/confirm', { action: pending }); setMessages((items) => [...items, { role: 'assistant', content: data.message || 'Entry posted successfully.' }]); setPending(null); }
    catch (err) { setError(err.response?.data?.message || 'The entry needs more details before it can be posted.'); }
    finally { setBusy(false); }
  };

  const updatePending = (action) => setPending(action);

  const toggleMic = () => {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SpeechRecognition) { setError('Voice input is not supported in this browser. You can still type your message.'); return; }
    if (listening) { recognitionRef.current?.stop(); return; }
    const recognition = new SpeechRecognition(); recognition.lang = 'en-IN'; recognition.interimResults = true;
    recognition.onstart = () => setListening(true); recognition.onend = () => setListening(false);
    recognition.onerror = () => { setListening(false); setError('Voice input stopped. Please try again or type instead.'); };
    recognition.onresult = (event) => setInput(Array.from(event.results).map((result) => result[0].transcript).join(''));
    recognitionRef.current = recognition; recognition.start();
  };

  const attachImage = (event) => { const file = event.target.files?.[0]; if (!file) return; const reader = new FileReader(); reader.onload = () => { setAttachedImage(String(reader.result || '')); setInput((value) => `${value}${value ? ' ' : ''}[Receipt attached: ${file.name}]`); }; reader.readAsDataURL(file); };

  return <div className="ai-accountant-page">
    <div className="ai-accountant-heading"><div><span className="eyebrow">KAROBAR ASSISTANT</span><h1>AI Accountant</h1><p>Speak or type in your language. You confirm before anything is posted.</p></div><div className="ai-heading-actions"><button type="button" className="secondary-button" onClick={() => setHistoryOpen(true)}><i className="bx bx-history" /> History</button><button type="button" className="secondary-button" onClick={startNewChat}><i className="bx bx-plus" /> New chat</button><div className="ai-round-actions"><button title="Toggle voice replies" type="button"><i className="bx bx-volume-full" /></button><button title="Voice input" type="button" onClick={toggleMic}><i className="bx bx-headphone" /></button></div></div></div>
    {historyOpen && <div className="ai-history-panel"><div className="ai-history-header"><div><span className="eyebrow">SAVED CONVERSATIONS</span><strong>Your AI Accountant history</strong></div><button type="button" onClick={() => setHistoryOpen(false)}>×</button></div>{conversations.length === 0 ? <p className="ai-history-empty">No saved chats yet.</p> : conversations.map((conversation) => <div className="ai-history-row" key={conversation.id}><button type="button" onClick={() => openConversation(conversation.id)}><strong>{conversation.title}</strong><small>{new Date(conversation.updated_at).toLocaleString()}</small></button><button type="button" className="ai-history-delete" onClick={() => deleteConversation(conversation.id)} title="Delete chat"><i className="bx bx-trash" /></button></div>)}</div>}
    {!settings?.configured && <div className="ai-setup-card"><div><strong>AI Accountant is not configured</strong><p>Ask your system administrator to configure Omega Plus securely on the server. API credentials are never managed or exposed in this dashboard.</p></div><span className="ai-secure-badge"><i className="bx bx-lock-alt" /> Server-managed</span></div>}
    <section className="ai-conversation-card">
      {messages.length === 0 && <div className="ai-welcome"><div className={`ai-mic-hero ${listening ? 'is-listening' : ''}`} onClick={toggleMic}><span className="ai-mic-circle"><i className="bx bx-microphone" /></span><span className="ai-wave">▂▅▃▆▃▇▂▅▃▆▂▅</span><span className="ai-speak-label">{listening ? 'LISTENING…' : 'TAP TO SPEAK'}</span></div><h2>Tell me what happened today.</h2><p>Sales, purchases, expenses, payments — or ask anything about your business.</p><div className="ai-prompts"><span>TRY SAYING…</span><div>{prompts.map((prompt) => <button type="button" key={prompt} onClick={() => send(prompt)}>{prompt}</button>)}</div></div></div>}
      <div className="ai-messages">{messages.map((message, index) => <div key={`${message.role}-${index}`} className={`ai-message ${message.role}`}>{message.content}</div>)}{busy && <div className="ai-message assistant ai-thinking"><i className="bx bx-loader-alt bx-spin" /> Understanding…</div>}</div>
      {pending && <ProposalCard action={pending} onChange={updatePending} onConfirm={confirm} onCancel={() => setPending(null)} busy={busy} lookups={lookups} />}
    </section>
    {error && <div className="ai-error"><i className="bx bx-error-circle" />{error}</div>}
    <div className="ai-input-bar"><button type="button" className={`ai-input-mic ${listening ? 'is-listening' : ''}`} onClick={toggleMic}><i className="bx bx-microphone" /></button><button type="button" className="ai-camera" onClick={() => fileRef.current?.click()} title="Attach bill or receipt"><i className="bx bx-camera" /></button><input value={input} onChange={(event) => setInput(event.target.value)} onKeyDown={(event) => event.key === 'Enter' && send()} placeholder="Type what happened in your business…" /><button type="button" className="ai-send" onClick={() => send()} disabled={!input.trim() || busy}><i className="bx bx-up-arrow-alt" /></button><input ref={fileRef} type="file" accept="image/*" capture="environment" hidden onChange={attachImage} /></div>
  </div>;
}
