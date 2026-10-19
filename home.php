<?php
declare(strict_types=1);

require_once __DIR__.'/app/bootstrap.php';

$user=current_user();
page_start('منصة صالونك');
?>

<header class="site-header">
    <div class="customer-brand">
        <img src="/hero.png" class="home-header-logo" alt="">
        <strong>منصة صالونك</strong>
    </div>

    <div class="customer-menu-wrap">
        <button type="button" class="customer-menu-btn" id="customer-menu-btn"
                aria-label="فتح القائمة" aria-expanded="false">
            <span></span>
            <span></span>
            <span></span>
        </button>

        <nav class="customer-menu" id="customer-menu" aria-label="قائمة العميل">
            <button type="button" class="customer-menu-item active"
                    data-menu-tab="home">
                <span>🏠</span>
                <span>الرئيسية</span>
            </button>

            <button type="button" class="customer-menu-item"
                    data-menu-tab="vip">
                <span>✂️</span>
                <span>خدمات VIP</span>
            </button>

            <button type="button" class="customer-menu-item"
                    data-menu-tab="offers">
                <span>🔥</span>
                <span>العروض المخفضة</span>
            </button>

            <button type="button" class="customer-menu-item"
                    data-menu-tab="loyalty">
                <span>⭐</span>
                <span>نقاط الولاء</span>
            </button>

            <?php if ($user): ?>
                <a class="customer-menu-item"
                   href="<?= e(role_home($user['role'])) ?>">
                    <span>👤</span>
                    <span>لوحتي</span>
                </a>

                <a class="customer-menu-item logout"
                   href="/logout.php">
                    <span>🚪</span>
                    <span>خروج</span>
                </a>
            <?php else: ?>
                <a class="customer-menu-item" href="/index.php">
                    <span>🔐</span>
                    <span>دخول</span>
                </a>
            <?php endif; ?>
        </nav>
    </div>
</header>

<main class="customer customer-page">

    <section id="customer-tab-home" class="customer-tab-content active">
        <section class="hero-card">
            <div>
                <h1>احجز خدمتك بسهولة</h1>
                <p>تصفح الحلاقين والخدمات والمواعيد المتاحة.</p>
            </div>

            <img src="/hero.png" alt="">
        </section>

        <div class="toolbar">
            <input id="search" placeholder="ابحث عن حلاق أو صالون">
            <button class="btn primary" id="refresh">تحديث</button>
        </div>

        <section id="barbers" class="cards"></section>
    </section>

    <section id="customer-tab-vip" class="customer-tab-content">
        <div class="section-heading">
            <h2>خدمات VIP</h2>
            <p>استعرض خدمات VIP المتاحة من الحلاقين.</p>
        </div>

        <section id="customer-vip" class="cards">
            <div class="card">
                <p>جاري تحميل خدمات VIP...</p>
            </div>
        </section>
    </section>

    <section id="customer-tab-offers" class="customer-tab-content">
        <div class="section-heading">
            <h2>العروض المخفضة</h2>
            <p>استفد من العروض والأسعار المخفضة المتاحة حالياً.</p>
        </div>

        <section id="customer-offers" class="cards">
            <div class="card">
                <p>جاري تحميل العروض...</p>
            </div>
        </section>
    </section>

    <section id="customer-tab-loyalty" class="customer-tab-content">
        <div class="section-heading">
            <h2>برنامج الولاء المتدرج</h2>
            <p>اكسب نقاط حسب الخدمة وارتقي بمستواك</p>
        </div>

        <div class="cards" style="margin-bottom:16px">
            <div class="card"
                 style="background:linear-gradient(135deg,#111,#333);color:#fff;">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <div>
                        <small style="opacity:.7">نقاطك الحالية</small>
                        <h2 id="loyalty-points" style="margin:4px 0">
                            0 نقطة
                        </h2>

                        <span id="loyalty-tier" class="badge"
                              style="background:#f5c518;color:#000">
                            زبون عادي 🟢
                        </span>
                    </div>

                    <div style="text-align:center">
                        <div id="loyalty-icon" style="font-size:48px">
                            🟢
                        </div>
                    </div>
                </div>

                <div style="margin-top:12px">
                    <small>التقدم للمستوى القادم</small>

                    <div style="background:rgba(255,255,255,.2);height:8px;
                                border-radius:10px;margin-top:6px">
                        <div id="loyalty-progress"
                             style="height:8px;width:0%;background:#f5c518;
                                    border-radius:10px"></div>
                    </div>

                    <small id="loyalty-next" style="opacity:.8"></small>
                </div>
            </div>
        </div>

        <div class="section-heading" style="margin-top:10px">
            <h3>مراحل المكافآت</h3>
        </div>

        <div class="cards"
             style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));">
            <div class="card" id="reward-50">
                <h4>🎁 50 نقطة</h4>
                <p>حلاقة شعر مجانية</p>
                <span class="reward-status">مقفل</span>
            </div>

            <div class="card" id="reward-100">
                <h4>✂️ 100 نقطة</h4>
                <p>حلاقة + لحية</p>
                <span class="reward-status">مقفل</span>
            </div>

            <div class="card" id="reward-200">
                <h4>👑 200 نقطة</h4>
                <p>باقة كاملة VIP</p>
                <span class="reward-status">مقفل</span>
            </div>
        </div>

        <div class="section-heading" style="margin-top:16px">
            <h3>كيف تكسب النقاط؟</h3>
        </div>

        <div class="cards"
             style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));">
            <div class="card">
                <strong>حلاقة شعر</strong><br>
                <span style="color:#0a0">+10 نقاط</span>
            </div>

            <div class="card">
                <strong>قطعية</strong><br>
                <span style="color:#0a0">+15 نقطة</span>
            </div>

            <div class="card">
                <strong>حمام زيت</strong><br>
                <span style="color:#0a0">+30 نقطة</span>
            </div>

            <div class="card">
                <strong>باقة كاملة</strong><br>
                <span style="color:#0a0">+50 نقطة</span>
            </div>
        </div>

        <div class="section-heading" style="margin-top:16px">
            <h3>سجل نقاطي</h3>
        </div>

        <section id="customer-loyalty" class="cards"></section>
    </section>
</main>

<script src="/assets/js/customer.js"></script>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const menuBtn=document.getElementById('customer-menu-btn');
    const menu=document.getElementById('customer-menu');
    const menuItems=document.querySelectorAll('[data-menu-tab]');
    const tabs=document.querySelectorAll('.customer-tab-content');

    const closeMenu=()=>{
        menu.classList.remove('open');
        menuBtn.classList.remove('open');
        menuBtn.setAttribute('aria-expanded','false');
    };

    const showTab=tabName=>{
        tabs.forEach(tab=>{
            tab.classList.toggle(
                'active',
                tab.id==='customer-tab-'+tabName
            );
        });

        menuItems.forEach(item=>{
            item.classList.toggle(
                'active',
                item.dataset.menuTab===tabName
            );
        });

        closeMenu();

        if(tabName==='vip'||tabName==='offers'){
            loadCustomerOffers();
        }
    };

    menuBtn.addEventListener('click',event=>{
        event.stopPropagation();

        const open=!menu.classList.contains('open');

        if(open){
            menu.classList.add('open');
            menuBtn.classList.add('open');
            menuBtn.setAttribute('aria-expanded','true');
        }else{
            closeMenu();
        }
    });

    menuItems.forEach(item=>{
        item.addEventListener('click',()=>{
            showTab(item.dataset.menuTab);
        });
    });

    document.addEventListener('click',event=>{
        if(!menu.contains(event.target)&&!menuBtn.contains(event.target)){
            closeMenu();
        }
    });

    const updateLoyaltyUI=points=>{
        const pointsEl=document.getElementById('loyalty-points');
        const tierEl=document.getElementById('loyalty-tier');
        const iconEl=document.getElementById('loyalty-icon');
        const progressEl=document.getElementById('loyalty-progress');
        const nextEl=document.getElementById('loyalty-next');

        if(!pointsEl||!tierEl||!iconEl||!progressEl||!nextEl){
            return;
        }

        pointsEl.textContent=points+' نقطة';

        let tier='عادي';
        let icon='🟢';
        let next=100-points;
        let width=points;

        if(points>=600){
            tier='VIP 💎';
            icon='💎';
            next=0;
            width=100;
        }else if(points>=300){
            tier='ذهبي 🟡';
            icon='🟡';
            next=600-points;
            width=((points-300)/300)*100;
        }else if(points>=100){
            tier='فضي ⚪';
            icon='⚪';
            next=300-points;
            width=((points-100)/200)*100;
        }

        tierEl.textContent='زبون '+tier;
        iconEl.textContent=icon;
        progressEl.style.width=Math.min(100,Math.max(0,width))+'%';

        nextEl.textContent=next>0
            ?`باقي ${next} نقطة للمستوى القادم`
            :'وصلت لأعلى مستوى!';

        document.querySelectorAll('[id^="reward-"]').forEach(el=>{
            const need=parseInt(el.id.split('-')[1],10);
            const status=el.querySelector('.reward-status');

            if(!status){
                return;
            }

            status.textContent=points>=need
                ?'✅ متاح للاستبدال'
                :`باقي ${need-points} نقطة`;

            el.style.opacity=points>=need?'1':'0.6';

            if(points>=need){
                el.style.border='2px solid #22c55e';
            }
        });
    };

    updateLoyaltyUI(95);

    async function loadCustomerOffers(){
        const vipBox=document.getElementById('customer-vip');
        const offersBox=document.getElementById('customer-offers');

        try{
            const response=await fetch(
                '/api/public.php?action=offers',
                {
                    method:'GET',
                    headers:{
                        'Accept':'application/json'
                    }
                }
            );

            const data=await response.json();

            if(!data.ok){
                throw new Error(data.error||'تعذر تحميل البيانات');
            }

            renderVip(data.vip||[]);
            renderOffers(data.flash||[]);
        }catch(error){
            const message=`
                <div class="card">
                    <p>تعذر تحميل الخدمات والعروض حالياً.</p>
                </div>
            `;

            if(vipBox){
                vipBox.innerHTML=message;
            }

            if(offersBox){
                offersBox.innerHTML=message;
            }
        }
    }

    function renderVip(items){
        const box=document.getElementById('customer-vip');

        if(!box){
            return;
        }

        if(!items.length){
            box.innerHTML=`
                <div class="card">
                    <h3>لا توجد خدمات VIP حالياً</h3>
                    <p>سيتم عرض خدمات VIP هنا عند توفرها.</p>
                </div>
            `;
            return;
        }

        box.innerHTML=items.map(item=>`
            <article class="card">
                <span class="badge">VIP 👑</span>
                <h3>${escapeCustomerHtml(item.name)}</h3>
                <p>
                    الحلاق:
                    ${escapeCustomerHtml(item.barber_name||'غير محدد')}
                </p>
                <strong>${escapeCustomerHtml(item.price)} جنيه</strong>
            </article>
        `).join('');
    }

    function renderOffers(items){
        const box=document.getElementById('customer-offers');

        if(!box){
            return;
        }

        if(!items.length){
            box.innerHTML=`
                <div class="card">
                    <h3>لا توجد عروض مخفضة حالياً</h3>
                    <p>سيتم عرض العروض هنا عند توفرها.</p>
                </div>
            `;
            return;
        }

        box.innerHTML=items.map(item=>`
            <article class="card">
                <span class="badge">🔥 عرض مخفض</span>
                <h3>${escapeCustomerHtml(item.name)}</h3>
                <p>
                    الحلاق:
                    ${escapeCustomerHtml(item.barber_name||'غير محدد')}
                </p>
                <div>
                    <del>${escapeCustomerHtml(item.price)} جنيه</del>
                    <strong>
                        ${escapeCustomerHtml(item.flash_price)} جنيه
                    </strong>
                </div>
            </article>
        `).join('');
    }

    function escapeCustomerHtml(value){
        return String(value??'')
            .replaceAll('&','&amp;')
            .replaceAll('<','&lt;')
            .replaceAll('>','&gt;')
            .replaceAll('"','&quot;')
            .replaceAll("'",'&#039;');
    }
});
</script>

<style>
.customer-brand{
    display:flex;
    align-items:center;
    gap:10px;
}

.customer-menu-wrap{
    position:relative;
    flex-shrink:0;
}

.customer-menu-btn{
    width:44px;
    height:44px;
    padding:8px;
    border:0;
    border-radius:10px;
    background:transparent;
    color:#1e3a8a;
    cursor:pointer;
    display:flex;
    flex-direction:column;
    justify-content:center;
    align-items:center;
    gap:5px;
}

.customer-menu-btn span{
    display:block;
    width:25px;
    height:3px;
    border-radius:3px;
    background:currentColor;
    transition:.2s;
}

.customer-menu-btn.open span:nth-child(1){
    transform:translateY(8px) rotate(45deg);
}

.customer-menu-btn.open span:nth-child(2){
    opacity:0;
}

.customer-menu-btn.open span:nth-child(3){
    transform:translateY(-8px) rotate(-45deg);
}

.customer-menu{
    position:absolute;
    top:calc(100% + 8px);
    left:0;
    width:220px;
    padding:8px;
    border-radius:14px;
    background:#fff;
    box-shadow:0 12px 30px rgba(0,0,0,.16);
    border:1px solid #c9dff5;
    display:none;
    z-index:1000;
}

.customer-menu.open{
    display:block;
}

.customer-menu-item{
    width:100%;
    min-height:44px;
    padding:9px 12px;
    border:0;
    border-radius:9px;
    background:transparent;
    color:#1e3a8a;
    text-decoration:none;
    cursor:pointer;
    display:flex;
    align-items:center;
    gap:9px;
    font:inherit;
    text-align:right;
}

.customer-menu-item:hover,
.customer-menu-item.active{
    background:#eef6ff;
    color:#2563eb;
}

.customer-menu-item.logout{
    color:#dc2626;
}

.customer-menu-item.logout:hover{
    background:#fff1f2;
    color:#dc2626;
}

.customer-tab-content{
    display:none;
}

.customer-tab-content.active{
    display:block;
}

@media(max-width:600px){
    .customer-menu{
        width:210px;
        left:0;
        right:auto;
    }

    .customer-menu-btn{
        width:42px;
        height:42px;
    }
}
</style>

<?php page_end(); ?>