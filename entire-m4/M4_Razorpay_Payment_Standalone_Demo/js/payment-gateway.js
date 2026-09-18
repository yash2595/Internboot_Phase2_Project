// Razorpay Payment Demo
const qs = new URLSearchParams(location.search);
const candidateId = qs.get('candidate_id') || '1';
let assessmentId = qs.get('assessment_id') || '';
let paymentId = qs.get('payment_id') || '';

const $ = id => document.getElementById(id);
const money = n => new Intl.NumberFormat('en-IN', {style: 'currency', currency: 'INR', maximumFractionDigits: 2}).format(Number(n || 0));
function showAlert(message){ $('alert').textContent = message; $('alert').classList.remove('hidden'); }
function hideAlert(){ $('alert').classList.add('hidden'); }
function setStatus(status){ const b = $('statusBadge'); const s = (status || 'pending').toLowerCase(); b.textContent = s === 'success' ? 'Paid' : s === 'pending' ? 'Pending' : 'Failed'; b.className = 'status ' + (s === 'success' ? 'success' : s === 'pending' ? 'pending' : 'failed'); }
async function api(action, options = {}){
  const res = await fetch(`/api/payment/payment.php?action=${action}`, {
    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
    ...options
  });
  const text = await res.text();
  let data;
  try { data = JSON.parse(text); } catch { throw new Error(text.slice(0,180) || 'Server returned an invalid response.'); }
  if (!res.ok || data.status !== 'success') throw new Error(data.message || 'Request failed.');
  return data.data;
}
function submitPayU(data){
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = data.payu_url;
  form.style.display = 'none';
  form.id = 'payuForm';
  Object.entries(data.form).forEach(([name, value]) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value ?? '';
    form.appendChild(input);
  });
  document.body.appendChild(form);
  form.submit();
}
async function verifyPayment(){
  try {
    hideAlert();
    const payload = await api('verify', { method: 'POST', body: JSON.stringify({ payment_id: paymentId }) });
    setStatus('success');
    $('paymentDetails').textContent = `Payment ID: ${payload.payment_id}`;
  } catch (e) {
    setStatus('failed');
    showAlert(e.message);
  }
}
async function init(){
  if (paymentId) { await verifyPayment(); return; }
  try {
    const order = await api('create', { method: 'POST', body: JSON.stringify({ candidate_id: candidateId, assessment_id: assessmentId }) });
    submitPayU(order);
  } catch (e) { showAlert(e.message); }
}
init();
