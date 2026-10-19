'use strict';

const Manager=window.Manager||{};
const API_URL='../api/manager.php';

let salonData={
barbers:[],
services:[],
tools:[],
earnings:[],
withdrawals:[],
setting:{barber_percent:50,salon_percent:50}
};

// إرسال طلبات مدير الصالون مع CSRF وإرجاع أخطاء الـAPI بصورة صحيحة.
async function salonApi(action,data={}){
const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
const response=await fetch(API_URL,{
method:'POST',
headers:{
'Content-Type':'application/json',
'X-CSRF-Token':csrf,
'Accept':'application/json'
},
credentials:'same-origin',
body:JSON.stringify({action,...data})
});

const text=await response.text();
let result;

try{
result=JSON.parse(text);
}catch{
console.error('API response:',text);
throw new Error('استجابة غير صالحة من الخادم');
}

if(!response.ok||!result.ok){
throw new Error(result.error||result.message||'حدث خطأ أثناء تنفيذ العملية');
}

return result;
}

// تحميل بيانات الصالون ثم إعادة رسم التبويب الحالي.
async function loadSalonData(){
try{
const result=await salonApi('salon_data');

salonData={
barbers:Array.isArray(result.barbers)?result.barbers:[],
services:Array.isArray(result.services)?result.services:[],
tools:Array.isArray(result.tools)?result.tools:[],
earnings:Array.isArray(result.earnings)?result.earnings:[],
withdrawals:Array.isArray(result.withdrawals)?result.withdrawals:[],
setting:result.setting||{barber_percent:50,salon_percent:50}
};

renderCurrentTab();
return salonData;
}catch(error){
showSalonMessage(error.message,'error');
return null;
}
}

// حماية القيم المعروضة داخل HTML.
function escapeHtml(value){
if(typeof Manager.escape==='function')return Manager.escape(value);

return String(value??'')
.replace(/&/g,'&amp;')
.replace(/</g,'&lt;')
.replace(/>/g,'&gt;')
.replace(/"/g,'&quot;')
.replace(/'/g,'&#039;');
}

// تنسيق المبالغ المالية.
function money(value){
return Number(value||0).toLocaleString('ar-SD',{
minimumFractionDigits:2,
maximumFractionDigits:2
});
}

// عرض رسائل النجاح والأخطاء.
function showSalonMessage(message,type='success'){
if(typeof Manager.toast==='function'){
Manager.toast(message,type);
return;
}

if(typeof window.showToast==='function'){
window.showToast(message,type);
return;
}

console[type==='error'?'error':'log'](message);
}

// استخدام نافذة التأكيد المشتركة.
async function confirmSalon(message){
if(typeof Manager.confirm==='function'){
return await Manager.confirm(message);
}

return window.confirm(message);
}

// معرفة التبويب الحالي وتشغيل الواجهة الخاصة به.
function renderCurrentTab(){
const tab=new URLSearchParams(window.location.search).get('tab')||'dashboard';

if(tab==='dashboard')renderDashboard();
else if(tab==='people')renderBarbers();
else if(tab==='services')renderServices();
else if(tab==='tools')renderTools();
else if(tab==='finance')renderFinance();
else if(tab==='trash')loadTrash();
}

// عرض أعداد العناصر الأساسية.
function renderDashboard(){
const stats=document.getElementById('stats');

if(!stats)return;

stats.innerHTML=`
<div class="stat">الحلاقون<b>${salonData.barbers.length}</b></div>
<div class="stat">الخدمات<b>${salonData.services.length}</b></div>
<div class="stat">الأدوات<b>${salonData.tools.length}</b></div>
<div class="stat">الأرباح<b>${salonData.earnings.length}</b></div>
<div class="stat">السحوبات<b>${salonData.withdrawals.length}</b></div>
`;
}

// بناء عنصر تفاصيل للعرض فقط.
function detailItem(label,value,full=false){
return `
<div class="detail-item${full?' full':''}">
    <span class="detail-label">${escapeHtml(label)}</span>
    <div class="detail-value">${value||'-'}</div>
</div>
`;
}

// فتح نافذة تفاصيل للقراءة فقط.
function openDetails(title,html){
const editor=document.getElementById('editor');
const form=document.getElementById('editor-form');

if(!editor||!form){
showSalonMessage('نافذة التفاصيل غير موجودة في الصفحة','error');
return;
}

form.innerHTML=`
<h3>${escapeHtml(title)}</h3>
<div class="details-grid">
${html}
</div>
<div class="details-actions">
    <button type="button" class="btn btn-muted" data-action="close-editor">
        إغلاق
    </button>
</div>
`;

form.onsubmit=event=>event.preventDefault();

editor.showModal();
}

// عرض تفاصيل الحلاق كاملة بدون تعديل.
function viewBarber(id){
const barber=findBarber(id);

if(!barber){
showSalonMessage('الحلاق غير موجود في البيانات الحالية','error');
return;
}

const available=Number(barber.is_available);

openDetails(`تفاصيل الحلاق: ${barber.name}`,`
${detailItem('المعرف',escapeHtml(barber.id))}
${detailItem('الاسم',escapeHtml(barber.name||'-'))}
${detailItem('رقم الهاتف',escapeHtml(barber.phone||'-'))}
${detailItem('البريد الإلكتروني',escapeHtml(barber.email||'-'))}
${detailItem('التقييم',Number(barber.rating||0).toFixed(1))}
${detailItem('الحالة',`<span class="detail-status ${available?'active':'inactive'}">${available?'نشط':'غير نشط'}</span>`)}
${detailItem('معرف الصالون',escapeHtml(barber.salon_id??'-'))}
${detailItem('الموقع',escapeHtml(barber.location||'-'))}
${detailItem('يجب تغيير كلمة المرور',Number(barber.must_change_password)?'نعم':'لا')}
${detailItem('تاريخ الإنشاء',escapeHtml(barber.created_at||'-'))}
${detailItem('تاريخ الحذف',escapeHtml(barber.deleted_at||'-'))}
`);
}

// عرض تفاصيل الخدمة كاملة بدون تعديل.
function viewService(id){
const service=findService(id);

if(!service){
showSalonMessage('الخدمة غير موجودة في البيانات الحالية','error');
return;
}

openDetails(`تفاصيل الخدمة: ${service.name}`,`
${detailItem('المعرف',escapeHtml(service.id))}
${detailItem('اسم الخدمة',escapeHtml(service.name||'-'))}
${detailItem('السعر',`${money(service.price)}`)}
${detailItem('الحلاق',escapeHtml(service.barber_name||'-'))}
${detailItem('معرف الحلاق',escapeHtml(service.barber_id??'-'))}
${detailItem('المدة',`${escapeHtml(service.duration??'-')} دقيقة`)}
${detailItem('نوع الخدمة',Number(service.is_vip)?'VIP':'عادية')}
${detailItem('السعر المخفض',service.flash_price?money(service.flash_price):'-')}
${detailItem('نهاية العرض',escapeHtml(service.flash_sale_end||'-'))}
${detailItem('تاريخ الإنشاء',escapeHtml(service.created_at||'-'))}
${detailItem('تاريخ الحذف',escapeHtml(service.deleted_at||'-'))}
`);
}

// عرض تفاصيل الأداة كاملة بدون تعديل.
function viewTool(id){
const tool=findTool(id);

if(!tool){
showSalonMessage('الأداة غير موجودة في البيانات الحالية','error');
return;
}

openDetails(`تفاصيل الأداة: ${tool.name}`,`
${detailItem('المعرف',escapeHtml(tool.id))}
${detailItem('اسم الأداة',escapeHtml(tool.name||'-'))}
${detailItem('السعر',money(tool.price))}
${detailItem('الكمية',escapeHtml(tool.quantity??0))}
${detailItem('معرف الصالون',escapeHtml(tool.salon_id??'-'))}
${detailItem('تاريخ الإنشاء',escapeHtml(tool.created_at||'-'))}
`);
}

// عرض تفاصيل المستخدم المحذوف بدون تعديل.
function viewTrashUser(id){
const user=salonData.trashUsers?.find(item=>Number(item.id)===Number(id));

if(!user){
showSalonMessage('المستخدم غير موجود في سلة المهملات الحالية','error');
return;
}

openDetails(`تفاصيل المستخدم: ${user.name||'-'}`,`
${detailItem('المعرف',escapeHtml(user.id))}
${detailItem('الاسم',escapeHtml(user.name||'-'))}
${detailItem('رقم الهاتف',escapeHtml(user.phone||'-'))}
${detailItem('البريد الإلكتروني',escapeHtml(user.email||'-'))}
${detailItem('الصلاحية',escapeHtml(user.role||'-'))}
${detailItem('معرف الصالون',escapeHtml(user.salon_id??'-'))}
${detailItem('الحالة',escapeHtml(user.is_available??'-'))}
${detailItem('التقييم',Number(user.rating||0).toFixed(1))}
${detailItem('الموقع',escapeHtml(user.location||'-'))}
${detailItem('تاريخ الإنشاء',escapeHtml(user.created_at||'-'))}
${detailItem('تاريخ الحذف',escapeHtml(user.deleted_at||'-'))}
`);
}

// بناء جدول الحلاقين مع عرض مختصر للتفاصيل.
function renderBarbers(){
const container=document.getElementById('barbers');

if(!container)return;

const rows=salonData.barbers.map(barber=>{
const available=Number(barber.is_available);

return `
<tr class="clickable-row" data-action="view-barber" data-id="${barber.id}">
    <td>${escapeHtml(barber.name)}</td>
    <td>${escapeHtml(barber.phone)}</td>
    <td>${Number(barber.rating||0).toFixed(1)}</td>
    <td>
        <span class="detail-status ${available?'active':'inactive'}">
            ${available?'نشط':'غير نشط'}
        </span>
    </td>
    <td class="actions">
        <button type="button"
            class="btn btn-sm ${available?'btn-success':'btn-secondary'}"
            data-action="toggle-barber" data-id="${barber.id}">
            ${available?'نشط':'غير نشط'}
        </button>
        <button type="button" class="btn btn-sm btn-edit"
            data-action="edit-barber" data-id="${barber.id}">
            تعديل
        </button>
        <button type="button" class="btn btn-sm btn-danger"
            data-action="delete-barber" data-id="${barber.id}">
            حذف
        </button>
    </td>
</tr>
`;
}).join('');

container.innerHTML=`
<div class="table-wrap">
<table>
<thead>
<tr>
    <th>الاسم</th>
    <th>الهاتف</th>
    <th>التقييم</th>
    <th>الحالة</th>
    <th>الإجراء</th>
</tr>
</thead>
<tbody>
${rows||'<tr><td colspan="5">لا يوجد حلاقون</td></tr>'}
</tbody>
</table>
</div>
`;
}

// بناء جدول الخدمات بتفاصيل مختصرة.
function renderServices(){
const container=document.getElementById('services');

if(!container)return;

const rows=salonData.services.map(service=>`
<tr class="clickable-row" data-action="view-service" data-id="${service.id}">
    <td>${escapeHtml(service.name)}</td>
    <td>${money(service.price)}</td>
    <td>${escapeHtml(service.barber_name||'-')}</td>
    <td>${escapeHtml(service.duration||'-')} دقيقة</td>
    <td>${Number(service.is_vip)?'VIP':'عادية'}</td>
    <td class="actions">
        <button type="button" class="btn btn-sm btn-edit"
            data-action="edit-service" data-id="${service.id}">
            تعديل
        </button>
        <button type="button" class="btn btn-sm btn-danger"
            data-action="delete-service" data-id="${service.id}">
            حذف
        </button>
    </td>
</tr>
`).join('');

container.innerHTML=`
<div class="table-wrap">
<table>
<thead>
<tr>
    <th>الخدمة</th>
    <th>السعر</th>
    <th>الحلاق</th>
    <th>المدة</th>
    <th>النوع</th>
    <th>الإجراء</th>
</tr>
</thead>
<tbody>
${rows||'<tr><td colspan="6">لا توجد خدمات</td></tr>'}
</tbody>
</table>
</div>
`;
}

// بناء جدول الأدوات.
function renderTools(){
const container=document.getElementById('tools');

if(!container)return;

const rows=salonData.tools.map(tool=>`
<tr class="clickable-row" data-action="view-tool" data-id="${tool.id}">
    <td>${escapeHtml(tool.name)}</td>
    <td>${money(tool.price)}</td>
    <td>${escapeHtml(tool.quantity??0)}</td>
    <td class="actions">
        <button type="button" class="btn btn-sm btn-danger"
            data-action="delete-tool" data-id="${tool.id}">
            حذف
        </button>
    </td>
</tr>
`).join('');

container.innerHTML=`
<div class="table-wrap">
<table>
<thead>
<tr>
    <th>الأداة</th>
    <th>السعر</th>
    <th>الكمية</th>
    <th>الإجراء</th>
</tr>
</thead>
<tbody>
${rows||'<tr><td colspan="4">لا توجد أدوات</td></tr>'}
</tbody>
</table>
</div>
`;
}

// حساب رصيد الحلاق.
function getBarberBalance(barberId){
const earned=salonData.earnings
.filter(item=>Number(item.barber_id)===Number(barberId))
.reduce((sum,item)=>sum+Number(item.amount||0),0);

const withdrawn=salonData.withdrawals
.filter(item=>Number(item.barber_id)===Number(barberId))
.reduce((sum,item)=>sum+Number(item.amount||0),0);

return Math.max(0,earned-withdrawn);
}

// عرض البيانات المالية.
function renderFinance(){
const container=document.getElementById('finance');

if(!container)return;

const barberPercent=Number(salonData.setting?.barber_percent??50);
const salonPercent=Number(salonData.setting?.salon_percent??50);

const barberOptions=salonData.barbers.map(barber=>`
<option value="${barber.id}">
${escapeHtml(barber.name)} - الرصيد ${money(getBarberBalance(barber.id))}
</option>
`).join('');

const earnings=salonData.earnings.map(item=>`
<tr>
    <td>${escapeHtml(item.barber_name||'-')}</td>
    <td>${money(item.amount)}</td>
    <td>${escapeHtml(item.date||item.booking_date||'-')}</td>
    <td>${money(item.booking_total||0)}</td>
    <td>${escapeHtml(item.note||'-')}</td>
</tr>
`).join('');

const withdrawals=salonData.withdrawals.map(item=>`
<tr>
    <td>${escapeHtml(item.barber_name||'-')}</td>
    <td>${money(item.amount)}</td>
    <td>${escapeHtml(item.payment_type||'-')}</td>
    <td>${escapeHtml(item.note||'-')}</td>
    <td>${escapeHtml(item.created_at||'-')}</td>
</tr>
`).join('');

container.innerHTML=`
<div class="grid">
<div class="stat">نسبة الحلاق<b>${barberPercent}%</b></div>
<div class="stat">نسبة الصالون<b>${salonPercent}%</b></div>
<div class="stat">عدد الأرباح<b>${salonData.earnings.length}</b></div>
<div class="stat">عدد السحوبات<b>${salonData.withdrawals.length}</b></div>
</div>

<div class="panel">
<h3>تعديل النسب</h3>
<form id="percent-form">
<div class="field">
<label>نسبة الحلاق</label>
<input type="number" name="barber_percent"
min="0" max="100" step="0.01"
value="${escapeHtml(barberPercent)}" required>
<small>نسبة الصالون تحسب تلقائياً من 100%</small>
</div>
<div class="actions">
<button type="submit" class="btn btn-primary">حفظ النسب</button>
</div>
</form>
</div>

<div class="panel">
<h3>تسجيل سحب للحلاق</h3>
<form id="withdraw-form">
<div class="field">
<label>الحلاق</label>
<select name="barber_id" required>
<option value="">اختر الحلاق</option>
${barberOptions}
</select>
</div>

<div class="field">
<label>المبلغ</label>
<input type="number" name="amount"
min="0.01" step="0.01" required>
</div>

<div class="field">
<label>طريقة الدفع</label>
<input name="payment_type" value="نقد" maxlength="50" required>
</div>

<div class="field">
<label>الملاحظة</label>
<input name="note" maxlength="255">
</div>

<div class="actions">
<button type="submit" class="btn btn-primary">تسجيل السحب</button>
</div>
</form>
</div>

<div class="panel">
<h3>الأرباح</h3>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>الحلاق</th>
<th>المبلغ</th>
<th>التاريخ</th>
<th>إجمالي الحجز</th>
<th>الملاحظة</th>
</tr>
</thead>
<tbody>
${earnings||'<tr><td colspan="5">لا توجد أرباح</td></tr>'}
</tbody>
</table>
</div>
</div>

<div class="panel">
<h3>السحوبات</h3>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>الحلاق</th>
<th>المبلغ</th>
<th>طريقة الدفع</th>
<th>الملاحظة</th>
<th>التاريخ</th>
</tr>
</thead>
<tbody>
${withdrawals||'<tr><td colspan="5">لا توجد سحوبات</td></tr>'}
</tbody>
</table>
</div>
</div>
`;
}

// البحث عن العناصر المحملة.
function findBarber(id){
return salonData.barbers.find(item=>Number(item.id)===Number(id));
}

function findService(id){
return salonData.services.find(item=>Number(item.id)===Number(id));
}

function findTool(id){
return salonData.tools.find(item=>Number(item.id)===Number(id));
}

// تحويل تاريخ قاعدة البيانات إلى datetime-local.
function toDateTimeLocal(value){
if(!value)return '';

return String(value)
.replace(' ','T')
.slice(0,16);
}

// فتح نافذة النموذج وربط submit بالنموذج نفسه.
function openEditor(title,html){
const editor=document.getElementById('editor');
const form=document.getElementById('editor-form');

if(!editor||!form){
showSalonMessage('نافذة النموذج غير موجودة في الصفحة','error');
return null;
}

form.innerHTML=`
<h3>${escapeHtml(title)}</h3>
${html}
<div class="actions">
<button type="submit" class="btn btn-primary">حفظ</button>
<button type="button" class="btn" data-action="close-editor">إلغاء</button>
</div>
`;

form.onsubmit=async event=>{
event.preventDefault();

const type=form.querySelector('[name="editor_type"]')?.value||'';

if(type==='barber')await addBarber(form);
else if(type==='edit-barber')await saveBarberEdit(form);
else if(type==='service')await saveService(form);
else if(type==='tool')await saveTool(form);
};

editor.showModal();
return form;
}

// إغلاق نافذة النموذج.
function closeEditor(){
const editor=document.getElementById('editor');

if(editor?.open)editor.close();
}

// فتح نموذج إضافة حلاق.
function openBarberEditor(){
openEditor('إضافة حلاق',`
<input type="hidden" name="editor_type" value="barber">

<div class="field">
<label>اسم الحلاق</label>
<input name="name" maxlength="100" required>
</div>

<div class="field">
<label>رقم الهاتف</label>
<input name="phone" maxlength="30" required>
</div>

<div class="field">
<label>البريد الإلكتروني</label>
<input type="email" name="email" maxlength="190">
</div>

<div class="field">
<label>كلمة المرور</label>
<input type="password" name="password" minlength="8" required>
</div>

<div class="field">
<label>الحالة</label>
<select name="is_available">
<option value="1" selected>متاح</option>
<option value="0">غير متاح</option>
</select>
</div>
`);
}

// فتح نموذج إضافة أو تعديل خدمة.
function openServiceEditor(id=0){
const service=id?findService(id):null;

if(id&&!service){
showSalonMessage('الخدمة غير موجودة في البيانات الحالية','error');
return;
}

const barberOptions=salonData.barbers.map(barber=>`
<option value="${barber.id}"
${service&&Number(service.barber_id)===Number(barber.id)?'selected':''}>
${escapeHtml(barber.name)}
</option>
`).join('');

openEditor(service?'تعديل الخدمة':'إضافة خدمة',`
<input type="hidden" name="editor_type" value="service">
<input type="hidden" name="id" value="${service?.id||0}">

<div class="field">
<label>الحلاق</label>
<select name="barber_id" required>
<option value="">اختر الحلاق</option>
${barberOptions}
</select>
</div>

<div class="field">
<label>اسم الخدمة</label>
<input name="name" maxlength="100"
value="${escapeHtml(service?.name||'')}" required>
</div>

<div class="field">
<label>السعر</label>
<input type="number" min="0" step="0.01"
name="price" value="${escapeHtml(service?.price??'')}" required>
</div>

<div class="field">
<label>المدة بالدقائق</label>
<input type="number" min="1" max="1440"
name="duration" value="${escapeHtml(service?.duration||20)}" required>
</div>

<div class="field">
<label>نوع الخدمة</label>
<select name="is_vip">
<option value="0" ${!service||!Number(service.is_vip)?'selected':''}>عادية</option>
<option value="1" ${service&&Number(service.is_vip)?'selected':''}>VIP</option>
</select>
</div>

<div class="field">
<label>السعر المخفض</label>
<input type="number" min="0" step="0.01"
name="flash_price" value="${escapeHtml(service?.flash_price??'')}">
</div>

<div class="field">
<label>نهاية العرض</label>
<input type="datetime-local" name="flash_sale_end"
value="${escapeHtml(toDateTimeLocal(service?.flash_sale_end))}">
</div>
`);
}

// فتح نموذج إضافة أداة.
function openToolEditor(){
openEditor('إضافة أداة',`
<input type="hidden" name="editor_type" value="tool">

<div class="field">
<label>اسم الأداة</label>
<input name="name" maxlength="100" required>
</div>

<div class="field">
<label>السعر</label>
<input type="number" min="0" step="0.01"
name="price" value="0" required>
</div>

<div class="field">
<label>الكمية</label>
<input type="number" min="1" step="1"
name="quantity" value="1" required>
</div>
`);
}

// إرسال بيانات الحلاق الجديد.
async function addBarber(form){
const formData=new FormData(form);
const name=String(formData.get('name')||'').trim();
const phone=String(formData.get('phone')||'').trim();
const email=String(formData.get('email')||'').trim();
const password=String(formData.get('password')||'');
const isAvailable=Number(formData.get('is_available')||0);

if(!name||!phone||password.length<8){
showSalonMessage('الاسم والهاتف وكلمة المرور من 8 أحرف مطلوبة','error');
return;
}

try{
await salonApi('salon_add_barber',{
name,
phone,
email,
password,
is_available:isAvailable
});

closeEditor();
showSalonMessage('تمت إضافة الحلاق بنجاح');
await loadSalonData();
}catch(error){
showSalonMessage(error.message,'error');
}
}

// فتح نموذج تعديل الحلاق بدون تعديل الحالة من داخل النموذج.
function editBarber(id){
const barber=findBarber(id);

if(!barber){
showSalonMessage('الحلاق غير موجود في البيانات الحالية','error');
return;
}

openEditor('تعديل بيانات الحلاق',`
<input type="hidden" name="editor_type" value="edit-barber">
<input type="hidden" name="id" value="${Number(barber.id)}">

<div class="field">
<label>اسم الحلاق</label>
<input name="name" maxlength="100"
value="${escapeHtml(barber.name||'')}" required>
</div>

<div class="field">
<label>رقم الهاتف</label>
<input name="phone" maxlength="30"
value="${escapeHtml(barber.phone||'')}" required>
</div>

<div class="field">
<label>البريد الإلكتروني</label>
<input type="email" name="email" maxlength="190"
value="${escapeHtml(barber.email||'')}">
</div>

<div class="field">
<label>كلمة مرور جديدة</label>
<input type="password" name="password" minlength="8"
placeholder="اتركها فارغة إذا لا تريد تغييرها">
</div>
`);
}

// إرسال تعديل الحلاق مع الحفاظ على حالته الحالية.
async function saveBarberEdit(form){
const formData=new FormData(form);
const id=Number(formData.get('id')||0);
const name=String(formData.get('name')||'').trim();
const phone=String(formData.get('phone')||'').trim();
const email=String(formData.get('email')||'').trim();
const password=String(formData.get('password')||'');
const barber=findBarber(id);
const isAvailable=barber?Number(barber.is_available):0;

if(!id||!name||!phone){
showSalonMessage('الاسم ورقم الهاتف مطلوبان','error');
return;
}

if(password!==''&&password.length<8){
showSalonMessage('كلمة المرور الجديدة يجب أن تكون 8 أحرف على الأقل','error');
return;
}

try{
const result=await salonApi('salon_edit_barber',{
id,
name,
phone,
email,
password,
is_available:isAvailable
});

if(result.barber){
const index=salonData.barbers.findIndex(item=>Number(item.id)===id);

if(index!==-1){
salonData.barbers[index]={
...salonData.barbers[index],
...result.barber
};
}
}

closeEditor();
showSalonMessage('تم تعديل بيانات الحلاق');
renderCurrentTab();
}catch(error){
showSalonMessage(error.message,'error');
}
}

// تغيير حالة الحلاق مباشرة من زر الحالة.
async function toggleBarber(id){
const barber=findBarber(id);

if(!barber)return;

const current=Number(barber.is_available)?1:0;
const next=current?0:1;

try{
const result=await salonApi('salon_edit_barber',{
id:Number(id),
name:String(barber.name||''),
phone:String(barber.phone||''),
email:String(barber.email||''),
password:'',
is_available:next
});

if(result.barber){
const index=salonData.barbers.findIndex(item=>Number(item.id)===Number(id));

if(index!==-1){
salonData.barbers[index]={
...salonData.barbers[index],
...result.barber
};
}
}else{
barber.is_available=next;
}

renderBarbers();
showSalonMessage(next?'تم تفعيل الحلاق':'تم إيقاف الحلاق');
}catch(error){
showSalonMessage(error.message,'error');
}
}

// نقل الحلاق إلى السلة.
async function deleteBarber(id){
const barber=findBarber(id);

if(!barber)return;

const confirmed=await confirmSalon(`هل تريد حذف الحلاق "${barber.name}"؟`);

if(!confirmed)return;

try{
await salonApi('salon_delete_barber',{id:Number(id)});
showSalonMessage('تم حذف الحلاق');
await loadSalonData();
}catch(error){
showSalonMessage(error.message,'error');
}
}

// تجهيز بيانات الخدمة وإرسالها للـAPI.
async function saveService(form){
const formData=new FormData(form);
const id=Number(formData.get('id')||0);
const barberId=Number(formData.get('barber_id')||0);
const name=String(formData.get('name')||'').trim();
const price=String(formData.get('price')??'').trim();
const duration=Number(formData.get('duration')||0);
const isVip=Number(formData.get('is_vip')||0);
const flashPrice=String(formData.get('flash_price')??'').trim();
const flashEnd=String(formData.get('flash_sale_end')??'').trim();

if(!barberId||!name||price===''){
showSalonMessage('بيانات الخدمة غير مكتملة','error');
return;
}

if(!Number.isFinite(Number(price))||Number(price)<0){
showSalonMessage('سعر الخدمة غير صحيح','error');
return;
}

if(!Number.isInteger(duration)||duration<1||duration>1440){
showSalonMessage('مدة الخدمة يجب أن تكون بين دقيقة و1440 دقيقة','error');
return;
}

if(flashPrice!==''&&(!Number.isFinite(Number(flashPrice))||Number(flashPrice)<0)){
showSalonMessage('السعر المخفض غير صحيح','error');
return;
}

const payload={
id,
barber_id:barberId,
name,
price,
duration,
is_vip:isVip,
flash_price:flashPrice,
flash_sale_end:flashEnd
};

try{
const result=await salonApi('salon_save_service',payload);

if(result.service){
const index=salonData.services.findIndex(item=>Number(item.id)===id);

if(id&&index!==-1){
salonData.services[index]={
...salonData.services[index],
...result.service
};
}else if(result.service.id){
salonData.services.unshift(result.service);
}
}

closeEditor();
showSalonMessage(id?'تم تعديل الخدمة':'تمت إضافة الخدمة');
renderCurrentTab();
}catch(error){
showSalonMessage(error.message,'error');
}
}

// حذف الخدمة.
async function deleteService(id){
const service=findService(id);

if(!service)return;

const confirmed=await confirmSalon(`هل تريد حذف الخدمة "${service.name}"؟`);

if(!confirmed)return;

try{
await salonApi('salon_delete_service',{id:Number(id)});
showSalonMessage('تم حذف الخدمة');
await loadSalonData();
}catch(error){
showSalonMessage(error.message,'error');
}
}

// إضافة أداة.
async function saveTool(form){
const formData=new FormData(form);
const name=String(formData.get('name')||'').trim();
const price=formData.get('price')||0;
const quantity=Number(formData.get('quantity')||0);

if(!name||Number(price)<0||!Number.isInteger(quantity)||quantity<1){
showSalonMessage('بيانات الأداة غير صحيحة','error');
return;
}

try{
await salonApi('salon_tool',{name,price,quantity});
closeEditor();
showSalonMessage('تمت إضافة الأداة');
await loadSalonData();
}catch(error){
showSalonMessage(error.message,'error');
}
}

// حذف الأداة.
async function deleteTool(id){
const tool=findTool(id);

if(!tool)return;

const confirmed=await confirmSalon(`هل تريد حذف الأداة "${tool.name}"؟`);

if(!confirmed)return;

try{
await salonApi('salon_delete_tool',{id:Number(id)});
showSalonMessage('تم حذف الأداة');
await loadSalonData();
}catch(error){
showSalonMessage(error.message,'error');
}
}

// حفظ نسبة الحلاق.
async function savePercent(form){
const formData=new FormData(form);
const barberPercent=Number(formData.get('barber_percent'));

if(!Number.isFinite(barberPercent)||barberPercent<0||barberPercent>100){
showSalonMessage('النسبة يجب أن تكون بين 0 و100','error');
return;
}

try{
await salonApi('salon_percent',{barber_percent:barberPercent});
showSalonMessage('تم حفظ النسب');
await loadSalonData();
}catch(error){
showSalonMessage(error.message,'error');
}
}

// تسجيل السحب.
async function saveWithdrawal(form){
const formData=new FormData(form);
const barberId=Number(formData.get('barber_id')||0);
const amount=Number(formData.get('amount')||0);
const paymentType=String(formData.get('payment_type')||'').trim();
const note=String(formData.get('note')||'').trim();

if(!barberId||!Number.isFinite(amount)||amount<=0){
showSalonMessage('أدخل الحلاق والمبلغ بصورة صحيحة','error');
return;
}

try{
await salonApi('salon_withdraw',{
barber_id:barberId,
amount,
payment_type:paymentType,
note
});

showSalonMessage('تم تسجيل السحب');
await loadSalonData();
}catch(error){
showSalonMessage(error.message,'error');
}
}

// تحميل بيانات سلة المهملات.
async function loadTrash(){
const container=document.getElementById('trash-users');

if(!container)return;

container.innerHTML='<div class="panel">جاري تحميل سلة المهملات...</div>';

try{
const result=await salonApi('trash_users');
const users=Array.isArray(result.users)?result.users:[];

salonData.trashUsers=users;

const rows=users.map(user=>`
<tr class="clickable-row" data-action="view-trash-user" data-id="${user.id}">
<td>${escapeHtml(user.name||'-')}</td>
<td>${escapeHtml(user.role||'-')}</td>
<td>${escapeHtml(user.deleted_at||'-')}</td>
<td><span class="detail-status inactive">محذوف</span></td>
<td class="actions">
<button type="button" class="btn btn-sm btn-success"
data-action="restore-user" data-id="${user.id}">
استرجاع
</button>
<button type="button" class="btn btn-sm btn-danger"
data-action="permanent-delete-user" data-id="${user.id}">
حذف نهائي
</button>
</td>
</tr>
`).join('');

container.innerHTML=`
<div class="panel">
<h3>المستخدمون المحذوفون</h3>
<div class="table-wrap">
<table>
<thead>
<tr>
<th>الاسم</th>
<th>الصلاحية</th>
<th>تاريخ الحذف</th>
<th>الحالة</th>
<th>الإجراء</th>
</tr>
</thead>
<tbody>
${rows||'<tr><td colspan="5">سلة المهملات فارغة</td></tr>'}
</tbody>
</table>
</div>
</div>
`;
}catch(error){
container.innerHTML='';
showSalonMessage(error.message,'error');
}
}

// استرجاع مستخدم من سلة المهملات.
async function restoreUser(id){
const confirmed=await confirmSalon('هل تريد استرجاع هذا المستخدم؟');

if(!confirmed)return;

try{
await salonApi('restore_user',{id:Number(id)});
showSalonMessage('تم استرجاع المستخدم');
await loadTrash();
}catch(error){
showSalonMessage(error.message,'error');
}
}

// حذف المستخدم نهائياً.
async function permanentDeleteUser(id){
const confirmed=await confirmSalon(
'هل تريد حذف هذا المستخدم نهائياً؟ لا يمكن التراجع عن هذه العملية.'
);

if(!confirmed)return;

try{
await salonApi('permanent_delete_user',{id:Number(id)});
showSalonMessage('تم حذف المستخدم نهائياً');
await loadTrash();
}catch(error){
showSalonMessage(error.message,'error');
}
}

// ربط أزرار مدير الصالون والنماذج العامة.
function bindSalonEvents(){
document.addEventListener('click',async event=>{
const row=event.target.closest('tr[data-action]');

if(row&&!event.target.closest('button,a')){
const rowAction=row.dataset.action;
const rowId=Number(row.dataset.id||0);

if(rowAction==='view-barber')viewBarber(rowId);
else if(rowAction==='view-service')viewService(rowId);
else if(rowAction==='view-tool')viewTool(rowId);
else if(rowAction==='view-trash-user')viewTrashUser(rowId);

return;
}

const button=event.target.closest('button,a');

if(!button)return;

if(button.id==='new-barber'){
event.preventDefault();
openBarberEditor();
return;
}

if(button.id==='new-service'){
event.preventDefault();
openServiceEditor();
return;
}

if(button.id==='new-tool'){
event.preventDefault();
openToolEditor();
return;
}

if(button.dataset.action==='close-editor'){
event.preventDefault();
closeEditor();
return;
}

const action=button.dataset.action;
const id=Number(button.dataset.id||0);

if(action==='edit-barber')editBarber(id);
else if(action==='toggle-barber')await toggleBarber(id);
else if(action==='delete-barber')await deleteBarber(id);
else if(action==='edit-service')openServiceEditor(id);
else if(action==='delete-service')await deleteService(id);
else if(action==='delete-tool')await deleteTool(id);
else if(action==='restore-user')await restoreUser(id);
else if(action==='permanent-delete-user')await permanentDeleteUser(id);
else if(action==='refresh-salon')await loadSalonData();
});

// النماذج الموجودة خارج نافذة المحرر تبقى على مستمع الصفحة.
document.addEventListener('submit',async event=>{
const form=event.target;

if(form.id==='percent-form'){
event.preventDefault();
await savePercent(form);
return;
}

if(form.id==='withdraw-form'){
event.preventDefault();
await saveWithdrawal(form);
}
});
}

// تشغيل لوحة مدير الصالون.
document.addEventListener('DOMContentLoaded',async()=>{
bindSalonEvents();
await loadSalonData();
});