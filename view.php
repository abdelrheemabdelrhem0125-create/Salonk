<?php
declare(strict_types=1);

/* تجهيز بداية الصفحة وإضافة CSRF وملف التنسيق */
function page_start(string $title,string $css='app.css'): void {
    $token=e(csrf_token());
    echo '<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<meta name="csrf-token" content="'.$token.'">';
    echo '<title>'.e($title).'</title><link rel="stylesheet" href="/assets/css/'.e($css).'">';
    echo '</head><body>';
}

/* تحويل ملفات JavaScript إلى مصفوفة ثم تحميلها وإغلاق الصفحة */
function page_end(string|array $js=''): void {
    if (is_string($js)) $js=$js!==''?[$js]:[];

    /* تحميل الملفات بالترتيب المحدد حتى تعمل الملفات المشتركة قبل الخاصة */
    foreach ($js as $file) {
        if (!is_string($file)||$file==='') continue;
        echo '<script src="/assets/js/'.e($file).'"></script>';
    }

    echo '</body></html>';
}

/* تحديد التبويبات المتاحة لكل دور مع إبقاء الحماية الحقيقية داخل PHP وAPI */
function manager_tabs_for_role(string $role): array {
    /* المدير العام يدير المستخدمين والصوالين والحجوزات وطلبات الاستعادة والسلة */
    if (in_array($role,['admin','super_admin'],true)) return [
        'dashboard'=>['لوحة التحكم','dashboard'],'users'=>['المستخدمون','users'],'salons'=>['الصوالين','salons'],
        'bookings'=>['الحجوزات','bookings'],'resets'=>['استعادة كلمات المرور','resets'],'trash'=>['سلة المهملات','trash']
    ];

    /* مدير الصالون يدير الحلاقين والخدمات والأدوات والنسب والسلة الخاصة بصلاحياته */
    if ($role==='salon_admin') return [
        'dashboard'=>['لوحة التحكم','dashboard'],'people'=>['الحلاقون','people'],'services'=>['الخدمات','services'],
        'tools'=>['الأدوات','tools'],'finance'=>['النسب والأرصدة','finance'],'trash'=>['سلة المهملات','trash']
    ];

    /* الحلاق يدير لوحته وحجوزاته وجدوله وحساباته */
    if ($role==='barber') return [
        'dashboard'=>['لوحتي','dashboard'],'bookings'=>['الحجوزات','bookings'],
        'schedule'=>['الجدول الأسبوعي','schedule'],'accounting'=>['الحسابات','accounting']
    ];

    /* أي دور غير معروف لا يحصل إلا على لوحة التحكم */
    return ['dashboard'=>['لوحة التحكم','dashboard']];
}

/* إنشاء الهيدر وقائمة التنقل وفتح منطقة محتوى لوحة الإدارة */
function manager_shell_start(array $user,string $title,string $active): void {
    page_start($title,'manager.css');
    $role=(string)($user['role']??'');
    $tabs=manager_tabs_for_role($role);

    /* التأكد من أن التبويب المطلوب مسموح لهذا الدور قبل عرضه */
    if (!isset($tabs[$active])) $active='dashboard';

    /* عرض اسم المنصة واسم اللوحة وبيانات المستخدم ورابط الخروج */
    echo '<header class="manager-header"><div><strong>منصة صالونك</strong>';
    echo '<span class="manager-sub">'.e($title).'</span></div><div class="manager-user">';
    echo e((string)($user['name']??'')).' <a href="/logout.php">خروج</a></div></header>';

    /* فتح المحتوى وإنشاء روابط التبويبات مع تحديد الصفحة الحالية */
    echo '<main class="manager-main"><nav class="manager-tabs">';

    foreach ($tabs as $key=>$tab) {
        $activeClass=$active===$key?'active':'';
        echo '<a class="'.$activeClass.'" href="?tab='.e($key).'">'.e($tab[0]).'</a>';
    }

    echo '</nav><section class="manager-content">';
}

/* إغلاق محتوى اللوحة وتحميل JavaScript المشترك ثم الخاص بالصفحة */
function manager_shell_end(string $js): void {
    echo '</section></main>';
    page_end(['manager-common.js',$js]);
}