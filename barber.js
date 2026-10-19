(() => {
'use strict';

const M=window.Manager;
let DATA={};

const dayNames=['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'];

// تحميل بيانات الحلاق وعرض محتوى التبويب الموجود فقط.
async function loadBarber(){
    const d=await M.api('barber_data');

    if(!d.ok)return alert(d.error);

    DATA=d;

    const stats=document.getElementById('stats');
    const bookings=document.getElementById('bookings');
    const schedule=document.getElementById('schedule');
    const accounting=document.getElementById('accounting');

    // عرض إحصائيات الحلاق في تبويب لوحتي.
    if(stats){
        stats.innerHTML=`
            <div class="stat">الحجوزات<b>${d.bookings.length}</b></div>
            <div class="stat">المستحق<b>${Number(d.earned).toFixed(2)} ج</b></div>
            <div class="stat">المسحوب<b>${Number(d.withdrawn).toFixed(2)} ج</b></div>
            <div class="stat">الرصيد<b>${Number(d.balance).toFixed(2)} ج</b></div>
        `;
    }

    // عرض الحسابات المالية في تبويب الحسابات فقط.
    if(accounting){
        accounting.innerHTML=`
            <div class="stat">إجمالي المستحق<b>${Number(d.earned).toFixed(2)} ج</b></div>
            <div class="stat">إجمالي المسحوب<b>${Number(d.withdrawn).toFixed(2)} ج</b></div>
            <div class="stat">الرصيد الحالي<b>${Number(d.balance).toFixed(2)} ج</b></div>
            <div class="stat">عدد الحجوزات<b>${d.bookings.length}</b></div>
        `;
    }

    // عرض الحجوزات وأزرار تغيير حالتها في تبويب الحجوزات فقط.
    if(bookings){
        bookings.innerHTML=M.table(
            ['التاريخ','الوقت','الزبون','الخدمة','المبلغ','الحالة','إجراء'],
            (d.bookings||[]).map(b=>{
                const action=b.status==='pending'
                    ?`<button class="btn btn-primary status" data-id="${b.id}" data-status="confirmed">تأكيد</button>`
                    :b.status==='confirmed'
                        ?`<button class="btn btn-success status" data-id="${b.id}" data-status="completed">إكمال</button>`
                        :'';

                return `<tr>
                    <td>${M.esc(b.date)}</td>
                    <td>${M.esc(b.start_time)} - ${M.esc(b.end_time)}</td>
                    <td>${M.esc(b.customer_name)}</td>
                    <td>${M.esc(b.service_name||'—')}</td>
                    <td>${b.total_amount}</td>
                    <td>${M.esc(b.status)}</td>
                    <td>${action} <button class="btn btn-danger status" data-id="${b.id}" data-status="cancelled">إلغاء</button></td>
                </tr>`;
            }).join('')
        );
    }

    // عرض أيام وأوقات العمل في تبويب الجدول الأسبوعي فقط.
    if(schedule){
        schedule.innerHTML=(d.schedule||[]).map(s=>{
            const day=dayNames[Number(s.day_of_week)]||`اليوم ${s.day_of_week}`;

            return `
                <div class="inline-form" style="margin:7px 0">
                    <b>${day}</b>
                    <input data-day="${s.day_of_week}" data-k="start"
                        value="${s.start_time.slice(0,5)}" type="time">
                    <input data-day="${s.day_of_week}" data-k="end"
                        value="${s.end_time.slice(0,5)}" type="time">
                    <label>
                        <input data-day="${s.day_of_week}" data-k="off"
                            type="checkbox" ${s.is_off?'checked':''}> إجازة
                    </label>
                </div>
            `;
        }).join('');
    }
}

// تنفيذ تغيير حالة الحجز ثم تحديث التبويب الحالي.
document.addEventListener('click',async e=>{
    const button=e.target.closest('.status');

    if(!button)return;

    const r=await M.api('barber_booking_status',{
        id:button.dataset.id,
        status:button.dataset.status
    });

    if(!r.ok)return alert(r.error);

    loadBarber();
});

// حفظ جدول الحلاق عند وجود تبويب الجدول الأسبوعي.
const saveSchedule=document.getElementById('save-schedule');

if(saveSchedule){
    saveSchedule.onclick=async()=>{
        const days={};

        document.querySelectorAll('#schedule [data-day]').forEach(x=>{
            const d=x.dataset.day;

            days[d]??={};

            if(x.dataset.k==='off'){
                days[d].is_off=x.checked?1:0;
            }else{
                days[d][x.dataset.k]=x.value;
            }
        });

        const r=await M.api('barber_schedule',{days});

        alert(r.error||r.message);

        if(r.ok)loadBarber();
    };
}

loadBarber();
})();