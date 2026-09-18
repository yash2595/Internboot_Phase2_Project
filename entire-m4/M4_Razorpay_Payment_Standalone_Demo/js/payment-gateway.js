const qs = new URLSearchParams(location.search);
const candidateId = qs.get('candidate_id') || '1';
let assessmentId = qs.get('assessment_id') || '';
let paymentId = qs.get('payment_id') || '';

const $ = id => document.getElementById(id);
const money = n => new Intl.NumberFormat('en-IN',{style:'currency',currency:'INR',maximumFractionDigits:2}).format(Number(n||0));
function showAlert(message){ $('alert').textContent=message; $('alert').classList.remove('hidden'); }
function hideAlert(){ $('alert').classList.add('hidden'); }
function setStatus(status){ const b=$('statusBadge'); const s=(status||'pending').toLowerCase(); b.textContent=s==='success'?'Paid':s==='pending'?'Pending':'Failed'; b.className='status '+(s==='success'?'success':s==='pending'?'pending':'failed'); }
async function api(action, options={}){
  const res=await fetch(`backend/payment.php?action=${action}`,{headers:{'Accept':'application/json','Content-Type':'application/json'},...options});
  const text=await res.text(); let data; try{data=JSON.parse(text)}catch{throw new Error(text.slice(0,180)||'Server returned an invalid response.')}
  if(!res.ok || !data.success) throw new Error(data.message||'Request failed.'); return data;
}
function submitPayU(data){
  const form=document.createElement('form'); form.method='POST'; form.action=data.payu_url; form.style.display='none'; form.id='payuForm';
  Object.entries(data.form).forEach(([name,value])=>{const input=document.createElement('input');input.type='hidden';input.name=name;input.value=value??'';form.appendChild(input);});
  document.body.appendChild(form); form.submit();
}
async function verifyReturnedPayment(){
  if(!paymentId) return;
  try{
    $('payBtn').disabled=true; $('payBtn').innerHTML='<span>Verifying payment…</span><span>…</span>';
    const result=await api('verify',{method:'POST',body:JSON.stringify({payment_id:Number(paymentId)})});
    setStatus(result.status); if(result.status==='success'){showSuccess(result.payment.reference_number);$('payBtn').innerHTML='<span>Payment Verified</span><span>✓</span>';}
    else {showAlert(result.message);$('payBtn').disabled=false;$('payBtn').innerHTML='<span>Retry Payment</span><span>→</span>';}
  }catch(e){showAlert(e.message);$('payBtn').disabled=false;$('payBtn').innerHTML='<span>Retry Payment</span><span>→</span>';}
}
async function load(){
  try{
    const data=await api(`details&candidate_id=${encodeURIComponent(candidateId)}${assessmentId?`&assessment_id=${encodeURIComponent(assessmentId)}`:''}`);
    assessmentId=String(data.data.assessment.id); $('candidateName').textContent=data.data.candidate.name; $('candidateId').textContent=`IB-CAN-${data.data.candidate.id}`; $('assessmentName').textContent=data.data.assessment.title; $('duration').textContent=`${data.data.assessment.duration} min`; $('questions').textContent=data.data.assessment.questions; $('amount').textContent=money(2999); $('total').textContent=money(2999);
    if(data.data.payment?.status==='success'){setStatus('success');$('payBtn').disabled=true;$('payBtn').innerHTML='<span>Payment Already Completed</span><span>✓</span>';showSuccess(data.data.payment.reference_number);}
    else $('payBtn').disabled=false;
    if(qs.get('payment')==='verifying') await verifyReturnedPayment();
    else if(qs.get('payment') && qs.get('payment')!=='verifying'){setStatus(qs.get('payment'));showAlert(qs.get('payment')==='pending'?'Payment is pending. Enrollment will not be created until payment is verified.':'Payment failed or was cancelled. You can retry.');}
  }catch(e){showAlert(e.message)}
}
async function startPayment(){
  hideAlert();$('payBtn').disabled=true;$('payBtn').innerHTML='<span>Redirecting to PayU…</span><span>…</span>';
  try{const data=await api('create',{method:'POST',body:JSON.stringify({candidate_id:Number(candidateId),assessment_id:Number(assessmentId)})});if(data.already_paid){setStatus('success');showSuccess(data.payment.reference_number);return;} paymentId=String(data.payment_id);submitPayU(data);}catch(e){showAlert(e.message);$('payBtn').disabled=false;$('payBtn').innerHTML='<span>Pay ₹2,999</span><span>→</span>';}
}
function showSuccess(ref){$('successRef').textContent=ref||'—';$('successCard').classList.remove('hidden');window.scrollTo({top:document.body.scrollHeight,behavior:'smooth'});}
$('payBtn').addEventListener('click',startPayment);load();
