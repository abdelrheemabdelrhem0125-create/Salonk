'use strict';

(() => {
const API_URL='/api/public.php';
const token=document.querySelector('meta[name="csrf-token"]')?.content||'';

const $=selector=>document.querySelector(selector);

const esc=value=>String(value??'').replace(/[&<>"']/g,char=>({
    '&':'&amp;',
    '<':'&lt;',
    '>':'&gt;',
    '"':'&quot;',
    "'":'&#039;'
}[char]));

let all=[];
let current=null;
let selected=[];
let offers={flash:[],vip:[]};
let activeTab='home';


/* إرسال طلبات العميل إلى API العام. */
async function request(action,payload={},post=false){
    try{
        const params={action,...payload};

        const options={
            method:post?'POST':'GET',
            headers:{}
        };

        if(post){
            options.headers['Content-Type']='application/json';

            if(token){
                options.headers['X-CSRF-Token']=token;
            }

            options.body=JSON.stringify(params);
        }

        const query=post
            ? ''
            : '?'+new URLSearchParams(
                Object.entries(params).reduce((result,[key,value])=>{
                    if(value!==undefined&&value!==null){
                        result[key]=String(value);
                    }
                    return result;
                },{})
            );

        const response=await fetch(API_URL+query,options);
        const text=await response.text();

        let data;

        try{
            data=JSON.parse(text);
        }catch(error){
            console.error('استجابة API غير صالحة:',text);

            return {
                ok:false,
                status:response.status,
                error:'الخادم أرسل استجابة غير صالحة'
            };
        }

        if(!response.ok||data.ok===false){
            return {
                ...data,
                ok:false,
                status:response.status,
                error:data.error||'تعذر تنفيذ الطلب'
            };
        }

        return data;
    }catch(error){
        console.error('خطأ في طلب API:',error);

        return {
            ok:false,
            error:'تعذر الاتصال بالخادم'
        };
    }
}


/* إنشاء نافذة منبثقة بسيطة قابلة للإغلاق. */
function modal(html){
    document.querySelectorAll('.modal').forEach(item=>item.remove());

    const m=document.createElement('div');
    m.className='modal';

    m.innerHTML=`
        <div class="modal-card">
            ${html}
            <button type="button" class="modal-close">إغلاق</button>
        </div>
    `;

    m.addEventListener('click',event=>{
        if(event.target===m){
            m.remove();
        }
    });

    const close=m.querySelector('.modal-close');

    if(close){
        close.addEventListener('click',()=>{
            m.remove();
        });
    }

    document.body.appendChild(m);

    return m;
}


/* التحقق من صلاحية العرض المخفض قبل استخدام سعره. */
function isFlashActive(service){
    if(
        service.flash_price===null||
        service.flash_price===undefined||
        service.flash_price===''
    ){
        return false;
    }

    if(!service.flash_sale_end){
        return false;
    }

    const value=String(service.flash_sale_end)
        .replace(' ','T');

    const end=new Date(value);

    return !Number.isNaN(end.getTime())&&end.getTime()>Date.now();
}


function servicePrice(service){
    return isFlashActive(service)
        ? Number(service.flash_price||0)
        : Number(service.price||0);
}


/* إظهار تبويب واحد وإخفاء بقية أقسام العميل. */
async function switchTab(tab){
    const allowed=[
        'home',
        'vip',
        'offers',
        'loyalty'
    ];

    if(!allowed.includes(tab)){
        tab='home';
    }

    activeTab=tab;

    document.querySelectorAll('.customer-tab-content')
        .forEach(section=>{
            section.classList.toggle(
                'active',
                section.id===`customer-tab-${tab}`
            );
        });

    document.querySelectorAll('[data-menu-tab]')
        .forEach(item=>{
            item.classList.toggle(
                'active',
                item.dataset.menuTab===tab
            );
        });

    closeCustomerMenu();

    if(tab==='vip'||tab==='offers'){
        await loadOffers();
    }

    if(tab==='loyalty'){
        await loadCustomerInfo();
    }
}


/* إغلاق قائمة الثلاثة خطوط. */
function closeCustomerMenu(){
    const menu=$('#customer-menu');
    const button=$('#customer-menu-btn');

    if(menu){
        menu.classList.remove('open');
    }

    if(button){
        button.classList.remove('open');
        button.setAttribute('aria-expanded','false');
    }
}


/* تشغيل قائمة الثلاثة خطوط والتبويبات الجديدة. */
function initMenu(){
    const button=$('#customer-menu-btn');
    const menu=$('#customer-menu');

    if(!button||!menu){
        return;
    }

    button.addEventListener('click',event=>{
        event.stopPropagation();

        const open=menu.classList.toggle('open');

        button.classList.toggle('open',open);
        button.setAttribute(
            'aria-expanded',
            open?'true':'false'
        );
    });

    menu.querySelectorAll('[data-menu-tab]')
        .forEach(item=>{
            item.addEventListener('click',async()=>{
                const tab=item.dataset.menuTab;

                if(tab){
                    await switchTab(tab);
                }
            });
        });

    document.addEventListener('click',event=>{
        if(
            !menu.contains(event.target)&&
            !button.contains(event.target)
        ){
            closeCustomerMenu();
        }
    });
}


/* عرض رسالة التحميل أو الخطأ داخل القائمة. */
function setContainerMessage(container,message,empty=false){
    if(!container){
        return;
    }

    container.innerHTML=`
        <div class="card${empty?' empty':''}">
            ${esc(message)}
        </div>
    `;
}


/* عرض الحلاقين مع بيانات الصالون والتقييم. */
function render(list){
    const container=$('#barbers');

    if(!container){
        return;
    }

    if(!Array.isArray(list)||!list.length){
        setContainerMessage(
            container,
            'لا يوجد حلاقون متاحون حالياً.',
            true
        );
        return;
    }

    container.innerHTML=list.map(barber=>{
        const rating=Number(
            barber.rating??0
        ).toFixed(1);

        const available=
            Number(barber.is_available)===1;

        return `
            <article
                class="card barber-card"
                data-id="${Number(barber.id)}"
                tabindex="0"
                role="button"
                aria-label="اختيار الحلاق ${esc(barber.name)}">
                <img
                    src="/hero.png"
                    alt=""
                    loading="lazy">
                <div>
                    <h3>${esc(barber.name)}</h3>
                    <p>
                        ⭐ ${esc(rating)}
                        ·
                        ${available?'متاح':'غير متاح'}
                    </p>
                    <small>
                        ${esc(
                            barber.salon_name||'مستقل'
                        )}
                        ${
                            barber.salon_location
                                ? ' · '+
                                  esc(barber.salon_location)
                                : ''
                        }
                    </small>
                </div>
            </article>
        `;
    }).join('');

    container.querySelectorAll('.barber-card')
        .forEach(card=>{
            const open=()=>{
                openBarber(
                    Number(card.dataset.id)
                );
            };

            card.addEventListener('click',open);

            card.addEventListener(
                'keydown',
                event=>{
                    if(
                        event.key==='Enter'||
                        event.key===' '
                    ){
                        event.preventDefault();
                        open();
                    }
                }
            );
        });
}


/* تحميل قائمة الحلاقين من API العام. */
async function loadBarbers(){
    const container=$('#barbers');

    if(!container){
        console.error(
            'عنصر #barbers غير موجود في صفحة العميل'
        );
        return;
    }

    setContainerMessage(
        container,
        'جاري تحميل الحلاقين...'
    );

    const result=await request('barbers');

    if(!result.ok){
        console.error(
            'فشل تحميل الحلاقين:',
            result
        );

        setContainerMessage(
            container,
            result.error||
            'تعذر تحميل الحلاقين'
        );

        return;
    }

    all=Array.isArray(result.barbers)
        ? result.barbers
        : [];

    render(all);
}


/* تحميل العروض وخدمات VIP من API العام. */
async function loadOffers(){
    const vipBox=$('#customer-vip');
    const offersBox=$('#customer-offers');

    if(
        (!vipBox&&!offersBox)||
        (offers.flash.length&&offers.vip.length)
    ){
        return;
    }

    if(vipBox){
        setContainerMessage(
            vipBox,
            'جاري تحميل خدمات VIP...'
        );
    }

    if(offersBox){
        setContainerMessage(
            offersBox,
            'جاري تحميل العروض...'
        );
    }

    const result=await request('offers');

    if(!result.ok){
        const message=
            result.error||
            'تعذر تحميل الخدمات والعروض';

        if(vipBox){
            setContainerMessage(
                vipBox,
                message,
                true
            );
        }

        if(offersBox){
            setContainerMessage(
                offersBox,
                message,
                true
            );
        }

        return;
    }

    offers={
        flash:Array.isArray(result.flash)
            ? result.flash
            : [],
        vip:Array.isArray(result.vip)
            ? result.vip
            : []
    };

    renderVip();
    renderFlashOffers();
}


/* عرض خدمات VIP فقط. */
function renderVip(){
    const box=$('#customer-vip');

    if(!box){
        return;
    }

    const vip=offers.vip||[];

    if(!vip.length){
        setContainerMessage(
            box,
            'لا توجد خدمات VIP حالياً.',
            true
        );
        return;
    }

    box.innerHTML=vip.map(service=>`
        <article class="card offer-item">
            <span class="badge">👑 VIP</span>

            <h3>
                ${esc(service.name)}
            </h3>

            <p>
                الحلاق:
                ${esc(service.barber_name)}
            </p>

            <strong>
                ${esc(service.price)} ج
            </strong>
        </article>
    `).join('');
}


/* عرض العروض المخفضة فقط. */
function renderFlashOffers(){
    const box=$('#customer-offers');

    if(!box){
        return;
    }

    const flash=offers.flash||[];

    if(!flash.length){
        setContainerMessage(
            box,
            'لا توجد عروض مخفضة حالياً.',
            true
        );
        return;
    }

    box.innerHTML=flash.map(service=>`
        <article class="card offer-item">
            <span class="badge">🔥 عرض مخفض</span>

            <h3>
                ${esc(service.name)}
            </h3>

            <p>
                الحلاق:
                ${esc(service.barber_name)}
            </p>

            <p>
                <del>
                    ${esc(service.price)} ج
                </del>

                <strong>
                    ⚡ ${esc(service.flash_price)} ج
                </strong>
            </p>

            <small>
                ينتهي العرض:
                ${esc(service.flash_sale_end)}
            </small>
        </article>
    `).join('');
}


/* فتح خدمات الحلاق مع إبقاء الحجز داخل نفس المسار. */
async function openBarber(id){
    const barber=all.find(
        item=>Number(item.id)===Number(id)
    );

    if(!barber){
        return;
    }

    if(Number(barber.is_available)!==1){
        alert('هذا الحلاق غير متاح حالياً');
        return;
    }

    const result=await request(
        'services',
        {barber_id:id}
    );

    if(!result.ok){
        alert(
            result.error||
            'تعذر تحميل خدمات الحلاق'
        );
        return;
    }

    current={
        barber,
        services:Array.isArray(result.services)
            ? result.services
            : []
    };

    selected=[];

    const m=modal(`
        <h2>${esc(barber.name)}</h2>

        <p>
            ⭐ تقييم الحلاق:
            ${esc(
                Number(
                    barber.rating??0
                ).toFixed(1)
            )}
        </p>

        <div id="service-list"></div>

        <div id="summary">
            اختر خدمة
        </div>

        <button
            type="button"
            class="btn primary"
            id="book">
            اختيار الموعد
        </button>
    `);

    const list=m.querySelector('#service-list');

    if(!current.services.length){
        list.innerHTML=`
            <p>
                لا توجد خدمات لهذا الحلاق حالياً.
            </p>
        `;
    }else{
        list.innerHTML=
            current.services.map(service=>{
                const flash=isFlashActive(service);
                const price=servicePrice(service);

                return `
                    <label class="service-option">
                        <input
                            type="checkbox"
                            value="${Number(service.id)}">

                        <span>
                            <strong>
                                ${esc(service.name)}
                            </strong>

                            ${
                                Number(service.is_vip)
                                    ? '<span> 👑 VIP</span>'
                                    : ''
                            }

                            <br>

                            ${
                                flash
                                    ? `
                                        <del>
                                            ${esc(
                                                service.price
                                            )} ج
                                        </del>

                                        <strong>
                                            ⚡ ${esc(price)} ج
                                        </strong>
                                    `
                                    : `${esc(price)} ج`
                            }

                            ·
                            ${esc(service.duration)}
                            دقيقة

                            ${
                                flash
                                    ? `
                                        <br>
                                        <small>
                                            عرض فلاش حتى
                                            ${esc(
                                                service.flash_sale_end
                                            )}
                                        </small>
                                    `
                                    : ''
                            }
                        </span>
                    </label>
                `;
            }).join('');

        list.querySelectorAll(
            'input[type="checkbox"]'
        ).forEach(input=>{
            input.addEventListener(
                'change',
                ()=>{
                    selected=[
                        ...list.querySelectorAll(
                            'input[type="checkbox"]:checked'
                        )
                    ]
                    .map(item=>
                        current.services.find(
                            service=>
                                Number(service.id)===
                                Number(item.value)
                        )
                    )
                    .filter(Boolean);

                    const total=
                        selected.reduce(
                            (sum,service)=>
                                sum+
                                servicePrice(service),
                            0
                        );

                    const summary=
                        m.querySelector('#summary');

                    if(summary){
                        summary.textContent=
                            selected.length
                                ? `الإجمالي: ${
                                    total.toFixed(2)
                                } ج`
                                : 'اختر خدمة';
                    }
                }
            );
        });
    }

    const book=m.querySelector('#book');

    if(book){
        book.addEventListener(
            'click',
            bookingModal
        );
    }
}


/* فتح اختيار التاريخ والموعد. */
async function bookingModal(){
    if(!current?.barber){
        return;
    }

    if(!selected.length){
        alert('اختر خدمة أولاً');
        return;
    }

    const totalDuration=
        selected.reduce(
            (sum,service)=>
                sum+
                Number(service.duration||0),
            0
        );

    const now=new Date();

    const localToday=[
        now.getFullYear(),
        String(
            now.getMonth()+1
        ).padStart(2,'0'),
        String(
            now.getDate()
        ).padStart(2,'0')
    ].join('-');

    const m=modal(`
        <h2>تأكيد الموعد</h2>

        <p>
            ${selected.map(
                service=>esc(service.name)
            ).join('، ')}
        </p>

        <p>
            المدة:
            ${totalDuration} دقيقة
        </p>

        <input
            type="date"
            id="date"
            min="${localToday}"
            value="${localToday}">

        <div id="slots" class="slots"></div>

        <button
            type="button"
            class="btn primary"
            id="confirm">
            تأكيد الحجز
        </button>
    `);

    const date=m.querySelector('#date');

    if(!date){
        return;
    }

    date.addEventListener(
        'change',
        async()=>{
            await loadSlots(
                m,
                date.value,
                totalDuration
            );
        }
    );

    await loadSlots(
        m,
        date.value,
        totalDuration
    );

    const confirmButton=
        m.querySelector('#confirm');

    if(confirmButton){
        confirmButton.addEventListener(
            'click',
            async()=>{
                const selectedSlot=
                    m.querySelector(
                        '.slot.selected'
                    );

                const time=
                    selectedSlot?.dataset.time;

                if(!time){
                    alert(
                        'اختر وقتاً متاحاً'
                    );
                    return;
                }

                confirmButton.disabled=true;

                const result=await request(
                    'book',
                    {
                        barber_id:
                            current.barber.id,
                        date:date.value,
                        time,
                        service_ids:
                            selected.map(
                                service=>service.id
                            )
                    },
                    true
                );

                if(!result.ok){
                    confirmButton.disabled=false;

                    alert(
                        result.error||
                        'تعذر إتمام الحجز'
                    );

                    return;
                }

                alert(
                    result.message||
                    'تم الحجز بنجاح'
                );

                m.remove();
            }
        );
    }
}


/* تحميل المواعيد المتاحة للحلاق والخدمات المختارة. */
async function loadSlots(
    modalElement,
    date,
    duration
){
    const box=
        modalElement.querySelector('#slots');

    if(!box||!current?.barber){
        return;
    }

    box.innerHTML=
        '<p>جاري تحميل المواعيد...</p>';

    const result=await request(
        'slots',
        {
            barber_id:
                current.barber.id,
            date,
            duration
        }
    );

    if(!result.ok){
        box.innerHTML=`
            <p class="danger-text">
                ${esc(
                    result.error||
                    'تعذر تحميل المواعيد'
                )}
            </p>
        `;
        return;
    }

    const slots=Array.isArray(result.slots)
        ? result.slots
        : [];

    if(!slots.length){
        box.innerHTML=`
            <p>
                لا توجد مواعيد متاحة لهذا اليوم.
            </p>
        `;
        return;
    }

    box.innerHTML=slots.map(time=>`
        <button
            type="button"
            class="slot"
            data-time="${esc(time)}">
            ${esc(time)}
        </button>
    `).join('');

    box.querySelectorAll('.slot')
        .forEach(slot=>{
            slot.addEventListener(
                'click',
                ()=>{
                    box.querySelectorAll(
                        '.slot'
                    ).forEach(item=>{
                        item.classList.remove(
                            'selected'
                        );
                    });

                    slot.classList.add(
                        'selected'
                    );
                }
            );
        });
}


/* تحميل بيانات الولاء والتقييم من API. */
async function loadCustomerInfo(){
    const tools=$('#customer-loyalty');

    if(!tools){
        return;
    }

    tools.innerHTML=`
        <div class="card">
            جاري تحميل بيانات الولاء...
        </div>
    `;

    const loyalty=
        await request('loyalty');

    const rating=
        await request('pending_rating');

    renderCustomerInfo(
        loyalty,
        rating
    );
}


/* عرض بيانات الولاء القديمة والتقييم داخل السجل. */
function renderCustomerInfo(
    loyalty,
    rating
){
    const tools=$('#customer-loyalty');

    if(!tools){
        return;
    }

    tools.innerHTML='';

    if(!loyalty.ok&&!rating.ok){
        tools.innerHTML=`
            <div class="card empty">
                سجّل الدخول لمتابعة نقاط
                الولاء والتقييمات.
            </div>
        `;
        return;
    }

    const box=
        document.createElement('div');

    box.className=
        'card customer-info';

    let html=
        '<h3>حسابي</h3>';

    if(loyalty.ok){
        html+=`
            <p>
                🪙 نقاط الولاء:
                <strong>
                    ${esc(
                        loyalty.stamps??0
                    )}
                </strong>
            </p>
        `;
    }

    if(
        rating.ok&&
        rating.booking
    ){
        html+=`
            <div class="actions">
                <button
                    type="button"
                    class="btn primary"
                    id="rate-barber">
                    ⭐ تقييم الحلاق
                </button>
            </div>
        `;
    }

    box.innerHTML=html;
    tools.appendChild(box);

    const rateButton=
        box.querySelector(
            '#rate-barber'
        );

    if(
        rateButton&&
        rating.booking
    ){
        rateButton.addEventListener(
            'click',
            ()=>{
                openRatingModal(
                    rating.booking
                );
            }
        );
    }
}


/* فتح نموذج تقييم الحلاق. */
function openRatingModal(booking){
    const m=modal(`
        <h2>تقييم الحلاق</h2>

        <p>
            الحلاق:
            <strong>
                ${esc(
                    booking.barber_name
                )}
            </strong>
        </p>

        <p>
            الخدمة:
            ${esc(
                booking.service_name||
                'الخدمة'
            )}
        </p>

        <div class="field">
            <label for="rating">
                التقييم
            </label>

            <select id="rating">
                <option value="">
                    اختر التقييم
                </option>
                <option value="5">
                    ⭐⭐⭐⭐⭐ 5
                </option>
                <option value="4">
                    ⭐⭐⭐⭐ 4
                </option>
                <option value="3">
                    ⭐⭐⭐ 3
                </option>
                <option value="2">
                    ⭐⭐ 2
                </option>
                <option value="1">
                    ⭐ 1
                </option>
            </select>
        </div>

        <div class="field">
            <label for="rating-comment">
                تعليق
            </label>

            <textarea
                id="rating-comment"
                maxlength="500"
                rows="4"
                placeholder="اكتب رأيك عن الخدمة والحلاق"></textarea>
        </div>

        <button
            type="button"
            class="btn primary"
            id="send-rating">
            إرسال التقييم
        </button>
    `);

    const button=
        m.querySelector(
            '#send-rating'
        );

    if(!button){
        return;
    }

    button.addEventListener(
        'click',
        async()=>{
            const rating=
                m.querySelector(
                    '#rating'
                )?.value||'';

            const comment=
                m.querySelector(
                    '#rating-comment'
                )?.value.trim()||'';

            if(!rating){
                alert(
                    'اختر عدد النجوم أولاً'
                );
                return;
            }

            button.disabled=true;

            const result=await request(
                'rate',
                {
                    booking_id:
                        booking.id,
                    rating:Number(rating),
                    comment
                },
                true
            );

            if(!result.ok){
                button.disabled=false;

                alert(
                    result.error||
                    'تعذر حفظ التقييم'
                );

                return;
            }

            alert(
                result.message||
                'تم حفظ التقييم'
            );

            m.remove();

            await loadCustomerInfo();
            await loadBarbers();
        }
    );
}


/* ربط البحث بقائمة الحلاقين المحملة. */
function initSearch(){
    const search=$('#search');

    if(!search){
        return;
    }

    search.addEventListener(
        'input',
        event=>{
            const query=
                event.target.value
                    .trim()
                    .toLowerCase();

            render(
                all.filter(barber=>{
                    const text=[
                        barber.name||'',
                        barber.salon_name||'',
                        barber.salon_location||''
                    ]
                    .join(' ')
                    .toLowerCase();

                    return text.includes(
                        query
                    );
                })
            );
        }
    );
}


/* تحديث قائمة الحلاقين يدوياً. */
function initRefresh(){
    const refresh=$('#refresh');

    if(!refresh){
        return;
    }

    refresh.addEventListener(
        'click',
        async()=>{
            refresh.disabled=true;

            try{
                await loadBarbers();
            }finally{
                refresh.disabled=false;
            }
        }
    );
}


/* تشغيل وظائف صفحة العميل بعد جاهزية DOM. */
function init(){
    initMenu();
    initSearch();
    initRefresh();
    loadBarbers();
}

if(document.readyState==='loading'){
    document.addEventListener(
        'DOMContentLoaded',
        init,
        {once:true}
    );
}else{
    init();
}

})();