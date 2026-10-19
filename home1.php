<?php
declare(strict_types=1);
require_once __DIR__.'/app/bootstrap.php';
$user=current_user();
page_start('منصة صالونك');
?>
<header class="site-header">
    <div class="customer-page">
        <img src="/hero.png" class="home-header-logo" alt="">
        <strong>منصة صالونك</strong>
    </div>
    <div>
        <?= $user
           ? '<a href="'.e(role_home($user['role'])).'">لوحتي</a> <a href="/logout.php">خروج</a>'
            : '<a href="/index.php">دخول</a>'?>
    </div>
</header>

<main class="customer customer-page">
    <nav class="customer-tabs" aria-label="أقسام الصفحة">
        <button type="button" class="customer-tab active" data-tab="home">الرئيسية</button>
        <button type="button" class="customer-tab" data-tab="services">الخدمات والعروض</button>
        <button type="button" class="customer-tab" data-tab="loyalty">الولاء</button>
    </nav>

    <section id="customer-tab-home" class="customer-tab-content active">
        <section class="hero-card">
            <div><h1>احجز خدمتك بسهولة</h1><p>تصفح الحلاقين والخدمات والمواعيد المتاحة.</p></div>
            <img src="/hero.png" alt="">
        </section>
        <div class="toolbar">
            <input id="search" placeholder="ابحث عن حلاق أو صالون">
            <button class="btn primary" id="refresh">تحديث</button>
        </div>
        <section id="barbers" class="cards"></section>
    </section>

    <section id="customer-tab-services" class="customer-tab-content">
        <div class="section-heading"><h2>الخدمات والعروض</h2><p>استعرض العروض الحالية وخدمات VIP.</p></div>
        <section id="customer-tools" class="cards"></section>
    </section>

    <!-- ============ تبويبة الولاء المطورة - التعديل هنا فقط ============ -->
    <section id="customer-tab-loyalty" class="customer-tab-content">
        <div class="section-heading">
            <h2>برنامج الولاء المتدرج</h2>
            <p>اكسب نقاط حسب الخدمة وارتقي بمستواك</p>
        </div>

        <!-- 1. كرت المستوى والنقاط -->
        <div class="cards" style="margin-bottom:16px">
            <div class="card" style="background: linear-gradient(135deg,#111,#333); color:#fff;">
                <div style="display:flex; justify-content:space-between; align-items:center">
                    <div>
                        <small style="opacity:.7">نقاطك الحالية</small>
                        <h2 id="loyalty-points" style="margin:4px 0">0 نقطة</h2>
                        <span id="loyalty-tier" class="badge" style="background:#f5c518; color:#000">زبون عادي 🟢</span>
                    </div>
                    <div style="text-align:center">
                        <div id="loyalty-icon" style="font-size:48px">🟢</div>
                    </div>
                </div>
                <div style="margin-top:12px">
                    <small>التقدم للمستوى القادم</small>
                    <div style="background:rgba(255,255,255,.2); height:8px; border-radius:10px; margin-top:6px">
                        <div id="loyalty-progress" style="height:8px; width:0%; background:#f5c518; border-radius:10px"></div>
                    </div>
                    <small id="loyalty-next" style="opacity:.8"></small>
                </div>
            </div>
        </div>

        <!-- 2. مراحل المكافآت -->
        <div class="section-heading" style="margin-top:10px">
            <h3>مراحل المكافآت</h3>
        </div>
        <div class="cards" style="grid-template-columns: repeat(auto-fit, minmax(200px,1fr));">
            <div class="card" id="reward-50"><h4>🎁 50 نقطة</h4><p>حلاقة شعر مجانية</p><span class="reward-status">مقفل</span></div>
            <div class="card" id="reward-100"><h4>✂️ 100 نقطة</h4><p>حلاقة + لحية</p><span class="reward-status">مقفل</span></div>
            <div class="card" id="reward-200"><h4>👑 200 نقطة</h4><p>باقة كاملة VIP</p><span class="reward-status">مقفل</span></div>
        </div>

        <!-- 3. كيف تكسب النقاط -->
        <div class="section-heading" style="margin-top:16px">
            <h3>كيف تكسب النقاط؟</h3>
        </div>
        <div class="cards" style="grid-template-columns: repeat(auto-fit, minmax(140px,1fr));">
            <div class="card"><strong>حلاقة شعر</strong><br><span style="color:#0a0">+10 نقاط</span></div>
            <div class="card"><strong>قطعية</strong><br><span style="color:#0a0">+15 نقطة</span></div>
            <div class="card"><strong>حمام زيت</strong><br><span style="color:#0a0">+30 نقطة</span></div>
            <div class="card"><strong>باقة كاملة</strong><br><span style="color:#0a0">+50 نقطة</span></div>
        </div>

        <!-- 4. السجل القديم (ما لمسناه) -->
        <div class="section-heading" style="margin-top:16px">
            <h3>سجل نقاطي</h3>
        </div>
        <section id="customer-loyalty" class="cards"></section>
    </section>
</main>

<script src="/assets/js/customer.js"></script>
<script>
// تحديث واجهة الولاء من نفس API القديم بدون تغيير
document.addEventListener('DOMContentLoaded', ()=>{
  // لو عندك API يرجع النقاط، هنا بنحدث الواجهة
  // مثال مؤقت - حيربط مع customer.js
  const updateLoyaltyUI = (points)=>{
    document.getElementById('loyalty-points').textContent = points + ' نقطة';
    let tier='عادي', icon='🟢', next=100-points, width=(points%100);
    if(points>=600){tier='VIP 💎'; icon='💎'; next=0; width=100;}
    else if(points>=300){tier='ذهبي 🟡'; icon='🟡'; next=600-points; width=((points-300)/300)*100;}
    else if(points>=100){tier='فضي ⚪'; icon='⚪'; next=300-points; width=((points-100)/200)*100;}
    else {next=100-points; width=points;}

    document.getElementById('loyalty-tier').textContent = 'زبون ' + tier;
    document.getElementById('loyalty-icon').textContent = icon;
    document.getElementById('loyalty-progress').style.width = width + '%';
    document.getElementById('loyalty-next').textContent = next>0? `باقي ${next} نقطة للمستوى القادم` : 'وصلت لأعلى مستوى!';

    // فتح المكافآت
    document.querySelectorAll('[id^="reward-"]').forEach(el=>{
        const need = parseInt(el.id.split('-')[1]);
        el.querySelector('.reward-status').textContent = points>=need? '✅ متاح للاستبدال' : `باقي ${need-points} نقطة`;
        el.style.opacity = points>=need? '1' : '0.6';
        if(points>=need) el.style.border='2px solid #22c55e';
    });
  };
  // جرب بنقاط وهمية، customer.js حيحدثها لاحقا
  updateLoyaltyUI(95);
});
</script>
<?php page_end();?>
دا ملف العميل صاح