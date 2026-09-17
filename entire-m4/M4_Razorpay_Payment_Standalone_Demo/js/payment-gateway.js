const qs = new URLSearchParams(location.search);
const candidateId = qs.get('candidate_id') || '1';
let assessmentId = qs.get('assessment_id') || '';
let paymentSession = null;

const $ = id => document.getElementById(id);
const money = n => new Intl.NumberFormat('en-IN',{style:'currency',currency:'INR',maximumFractionDigits:2}).format(Number(n||0));

function showAlert(message){ $('alert').textContent=message; $('alert').classList.remove('hidden'); }
function hideAlert(){ $('alert').classList.add('hidden'); }
function setStatus(status){ const b=$('statusBadge'); b.textContent=status==='success'?'Paid':status==='pending'?'Pending':'Failed'; b.className='status '+status; }

async function api(action, options={}){
  const res=await fetch(`backend/payment.php?action=${action}`,{headers:{'Accept':'application/json','Content-Type':'application/json'},...options});
  const text=await res.text();
  let data; try{data=JSON.parse(text)}catch{throw new Error(text.slice(0,180)||'Server returned an invalid response.')}
  if(!res.ok || !data.success) throw new Error(data.message||'Request failed.');
  return data;
}

async function load(){
  try{
    const data=await api(`details&candidate_id=${encodeURIComponent(candidateId)}${assessmentId?`&assessment_id=${encodeURIComponent(assessmentId)}`:''}`);
    assessmentId=String(data.data.assessment.id);
    $('candidateName').textContent=data.data.candidate.name;
    $('candidateId').textContent=`IB-CAN-${data.data.candidate.id}`;
    $('assessmentName').textContent=data.data.assessment.title;
    $('duration').textContent=`${data.data.assessment.duration} min`;
    $('questions').textContent=data.data.assessment.questions;
    $('amount').textContent=money(data.data.fee); $('total').textContent=money(data.data.fee);
    if(data.data.payment?.status==='success'){
      setStatus('success'); $('payBtn').disabled=true; $('payBtn').innerHTML='<span>Payment Already Completed</span><span>✓</span>';
      showSuccess(data.data.payment.reference_number);
    }else $('payBtn').disabled=false;
  }catch(e){showAlert(e.message)}
}

async function startPayment(){
  hideAlert(); $('payBtn').disabled=true; $('payBtn').innerHTML='<span>Creating secure payment…</span><span>…</span>';
  try{
    const data=await api('create',{method:'POST',body:JSON.stringify({candidate_id:Number(candidateId),assessment_id:Number(assessmentId)})});
    if(data.already_paid){setStatus('success');showSuccess(data.payment.reference_number);return;}
    paymentSession=data;
    // This is the simulated gateway screen. The verification call is still performed by PHP on the server.
    const ok=window.confirm(`InternBoot Demo Payment\n\nAmount: ${money(data.amount)}\nReference: ${data.reference_number}\n\nClick OK to simulate a successful sandbox payment.`);
    if(!ok){$('payBtn').disabled=false;$('payBtn').innerHTML='<span>Pay Registration Fee</span><span>→</span>';return;}
    $('payBtn').innerHTML='<span>Verifying on server…</span><span>…</span>';
    const verified=await api('verify',{method:'POST',body:JSON.stringify({payment_id:data.payment_id,token:data.token})});
    setStatus('success'); showSuccess(verified.payment.reference_number); $('payBtn').innerHTML='<span>Payment Verified</span><span>✓</span>';
  }catch(e){showAlert(e.message);$('payBtn').disabled=false;$('payBtn').innerHTML='<span>Pay Registration Fee</span><span>→</span>'}
}

function showSuccess(ref){$('successRef').textContent=ref||'—';$('successCard').classList.remove('hidden');window.scrollTo({top:document.body.scrollHeight,behavior:'smooth'})}
$('payBtn').addEventListener('click',startPayment); load();
