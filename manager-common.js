(() => {
'use strict';
window.Manager = (() => {
 const token=document.querySelector('meta[name="csrf-token"]')?.content||'';
 const toastEl=document.getElementById('toast');
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
 const toast=m=>{if(!toastEl)return;toastEl.textContent=m;toastEl.style.display='block';setTimeout(()=>toastEl.style.display='none',2200)};
 const api=(action,payload={})=>fetch('/api/manager.php',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':token},body:JSON.stringify({action,...payload})}).then(r=>r.json());
 const modal=(title,body,submit)=>{
   const d=document.getElementById('editor'),f=document.getElementById('editor-form');if(!d||!f)return;
   f.innerHTML=`<h3>${esc(title)}</h3>${body}<div class="actions"><button class="btn btn-primary" type="submit">حفظ</button><button class="btn btn-muted" type="button" id="cancel">إلغاء</button></div>`;
   d.showModal();f.querySelector('#cancel').onclick=()=>d.close();f.onsubmit=e=>{e.preventDefault();submit(new FormData(f)).then(r=>{if(r?.ok){d.close();toast(r.message||'تم الحفظ');location.reload()}else alert(r?.error||'تعذر التنفيذ')})};
 };
 const table=(headers,rows)=>`<div class="table-wrap"><table class="table"><thead><tr>${headers.map(h=>`<th>${esc(h)}</th>`).join('')}</tr></thead><tbody>${rows||'<tr><td colspan="'+headers.length+'">لا توجد بيانات</td></tr>'}</tbody></table></div>`;
 return {api,modal,toast,esc,table};
})();
})();