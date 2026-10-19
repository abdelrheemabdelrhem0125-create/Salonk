<?php
declare(strict_types=1);

define('API_REQUEST', true);
require_once dirname(__DIR__) . '/app/bootstrap.php';

/* التأكد من أن الاتصال بالـ API يتم عبر POST وقراءة بيانات الطلب والتحقق من الجلسة وCSRF */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['ok'=>false,'error'=>'طريقة الطلب غير مسموحة'],405);
$data=request_data();
$action=trim((string)($data['action']??''));
verify_csrf();
$user=current_user();
if (!$user) respond(['ok'=>false,'error'=>'غير مسجل الدخول'],401);
$role=(string)$user['role'];
$pdo=db();

const TRASH_RETENTION_DAYS=30;

/* التحقق من أن الدور الحالي مسموح له بتنفيذ العملية المطلوبة */
function require_manager_scope(string ...$roles): void
{
    global $role;
    if (!in_array($role,$roles,true)) respond(['ok'=>false,'error'=>'غير مصرح لك'],403);
}

/* التأكد من أن مدير الصالون لا يستطيع الوصول إلى صالون آخر */
function require_salon_owner(int $salonId): void
{
    global $user,$role;
    if (in_array($role,['admin','super_admin'],true)) return;
    if ($role!=='salon_admin'||(int)$user['salon_id']!==$salonId) respond(['ok'=>false,'error'=>'هذا الصالون خارج صلاحيتك'],403);
}

/* تحديد الصالون المستهدف مع إجبار مدير الصالون على استخدام الصالون المرتبط بحسابه */
function target_salon(int $fallback=0): int
{
    global $user,$role,$data;
    if ($role==='salon_admin') {
        $sid=(int)($user['salon_id']??0);
        if ($sid<=0) respond(['ok'=>false,'error'=>'حساب مدير الصالون غير مرتبط بصولون'],409);
        return $sid;
    }
    return positive_id($data['salon_id']??$fallback,'salon_id');
}

/* التأكد من أن الحلاق موجود وفعال وينتمي فعلياً إلى الصالون المطلوب */
function ensure_barber_in_salon(int $barberId,int $salonId): array
{
    $q=db()->prepare("SELECT * FROM users WHERE id=? AND role='barber' AND salon_id=? AND deleted_at IS NULL LIMIT 1");
    $q->execute([$barberId,$salonId]);
    $barber=$q->fetch();
    if (!$barber) respond(['ok'=>false,'error'=>'الحلاق غير تابع للصالون'],404);
    return $barber;
}

/* التحقق من قيمة المبلغ وتحويلها إلى رقم عشري آمن */
function manager_amount_value(mixed $value,string $field): float
{
    if ($value===''||$value===null||!is_numeric((string)$value)||!is_finite((float)$value)) {
        respond(['ok'=>false,'error'=>'قيمة '.$field.' غير صحيحة'],422);
    }

    $amount=round((float)$value,2);

    if ($amount<0) {
        respond(['ok'=>false,'error'=>'قيمة '.$field.' لا يمكن أن تكون سالبة'],422);
    }

    return $amount;
}

/* منع المدير من إدارة حسابه ومنع المدير العادي من تجاوز مستوى صلاحياته */
function ensure_admin_can_manage_user(array $target): void
{
    global $role,$user;
    $targetId=(int)$target['id'];
    $targetRole=(string)$target['role'];
    if ($targetId===(int)$user['id']) respond(['ok'=>false,'error'=>'لا يمكنك تنفيذ هذه العملية على حسابك الحالي'],409);
    if ($role==='admin'&&in_array($targetRole,['admin','super_admin'],true)) respond(['ok'=>false,'error'=>'الصلاحية غير كافية لإدارة هذا الحساب'],403);
}

/* جلب حساب المستخدم مع اسم الصالون مع إمكانية البحث أيضاً داخل الحسابات المحذوفة */
function get_user_by_id(int $id,bool $includeDeleted=false): ?array
{
    $sql="SELECT u.id,u.name,u.phone,u.email,u.password,u.role,u.salon_id,u.is_available,u.rating,u.location,u.must_change_password,u.created_at,u.deleted_at,s.name AS salon_name FROM users u LEFT JOIN salons s ON s.id=u.salon_id WHERE u.id=?";
    if (!$includeDeleted) $sql.=" AND u.deleted_at IS NULL";
    $sql.=" LIMIT 1";
    $q=db()->prepare($sql);
    $q->execute([$id]);
    $result=$q->fetch();
    return $result?:null;
}

/* فحص الهاتف والبريد على جميع الحسابات لمنع تعارض UNIQUE حتى مع الحسابات الموجودة في السلة */
function ensure_unique_user_contact(string $phone,?string $email,int $ignoreId=0): void
{
    $conditions=['phone=?'];
    $params=[$phone];
    if ($email!==null&&$email!=='') {
        $conditions[]='email=?';
        $params[]=$email;
    }
    $sql="SELECT id FROM users WHERE (".implode(' OR ',$conditions).")";
    if ($ignoreId>0) {
        $sql.=" AND id<>?";
        $params[]=$ignoreId;
    }
    $sql.=" LIMIT 1";
    $q=db()->prepare($sql);
    $q->execute($params);
    if ($q->fetchColumn()) respond(['ok'=>false,'error'=>'رقم الهاتف أو البريد الإلكتروني مستخدم بالفعل'],409);
}

/* التأكد من أن الحساب داخل فترة الاستعادة المسموحة قبل إرجاعه من السلة */
function ensure_restore_period(array $target): void
{
    if (empty($target['deleted_at'])) respond(['ok'=>false,'error'=>'الحساب غير موجود في سلة المهملات'],404);
    $deletedAt=strtotime((string)$target['deleted_at']);
    if ($deletedAt===false) respond(['ok'=>false,'error'=>'تاريخ حذف الحساب غير صالح'],409);
    $expiresAt=$deletedAt+(TRASH_RETENTION_DAYS*86400);
    if (time()>$expiresAt) respond(['ok'=>false,'error'=>'انتهت مدة استعادة هذا الحساب'],410);
}

/* تحديد نطاق الحسابات التي يستطيع المدير إدارتها حسب دوره والصالون المرتبط به */
function ensure_trash_scope(array $target): void
{
    global $role,$user;
    $targetRole=(string)$target['role'];
    if (in_array($role,['admin','super_admin'],true)) {
        ensure_admin_can_manage_user($target);
        return;
    }
    if ($role!=='salon_admin') respond(['ok'=>false,'error'=>'غير مصرح لك'],403);
    if ((int)$target['salon_id']!==(int)$user['salon_id']||$targetRole!=='barber') respond(['ok'=>false,'error'=>'الحساب خارج صلاحية صالونك'],403);
}

/* فحص السجلات المرتبطة بالحساب لمنع حذف حساب ما زالت بياناته مستخدمة داخل النظام */
function permanent_delete_block_reason(int $userId): ?string
{
    $pdo=db();
    $checks=[
        ["SELECT COUNT(*) FROM services WHERE barber_id=?",'خدمات الحلاق'],
        ["SELECT COUNT(*) FROM bookings WHERE customer_id=?",'حجوزات العميل'],
        ["SELECT COUNT(*) FROM bookings WHERE barber_id=?",'حجوزات الحلاق'],
        ["SELECT COUNT(*) FROM barber_earnings WHERE barber_id=?",'أرباح الحلاق'],
        ["SELECT COUNT(*) FROM barber_withdrawals WHERE barber_id=?",'سحوبات الحلاق'],
        ["SELECT COUNT(*) FROM ratings WHERE customer_id=?",'تقييمات العميل'],
        ["SELECT COUNT(*) FROM ratings WHERE barber_id=?",'تقييمات الحلاق']
    ];
    foreach ($checks as [$sql,$name]) {
        $q=$pdo->prepare($sql);
        $q->execute([$userId]);
        if ((int)$q->fetchColumn()>0) return $name;
    }
    return null;
}

/* تحويل نتيجة فحص العلاقات إلى رسالة واضحة بدلاً من السماح بحذف حساب مرتبط */
function ensure_permanent_delete_allowed(int $userId): void
{
    try {
        $reason=permanent_delete_block_reason($userId);
        if ($reason!==null) respond(['ok'=>false,'error'=>'لا يمكن الحذف النهائي لأن الحساب مرتبط بسجلات محفوظة: '.$reason],409);
    } catch (Throwable $e) {
        error_log($e->getMessage());
        respond(['ok'=>false,'error'=>'تعذر التحقق من ارتباطات الحساب قبل الحذف النهائي'],500);
    }
}

/* حذف السجلات المساندة المسموح بإزالتها مع الحساب داخل نفس المعاملة */
function delete_user_auxiliary_records(int $userId): void
{
    $pdo=db();
    $pdo->prepare("DELETE FROM barber_schedules WHERE barber_id=?")->execute([$userId]);
    $pdo->prepare("DELETE FROM password_reset_requests WHERE user_id=?")->execute([$userId]);
    $pdo->prepare("DELETE FROM favorites WHERE customer_id=? OR barber_id=?")->execute([$userId,$userId]);
    $pdo->prepare("DELETE FROM loyalty_cards WHERE customer_id=?")->execute([$userId]);
}

/* البحث عن الحسابات التي تجاوزت مدة السلة ومحاولة حذفها نهائياً بعد إعادة التحقق والقفل */
function cleanup_expired_trash(): void
{
    global $role;
    if (!in_array($role,['admin','super_admin'],true)) return;
    try {
        $pdo=db();
        $q=$pdo->query("SELECT id,role FROM users WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(),INTERVAL ".TRASH_RETENTION_DAYS." DAY) ORDER BY deleted_at ASC LIMIT 100");

        foreach ($q->fetchAll() as $row) {
            $id=(int)$row['id'];

            if (in_array((string)$row['role'],['admin','super_admin'],true)) continue;

            try {
                $pdo->beginTransaction();

                /* قفل الحساب وإعادة التحقق من حالته قبل تنفيذ الحذف التلقائي */
                $lock=$pdo->prepare("SELECT id,role,deleted_at FROM users WHERE id=? FOR UPDATE");
                $lock->execute([$id]);
                $target=$lock->fetch();

                if (!$target||empty($target['deleted_at'])||in_array((string)$target['role'],['admin','super_admin'],true)) {
                    $pdo->rollBack();
                    continue;
                }

                /* التأكد من أن مدة السلة انتهت فعلاً وعدم حذف حساب تم تحديثه أثناء العملية */
                $deletedAt=strtotime((string)$target['deleted_at']);

                if ($deletedAt===false||$deletedAt>=time()-(TRASH_RETENTION_DAYS*86400)) {
                    $pdo->rollBack();
                    continue;
                }

                /* منع الحذف التلقائي إذا كان الحساب ما زال مرتبطاً ببيانات محفوظة */
                $reason=permanent_delete_block_reason($id);

                if ($reason!==null) {
                    $pdo->rollBack();
                    error_log('Expired trash skipped for user '.$id.': '.$reason);
                    continue;
                }

                delete_user_auxiliary_records($id);

                $stmt=$pdo->prepare("DELETE FROM users WHERE id=? AND deleted_at IS NOT NULL");
                $stmt->execute([$id]);

                if ($stmt->rowCount()!==1) throw new RuntimeException('تعذر حذف الحساب المنتهي');

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log($e->getMessage());
            }
        }
    } catch (Throwable $e) {
        error_log($e->getMessage());
    }
}

try {
    cleanup_expired_trash();

    /* توجيه كل عملية إلى الجزء المسؤول عنها مع تطبيق صلاحياتها قبل الوصول إلى البيانات */
    switch ($action) {
        case 'dashboard':
            require_manager_scope('admin','super_admin','salon_admin','barber');

            /* تجهيز إحصائيات الإدارة العامة من المستخدمين والصوالين والحجوزات والإيرادات */
            if (in_array($role,['admin','super_admin'],true)) {
                $stats=[
                    'users'=>(int)$pdo->query("SELECT COUNT(*) FROM users WHERE deleted_at IS NULL")->fetchColumn(),
                    'barbers'=>(int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='barber' AND deleted_at IS NULL")->fetchColumn(),
                    'salons'=>(int)$pdo->query("SELECT COUNT(*) FROM salons WHERE deleted_at IS NULL")->fetchColumn(),
                    'bookings'=>(int)$pdo->query("SELECT COUNT(*) FROM bookings")->fetchColumn(),
                    'revenue'=>(float)$pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM bookings WHERE status IN ('confirmed','completed')")->fetchColumn()
                ];
            } elseif ($role==='salon_admin') {
                $sid=(int)$user['salon_id'];
                require_salon_owner($sid);

                $q=$pdo->prepare("SELECT COUNT(*) FROM users WHERE salon_id=? AND role='barber' AND deleted_at IS NULL");
                $q->execute([$sid]);
                $barbers=(int)$q->fetchColumn();

                $q=$pdo->prepare("SELECT COUNT(*) FROM bookings b JOIN users u ON u.id=b.barber_id WHERE u.salon_id=?");
                $q->execute([$sid]);
                $bookings=(int)$q->fetchColumn();

                $q=$pdo->prepare("SELECT COALESCE(SUM(b.total_amount),0) FROM bookings b JOIN users u ON u.id=b.barber_id WHERE u.salon_id=? AND b.status IN ('confirmed','completed')");
                $q->execute([$sid]);
                $stats=['barbers'=>$barbers,'bookings'=>$bookings,'revenue'=>(float)$q->fetchColumn()];
            } else {
                $q=$pdo->prepare("SELECT COUNT(*) FROM bookings WHERE barber_id=?");
                $q->execute([(int)$user['id']]);

                $q2=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM barber_earnings WHERE barber_id=?");
                $q2->execute([(int)$user['id']]);

                $stats=['bookings'=>(int)$q->fetchColumn(),'earnings'=>(float)$q2->fetchColumn()];
            }

            respond(['ok'=>true,'stats'=>$stats]);

        case 'admin_users':
            require_manager_scope('admin','super_admin');

            /* جلب قائمة الحسابات النشطة للإدارة العامة مع اسم الصالون المرتبط بكل حساب */
            $q=$pdo->query("SELECT u.id,u.name,u.phone,u.email,u.role,u.salon_id,u.is_available,u.rating,u.location,u.created_at,u.deleted_at,s.name AS salon_name FROM users u LEFT JOIN salons s ON s.id=u.salon_id WHERE u.deleted_at IS NULL ORDER BY u.id DESC LIMIT 1000");
            respond(['ok'=>true,'users'=>$q->fetchAll()]);

        case 'trash_users':
            require_manager_scope('admin','super_admin','salon_admin');

            /* جلب الحسابات المحذوفة مع تطبيق نطاق الصلاحية حسب نوع المدير */
            if (in_array($role,['admin','super_admin'],true)) {
                $q=$pdo->query("SELECT u.id,u.name,u.phone,u.email,u.role,u.salon_id,u.is_available,u.rating,u.location,u.created_at,u.deleted_at,s.name AS salon_name FROM users u LEFT JOIN salons s ON s.id=u.salon_id WHERE u.deleted_at IS NOT NULL ORDER BY u.deleted_at DESC LIMIT 1000");
            } else {
                $q=$pdo->prepare("SELECT u.id,u.name,u.phone,u.email,u.role,u.salon_id,u.is_available,u.rating,u.location,u.created_at,u.deleted_at,s.name AS salon_name FROM users u LEFT JOIN salons s ON s.id=u.salon_id WHERE u.deleted_at IS NOT NULL AND u.role='barber' AND u.salon_id=? ORDER BY u.deleted_at DESC LIMIT 500");
                $q->execute([(int)$user['salon_id']]);
            }

            /* حساب عدد الأيام المتبقية للاستعادة وإظهار الحسابات التي انتهت مدتها */
            $rows=$q->fetchAll();

            foreach ($rows as &$row) {
                $deletedAt=strtotime((string)$row['deleted_at']);

                if ($deletedAt===false) {
                    $row['restore_days_left']=0;
                    $row['restore_expired']=true;
                    continue;
                }

                $expiresAt=$deletedAt+(TRASH_RETENTION_DAYS*86400);
                $row['restore_days_left']=(int)ceil(max(0,$expiresAt-time())/86400);
                $row['restore_expired']=time()>$expiresAt;
            }

            unset($row);
            respond(['ok'=>true,'retention_days'=>TRASH_RETENTION_DAYS,'users'=>$rows]);

        case 'restore_user':
            require_manager_scope('admin','super_admin','salon_admin');

            /* جلب الحساب المحذوف والتحقق من أنه داخل سلة المهملات وضمن صلاحية المدير */
            $id=positive_id($data['id']??0);
            $target=get_user_by_id($id,true);

            if (!$target) respond(['ok'=>false,'error'=>'الحساب غير موجود'],404);
            if (empty($target['deleted_at'])) respond(['ok'=>false,'error'=>'الحساب غير موجود في السلة'],409);

            ensure_trash_scope($target);
            ensure_restore_period($target);
            ensure_unique_user_contact((string)$target['phone'],$target['email']!==null?(string)$target['email']:null,$id);

            /* التأكد من أن الصالون المرتبط بالحساب ما زال موجوداً ونشطاً قبل الاستعادة */
            if (in_array((string)$target['role'],['barber','salon_admin'],true)) {
                $sid=(int)$target['salon_id'];

                if ($sid<=0) respond(['ok'=>false,'error'=>'لا يمكن استعادة الحساب لأن الصالون غير محدد'],409);

                $q=$pdo->prepare("SELECT id FROM salons WHERE id=? AND deleted_at IS NULL LIMIT 1");
                $q->execute([$sid]);

                if (!$q->fetchColumn()) respond(['ok'=>false,'error'=>'لا يمكن استعادة الحساب لأن الصالون محذوف أو غير موجود'],409);
            }

            /* استعادة الحساب داخل معاملة لضمان نجاح التغيير كاملاً أو التراجع عنه */
            $pdo->beginTransaction();

            try {
                $q=$pdo->prepare("UPDATE users SET deleted_at=NULL,is_available=CASE WHEN role='barber' THEN 1 ELSE is_available END WHERE id=? AND deleted_at IS NOT NULL");
                $q->execute([$id]);

                if ($q->rowCount()!==1) throw new RuntimeException('تعذر استعادة الحساب');

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            write_audit((int)$user['id'],'restore_user','user',$id);
            respond(['ok'=>true,'message'=>'تمت استعادة الحساب بنجاح']);

        case 'permanent_delete_user':
            require_manager_scope('admin','super_admin','salon_admin');

            /* التحقق من أن الحساب موجود ومحذوف وأن المدير يملك صلاحية حذفه نهائياً */
            $id=positive_id($data['id']??0);
            $target=get_user_by_id($id,true);

            if (!$target) respond(['ok'=>false,'error'=>'الحساب غير موجود'],404);
            if (empty($target['deleted_at'])) respond(['ok'=>false,'error'=>'لا يمكن الحذف النهائي لحساب نشط'],409);

            ensure_trash_scope($target);

            if (in_array((string)$target['role'],['admin','super_admin'],true)) {
                respond(['ok'=>false,'error'=>'لا يمكن حذف حسابات الإدارة العليا نهائياً من هذه العملية'],403);
            }

            /* قفل الحساب ثم إعادة فحص الصلاحيات والعلاقات قبل الحذف لمنع تعارض العمليات */
            $pdo->beginTransaction();

            try {
                $lock=$pdo->prepare("SELECT id,role,salon_id,deleted_at FROM users WHERE id=? FOR UPDATE");
                $lock->execute([$id]);
                $lockedTarget=$lock->fetch();

                if (!$lockedTarget) throw new RuntimeException('الحساب غير موجود');
                if (empty($lockedTarget['deleted_at'])) throw new RuntimeException('لا يمكن الحذف النهائي لحساب نشط');

                /* إعادة فحص نوع الحساب بعد القفل تمنع حذف حساب تغيّر وضعه قبل العملية */
                if (in_array((string)$lockedTarget['role'],['admin','super_admin'],true)) {
                    throw new RuntimeException('لا يمكن حذف حسابات الإدارة العليا نهائياً من هذه العملية');
                }

                if ($role==='salon_admin'&&((string)$lockedTarget['role']!=='barber'||(int)$lockedTarget['salon_id']!==(int)$user['salon_id'])) {
                    throw new RuntimeException('الحساب خارج صلاحية صالونك');
                }

                /* التأكد من عدم وجود سجلات أساسية مرتبطة بالحساب قبل إزالته من قاعدة البيانات */
                $reason=permanent_delete_block_reason($id);

                if ($reason!==null) {
                    throw new RuntimeException('لا يمكن الحذف النهائي لأن الحساب مرتبط بسجلات محفوظة: '.$reason);
                }

                delete_user_auxiliary_records($id);

                $q=$pdo->prepare("DELETE FROM users WHERE id=? AND deleted_at IS NOT NULL");
                $q->execute([$id]);

                if ($q->rowCount()!==1) throw new RuntimeException('تعذر حذف الحساب نهائياً');

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();

                if (str_starts_with($e->getMessage(),'لا يمكن الحذف النهائي')||$e->getMessage()==='الحساب خارج صلاحية صالونك') {
                    respond(['ok'=>false,'error'=>$e->getMessage()],409);
                }

                throw $e;
            }

            write_audit((int)$user['id'],'permanent_delete_user','user',$id);
            respond(['ok'=>true,'message'=>'تم حذف الحساب نهائياً']);

        case 'admin_salons':
            require_manager_scope('admin','super_admin');

            /* جلب الصوالين النشطة لعرضها وإدارتها من لوحة الإدارة العامة */
            $q=$pdo->query("SELECT id,name,location,phone,created_at FROM salons WHERE deleted_at IS NULL ORDER BY id DESC");
            respond(['ok'=>true,'salons'=>$q->fetchAll()]);

        case 'reset_requests':
            require_manager_scope('admin','super_admin');

            /* جلب طلبات استعادة كلمات المرور المعلقة للحسابات النشطة فقط */
            $q=$pdo->query("SELECT r.id,r.requested_at,r.status,u.name,u.phone,u.email FROM password_reset_requests r JOIN users u ON u.id=r.user_id WHERE r.status='pending' AND u.deleted_at IS NULL ORDER BY r.id DESC LIMIT 100");
            respond(['ok'=>true,'requests'=>$q->fetchAll()]);

        case 'approve_reset':
            require_manager_scope('admin','super_admin');

            /* التحقق من طلب الاستعادة وكلمة المرور الجديدة قبل تغيير بيانات الحساب */
            $rid=positive_id($data['request_id']??0,'request_id');
            $new=(string)($data['password']??'');

            if (mb_strlen($new,'UTF-8')<8) respond(['ok'=>false,'error'=>'كلمة المرور لا تقل عن 8 أحرف'],422);

            $q=$pdo->prepare("SELECT r.id,r.user_id,r.requested_at,u.deleted_at FROM password_reset_requests r JOIN users u ON u.id=r.user_id WHERE r.id=? AND r.status='pending' LIMIT 1");
            $q->execute([$rid]);
            $reset=$q->fetch();

            if (!$reset) respond(['ok'=>false,'error'=>'طلب الاستعادة غير موجود أو تمت معالجته'],404);
            if ($reset['deleted_at']!==null) respond(['ok'=>false,'error'=>'لا يمكن تغيير كلمة مرور حساب محذوف'],409);

            /* تغيير كلمة المرور وتحديد الطلب كمستخدم داخل معاملة واحدة */
            $uid=(int)$reset['user_id'];
            $pdo->beginTransaction();

            try {
                $q=$pdo->prepare("UPDATE users SET password=?,must_change_password=0 WHERE id=? AND deleted_at IS NULL");
                $q->execute([password_hash($new,PASSWORD_DEFAULT),$uid]);

                if ($q->rowCount()!==1) throw new RuntimeException('تعذر تحديث كلمة المرور');

                $q=$pdo->prepare("UPDATE password_reset_requests SET status='used' WHERE id=? AND status='pending'");
                $q->execute([$rid]);

                if ($q->rowCount()!==1) throw new RuntimeException('تعذر تحديث حالة طلب الاستعادة');

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            write_audit((int)$user['id'],'approve_password_reset','password_reset_request',$rid);
            respond(['ok'=>true,'message'=>'تم تغيير كلمة المرور']);

        case 'admin_bookings':
            require_manager_scope('admin','super_admin');

            /* جلب آخر الحجوزات مع بيانات العميل والحلاق والخدمة والصالون للإدارة العامة */
            $q=$pdo->query("SELECT b.id,b.date,b.start_time,b.end_time,b.total_amount,b.status,c.name AS customer_name,bar.name AS barber_name,s.name AS service_name,bar.salon_id,sal.name AS salon_name FROM bookings b JOIN users c ON c.id=b.customer_id JOIN users bar ON bar.id=b.barber_id LEFT JOIN services s ON s.id=b.service_id LEFT JOIN salons sal ON sal.id=bar.salon_id ORDER BY b.id DESC LIMIT 1000");
            respond(['ok'=>true,'bookings'=>$q->fetchAll()]);

        case 'save_salon':
            require_manager_scope('admin','super_admin');

            /* التحقق من بيانات الصالون ثم إنشاء صالون جديد أو تحديث صالون موجود */
            $id=!empty($data['id'])?positive_id($data['id']):0;
            $name=trim((string)($data['name']??''));
            $loc=trim((string)($data['location']??''));
            $phone=trim((string)($data['phone']??''));

            if ($name===''||mb_strlen($name,'UTF-8')>100||$loc===''||mb_strlen($loc,'UTF-8')>150) {
                respond(['ok'=>false,'error'=>'بيانات الصالون غير صحيحة'],422);
            }

            if ($id) {
                $q=$pdo->prepare("SELECT id FROM salons WHERE id=? AND deleted_at IS NULL LIMIT 1");
                $q->execute([$id]);

                if (!$q->fetchColumn()) respond(['ok'=>false,'error'=>'الصالون غير موجود'],404);

                $q=$pdo->prepare("UPDATE salons SET name=?,location=?,phone=? WHERE id=? AND deleted_at IS NULL");
                $q->execute([$name,$loc,$phone!==''?$phone:null,$id]);
                $auditAction='update_salon';
            } else {
                /* إنشاء إعدادات الصالون مع الصالون داخل معاملة حتى لا يبقى الصالون بدون إعدادات */
                $pdo->beginTransaction();

                try {
                    $q=$pdo->prepare("INSERT INTO salons (name,location,phone) VALUES (?,?,?)");
                    $q->execute([$name,$loc,$phone!==''?$phone:null]);
                    $id=(int)$pdo->lastInsertId();

                    $pdo->prepare("INSERT INTO salon_settings (salon_id) VALUES (?)")->execute([$id]);
                    $pdo->commit();
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    throw $e;
                }

                $auditAction='create_salon';
            }

            write_audit((int)$user['id'],$auditAction,'salon',$id);
            respond(['ok'=>true,'message'=>'تم الحفظ']);

        case 'delete_salon':
            require_manager_scope('admin','super_admin');

            /* منع حذف الصالون إذا كانت لديه حسابات حلاقين أو مديري صالون نشطة */
            $id=positive_id($data['id']??0);
            $q=$pdo->prepare("SELECT id FROM salons WHERE id=? AND deleted_at IS NULL LIMIT 1");
            $q->execute([$id]);

            if (!$q->fetchColumn()) respond(['ok'=>false,'error'=>'الصالون غير موجود'],404);

            $q=$pdo->prepare("SELECT COUNT(*) FROM users WHERE salon_id=? AND role IN ('barber','salon_admin') AND deleted_at IS NULL");
            $q->execute([$id]);

            if ((int)$q->fetchColumn()>0) {
                respond(['ok'=>false,'error'=>'لا يمكن حذف صالون لديه حسابات مرتبطة؛ عطّلها أو انقلها أولاً'],409);
            }

            /* نقل الصالون إلى الحذف الناعم بدلاً من حذفه من قاعدة البيانات */
            $q=$pdo->prepare("UPDATE salons SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL");
            $q->execute([$id]);

            if ($q->rowCount()!==1) respond(['ok'=>false,'error'=>'تعذر حذف الصالون'],409);

            write_audit((int)$user['id'],'delete_salon','salon',$id);
            respond(['ok'=>true,'message'=>'تم حذف الصالون']);

        case 'save_user':
            require_manager_scope('admin','super_admin');

            /* التحقق من بيانات المستخدم والدور ومنع المدير العادي من إنشاء أو إدارة حسابات إدارية أعلى */
            $id=!empty($data['id'])?positive_id($data['id']):0;
            $name=trim((string)($data['name']??''));
            $phone=trim((string)($data['phone']??''));
            $email=trim((string)($data['email']??''));
            $newRole=trim((string)($data['role']??'customer'));
            $allowed=['customer','barber','salon_admin','admin','super_admin'];

            if ($name===''||mb_strlen($name,'UTF-8')>100||$phone===''||mb_strlen($phone,'UTF-8')>30||!in_array($newRole,$allowed,true)) {
                respond(['ok'=>false,'error'=>'بيانات المستخدم غير صحيحة'],422);
            }

            if ($email!==''&&(mb_strlen($email,'UTF-8')>150||!filter_var($email,FILTER_VALIDATE_EMAIL))) {
                respond(['ok'=>false,'error'=>'البريد الإلكتروني غير صحيح'],422);
            }

            if ($role==='admin'&&in_array($newRole,['admin','super_admin'],true)) {
                respond(['ok'=>false,'error'=>'المدير العادي لا يدير حسابات الإدارة العليا'],403);
            }

            /* عند التعديل يتم جلب الحساب الحالي والتحقق من أن المدير يملك صلاحية تغييره */
            $target=null;

            if ($id) {
                $target=get_user_by_id($id,false);

                if (!$target) respond(['ok'=>false,'error'=>'الحساب غير موجود'],404);

                ensure_admin_can_manage_user($target);

                if ($target['role']!==$newRole&&$target['id']===$user['id']) {
                    respond(['ok'=>false,'error'=>'لا يمكنك تغيير صلاحية حسابك'],409);
                }
            }

            /* تحديد الصالون حسب الدور والتأكد من أن الصالون موجود وغير محذوف */
            $sid=null;

            if (isset($data['salon_id'])&&$data['salon_id']!==''&&$data['salon_id']!==null) {
                $sid=positive_id($data['salon_id'],'salon_id');
            }

            if (in_array($newRole,['barber','salon_admin'],true)&&$sid===null) {
                respond(['ok'=>false,'error'=>'هذا الدور يحتاج صالوناً'],422);
            }

            if (in_array($newRole,['customer','admin','super_admin'],true)) $sid=null;

            if ($sid!==null) {
                $q=$pdo->prepare("SELECT id FROM salons WHERE id=? AND deleted_at IS NULL LIMIT 1");
                $q->execute([$sid]);

                if (!$q->fetchColumn()) respond(['ok'=>false,'error'=>'الصالون غير موجود أو محذوف'],404);
            }

            /* منع تكرار بيانات الاتصال ثم حفظ الحساب مع تشفير كلمة المرور عند إنشائها أو تغييرها */
            $email=$email!==''?$email:null;
            ensure_unique_user_contact($phone,$email,$id);

            if ($id) {
                $password=trim((string)($data['password']??''));

                if ($password!==''&&mb_strlen($password,'UTF-8')<8) {
                    respond(['ok'=>false,'error'=>'كلمة المرور لا تقل عن 8 أحرف'],422);
                }

                if ($password!=='') {
                    $q=$pdo->prepare("UPDATE users SET name=?,phone=?,email=?,role=?,salon_id=?,password=?,must_change_password=0 WHERE id=? AND deleted_at IS NULL");
                    $q->execute([$name,$phone,$email,$newRole,$sid,password_hash($password,PASSWORD_DEFAULT),$id]);
                } else {
                    $q=$pdo->prepare("UPDATE users SET name=?,phone=?,email=?,role=?,salon_id=? WHERE id=? AND deleted_at IS NULL");
                    $q->execute([$name,$phone,$email,$newRole,$sid,$id]);
                }

                if ($q->rowCount()<1) respond(['ok'=>false,'error'=>'لم يتم تحديث بيانات المستخدم'],409);

                write_audit((int)$user['id'],'update_user','user',$id);
                respond(['ok'=>true,'message'=>'تم تحديث بيانات المستخدم']);
            }

            $password=(string)($data['password']??'');

            if (mb_strlen($password,'UTF-8')<8) {
                respond(['ok'=>false,'error'=>'كلمة المرور لا تقل عن 8 أحرف'],422);
            }

            $q=$pdo->prepare("INSERT INTO users (name,phone,email,password,role,salon_id,must_change_password) VALUES (?,?,?,?,?,?,0)");
            $q->execute([$name,$phone,$email,password_hash($password,PASSWORD_DEFAULT),$newRole,$sid]);
            $id=(int)$pdo->lastInsertId();

            write_audit((int)$user['id'],'create_user','user',$id);
            respond(['ok'=>true,'message'=>'تم إنشاء المستخدم']);

        case 'delete_user':
            require_manager_scope('admin','super_admin');

            /* التحقق من الحساب ثم نقله إلى السلة بالحذف الناعم مع تعطيل توفره */
            $id=positive_id($data['id']??0);
            $target=get_user_by_id($id,false);

            if (!$target) respond(['ok'=>false,'error'=>'الحساب غير موجود'],404);

            ensure_admin_can_manage_user($target);

            $q=$pdo->prepare("UPDATE users SET deleted_at=NOW(),is_available=0 WHERE id=? AND deleted_at IS NULL");
            $q->execute([$id]);

            if ($q->rowCount()!==1) respond(['ok'=>false,'error'=>'تعذر نقل الحساب إلى السلة'],409);

            write_audit((int)$user['id'],'delete_user','user',$id);
            respond(['ok'=>true,'message'=>'تم نقل الحساب إلى سلة المهملات']);

        case 'salon_data':
            require_manager_scope('salon_admin');

            /* جلب بيانات الصالون المطلوبة للوحة المدير بشكل منفصل وواضح */
            $sid=(int)($user['salon_id']??0);

            if ($sid<=0) respond(['ok'=>false,'error'=>'حساب مدير الصالون غير مرتبط بصولون'],409);

            $q=$pdo->prepare("SELECT id,name,phone,email,is_available,rating
                              FROM users
                              WHERE salon_id=? AND role='barber' AND deleted_at IS NULL
                              ORDER BY id DESC");
            $q->execute([$sid]);
            $barbers=$q->fetchAll();

            /* جلب الخدمات مع اسم الحلاق ومنع ظهور خدمات الحلاق المحذوف */
            $q=$pdo->prepare("SELECT s.id,s.barber_id,s.name,s.price,s.duration,
                                     s.is_vip,s.flash_price,s.flash_sale_end,s.created_at,
                                     u.name AS barber_name
                              FROM services s
                              INNER JOIN users u ON u.id=s.barber_id
                              WHERE u.salon_id=?
                                AND u.role='barber'
                                AND u.deleted_at IS NULL
                                AND s.deleted_at IS NULL
                              ORDER BY s.id DESC");
            $q->execute([$sid]);
            $services=$q->fetchAll();

            $q=$pdo->prepare("SELECT id,name,price,quantity,created_at
                              FROM tools
                              WHERE salon_id=?
                              ORDER BY id DESC");
            $q->execute([$sid]);
            $tools=$q->fetchAll();

            $q=$pdo->prepare("SELECT e.id,e.barber_id,e.salon_id,e.booking_id,e.amount,
                                     e.salon_amount,e.paid_at,e.note,
                                     u.name AS barber_name,
                                     b.date AS booking_date,
                                     b.total_amount AS booking_total
                              FROM barber_earnings e
                              INNER JOIN users u ON u.id=e.barber_id
                              INNER JOIN bookings b ON b.id=e.booking_id
                              WHERE e.salon_id=?
                              ORDER BY e.id DESC
                              LIMIT 500");
            $q->execute([$sid]);
            $earnings=$q->fetchAll();

            $q=$pdo->prepare("SELECT w.id,w.barber_id,w.salon_id,w.amount,
                                     w.payment_type,w.note,w.created_at,
                                     u.name AS barber_name
                              FROM barber_withdrawals w
                              INNER JOIN users u ON u.id=w.barber_id
                              WHERE w.salon_id=?
                              ORDER BY w.id DESC
                              LIMIT 500");
            $q->execute([$sid]);
            $withdrawals=$q->fetchAll();

            $q=$pdo->prepare("SELECT barber_percent,salon_percent
                              FROM salon_settings
                              WHERE salon_id=?
                              LIMIT 1");
            $q->execute([$sid]);
            $setting=$q->fetch();

            if (!$setting) {
                $setting=[
                    'barber_percent'=>50,
                    'salon_percent'=>50
                ];
            }

            respond([
                'ok'=>true,
                'barbers'=>$barbers,
                'services'=>$services,
                'tools'=>$tools,
                'earnings'=>$earnings,
                'withdrawals'=>$withdrawals,
                'setting'=>$setting
            ]);

        case 'salon_add_barber':
            require_manager_scope('salon_admin');

            /* التحقق من بيانات الحلاق والصالون ثم إنشاء الحساب وجدوله الأسبوعي داخل معاملة */
            $sid=(int)($user['salon_id']??0);

            if ($sid<=0) respond(['ok'=>false,'error'=>'الصالون غير محدد'],409);

            $name=trim((string)($data['name']??''));
            $phone=trim((string)($data['phone']??''));
            $email=trim((string)($data['email']??''));
            $password=(string)($data['password']??'');

            if ($name===''||$phone===''||mb_strlen($name,'UTF-8')>100||mb_strlen($phone,'UTF-8')>30||mb_strlen($password,'UTF-8')<8) {
                respond(['ok'=>false,'error'=>'الاسم والرقم وكلمة المرور (8 أحرف) مطلوبة'],422);
            }

            if ($email!==''&&(mb_strlen($email,'UTF-8')>150||!filter_var($email,FILTER_VALIDATE_EMAIL))) {
                respond(['ok'=>false,'error'=>'البريد الإلكتروني غير صحيح'],422);
            }

            $q=$pdo->prepare("SELECT id FROM salons WHERE id=? AND deleted_at IS NULL LIMIT 1");
            $q->execute([$sid]);

            if (!$q->fetchColumn()) respond(['ok'=>false,'error'=>'الصالون غير موجود'],404);

            $email=$email!==''?$email:null;
            ensure_unique_user_contact($phone,$email);

            $pdo->beginTransaction();

            try {
                /* حفظ البريد أيضاً حتى تتطابق إضافة الحلاق مع نموذج الواجهة */
                $q=$pdo->prepare("INSERT INTO users (name,phone,email,password,role,salon_id,must_change_password) VALUES (?,?,?,?,?,?,0)");
                $q->execute([$name,$phone,$email,password_hash($password,PASSWORD_DEFAULT),'barber',$sid]);
                $bid=(int)$pdo->lastInsertId();

                $schedule=$pdo->prepare("INSERT INTO barber_schedules (barber_id,day_of_week,start_time,end_time,is_off) VALUES (?,?,?,?,0)");

                for ($d=0;$d<7;$d++) {
                    $schedule->execute([$bid,$d,'09:00:00','21:00:00']);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            write_audit((int)$user['id'],'create_barber','user',$bid);
            respond(['ok'=>true,'message'=>'تمت إضافة الحلاق']);

        case 'salon_edit_barber':
            require_manager_scope('salon_admin');

            /* تحديث بيانات الحلاق كاملة مع إبقاء التحقق داخل نطاق صالون المدير */
            $sid=(int)($user['salon_id']??0);

            if ($sid<=0) respond(['ok'=>false,'error'=>'الصالون غير محدد'],409);

            $bid=positive_id($data['id']??0);
            $barber=ensure_barber_in_salon($bid,$sid);

            $name=trim((string)($data['name']??''));
            $phone=trim((string)($data['phone']??''));
            $email=trim((string)($data['email']??''));
            $password=(string)($data['password']??'');
            $isAvailable=!empty($data['is_available'])?1:0;

            if ($name===''||$phone===''||mb_strlen($name,'UTF-8')>100||mb_strlen($phone,'UTF-8')>30) {
                respond(['ok'=>false,'error'=>'الاسم ورقم الهاتف مطلوبان'],422);
            }

            if ($email!==''&&(mb_strlen($email,'UTF-8')>150||!filter_var($email,FILTER_VALIDATE_EMAIL))) {
                respond(['ok'=>false,'error'=>'البريد الإلكتروني غير صحيح'],422);
            }

            if ($password!==''&&mb_strlen($password,'UTF-8')<8) {
                respond(['ok'=>false,'error'=>'كلمة المرور لا تقل عن 8 أحرف'],422);
            }

            $email=$email!==''?$email:null;
            ensure_unique_user_contact($phone,$email,$bid);

            if ($password!=='') {
                $q=$pdo->prepare("UPDATE users
                                  SET name=?,phone=?,email=?,password=?,must_change_password=0,is_available=?
                                  WHERE id=? AND role='barber' AND salon_id=? AND deleted_at IS NULL");
                $q->execute([$name,$phone,$email,password_hash($password,PASSWORD_DEFAULT),$isAvailable,$bid,$sid]);
            } else {
                $q=$pdo->prepare("UPDATE users
                                  SET name=?,phone=?,email=?,is_available=?
                                  WHERE id=? AND role='barber' AND salon_id=? AND deleted_at IS NULL");
                $q->execute([$name,$phone,$email,$isAvailable,$bid,$sid]);
            }

            /* التحقق من أن الحفظ تم فعلاً ثم إرجاع البيانات الجديدة للواجهة */
            $updated=get_user_by_id($bid,false);

            if (!$updated||($updated['role']??'')!=='barber'||(int)$updated['salon_id']!==$sid) {
                respond(['ok'=>false,'error'=>'تعذر تحديث بيانات الحلاق'],409);
            }

            write_audit((int)$user['id'],'update_barber','user',$bid);

            respond([
                'ok'=>true,
                'message'=>'تم تحديث بيانات الحلاق',
                'barber'=>[
                    'id'=>(int)$updated['id'],
                    'name'=>$updated['name'],
                    'phone'=>$updated['phone'],
                    'email'=>$updated['email'],
                    'is_available'=>(int)$updated['is_available'],
                    'rating'=>(float)$updated['rating']
                ]
            ]);

        case 'salon_delete_barber':
            require_manager_scope('salon_admin');

            /* نقل الحلاق إلى السلة مع تعطيل توفره بعد التأكد من ارتباطه بالصالون */
            $sid=(int)$user['salon_id'];
            $bid=positive_id($data['id']??0);
            ensure_barber_in_salon($bid,$sid);

            $q=$pdo->prepare("UPDATE users SET deleted_at=NOW(),is_available=0 WHERE id=? AND role='barber' AND salon_id=? AND deleted_at IS NULL");
            $q->execute([$bid,$sid]);

            if ($q->rowCount()!==1) respond(['ok'=>false,'error'=>'تعذر حذف الحلاق'],409);

            write_audit((int)$user['id'],'delete_barber','user',$bid);
            respond(['ok'=>true,'message'=>'تم نقل الحلاق إلى سلة المهملات']);

        case 'salon_save_service':
            require_manager_scope('salon_admin');

            /* التحقق من الصالون والحلاق وبيانات الخدمة قبل الحفظ */
            $sid=(int)($user['salon_id']??0);

            if ($sid<=0) respond(['ok'=>false,'error'=>'الصالون غير محدد'],409);

            $bid=positive_id($data['barber_id']??0,'barber_id');
            ensure_barber_in_salon($bid,$sid);

            $id=!empty($data['id'])?positive_id($data['id']):0;
            $name=trim((string)($data['name']??''));
            $price=manager_amount_value($data['price']??null,'السعر');
            $duration=positive_id($data['duration']??0,'duration');

            if ($name===''||mb_strlen($name,'UTF-8')>100) {
                respond(['ok'=>false,'error'=>'اسم الخدمة مطلوب'],422);
            }

            if ($duration<1||$duration>1440) {
                respond(['ok'=>false,'error'=>'مدة الخدمة يجب أن تكون بين دقيقة و1440 دقيقة'],422);
            }

            $isVip=!empty($data['is_vip'])?1:0;

            /* السعر المخفض ونهاية العرض اختياريان */
            $flashPrice=null;
            $flashValue=$data['flash_price']??'';

            if ($flashValue!=='') {
                $flashPrice=manager_amount_value($flashValue,'السعر المخفض');
            }

            $flashEnd=trim((string)($data['flash_sale_end']??''));

            if ($flashEnd!=='') {
                $flashEnd=str_replace('T',' ',$flashEnd);

                if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/',$flashEnd)) {
                    $flashEnd.=':00';
                }

                $date=DateTimeImmutable::createFromFormat('Y-m-d H:i:s',$flashEnd);
                $errors=DateTimeImmutable::getLastErrors();

                if (!$date||($errors!==false&&($errors['warning_count']>0||$errors['error_count']>0))) {
                    respond(['ok'=>false,'error'=>'تاريخ نهاية العرض غير صحيح'],422);
                }

                $flashEnd=$date->format('Y-m-d H:i:s');
            } else {
                $flashEnd=null;
            }

            if ($id) {
                /* التأكد من ملكية الخدمة للصالون قبل تعديلها */
                $q=$pdo->prepare("SELECT s.id
                                  FROM services s
                                  INNER JOIN users u ON u.id=s.barber_id
                                  WHERE s.id=?
                                    AND u.salon_id=?
                                    AND u.role='barber'
                                    AND u.deleted_at IS NULL
                                    AND s.deleted_at IS NULL
                                  LIMIT 1");
                $q->execute([$id,$sid]);

                if (!$q->fetchColumn()) {
                    respond(['ok'=>false,'error'=>'الخدمة غير موجودة أو خارج صلاحية الصالون'],404);
                }

                $q=$pdo->prepare("UPDATE services
                                  SET barber_id=?,name=?,price=?,duration=?,
                                      is_vip=?,flash_price=?,flash_sale_end=?
                                  WHERE id=? AND deleted_at IS NULL");
                $q->execute([
                    $bid,
                    $name,
                    $price,
                    $duration,
                    $isVip,
                    $flashPrice,
                    $flashEnd,
                    $id
                ]);

                $auditAction='update_service';
                $message='تم تعديل الخدمة بنجاح';
            } else {
                $q=$pdo->prepare("INSERT INTO services
                                  (barber_id,name,price,duration,is_vip,flash_price,flash_sale_end)
                                  VALUES (?,?,?,?,?,?,?)");
                $q->execute([
                    $bid,
                    $name,
                    $price,
                    $duration,
                    $isVip,
                    $flashPrice,
                    $flashEnd
                ]);

                $id=(int)$pdo->lastInsertId();
                $auditAction='create_service';
                $message='تمت إضافة الخدمة بنجاح';
            }

            /* إعادة جلب الخدمة بعد الحفظ للتأكد من نجاح العملية وإرجاع البيانات الفعلية */
            $q=$pdo->prepare("SELECT s.id,s.barber_id,s.name,s.price,s.duration,
                                     s.is_vip,s.flash_price,s.flash_sale_end,s.created_at,
                                     u.name AS barber_name
                              FROM services s
                              INNER JOIN users u ON u.id=s.barber_id
                              WHERE s.id=?
                                AND u.salon_id=?
                                AND u.role='barber'
                                AND u.deleted_at IS NULL
                                AND s.deleted_at IS NULL
                              LIMIT 1");
            $q->execute([$id,$sid]);
            $savedService=$q->fetch();

            if (!$savedService) {
                respond(['ok'=>false,'error'=>'تم تنفيذ الحفظ لكن تعذر قراءة الخدمة بعد العملية'],409);
            }

            write_audit((int)$user['id'],$auditAction,'service',$id);

            respond([
                'ok'=>true,
                'message'=>$message,
                'id'=>$id,
                'service'=>$savedService
            ]);

        case 'salon_delete_service':
            require_manager_scope('salon_admin');

            /* التأكد من أن الخدمة تخص حلاقاً داخل الصالون ثم تطبيق الحذف الناعم */
            $sid=(int)$user['salon_id'];
            $id=positive_id($data['id']??0);

            $q=$pdo->prepare("SELECT s.id FROM services s JOIN users u ON u.id=s.barber_id WHERE s.id=? AND u.salon_id=? AND u.deleted_at IS NULL AND s.deleted_at IS NULL");
            $q->execute([$id,$sid]);

            if (!$q->fetch()) respond(['ok'=>false,'error'=>'الخدمة غير تابعة للصالون'],403);

            $q=$pdo->prepare("UPDATE services SET deleted_at=NOW() WHERE id=? AND deleted_at IS NULL");
            $q->execute([$id]);

            if ($q->rowCount()!==1) respond(['ok'=>false,'error'=>'تعذر حذف الخدمة'],409);

            write_audit((int)$user['id'],'delete_service','service',$id);
            respond(['ok'=>true,'message'=>'تم حذف الخدمة']);

        case 'salon_tool':
            require_manager_scope('salon_admin');

            /* إضافة أو تعديل أداة داخل الصالون الحالي فقط */
            $sid=(int)$user['salon_id'];

            if ($sid<=0) respond(['ok'=>false,'error'=>'الصالون غير محدد'],409);

            $id=!empty($data['id'])?positive_id($data['id']):0;
            $name=trim((string)($data['name']??''));
            $price=manager_amount_value($data['price']??0,'سعر الأداة');
            $qty=positive_id($data['quantity']??1,'quantity');

            if ($name===''||mb_strlen($name,'UTF-8')>100) {
                respond(['ok'=>false,'error'=>'اسم الأداة مطلوب'],422);
            }

            $q=$pdo->prepare("SELECT id FROM salons WHERE id=? AND deleted_at IS NULL LIMIT 1");
            $q->execute([$sid]);

            if (!$q->fetchColumn()) respond(['ok'=>false,'error'=>'الصالون غير موجود'],404);

            if ($id) {
                $q=$pdo->prepare("SELECT id FROM tools WHERE id=? AND salon_id=? LIMIT 1");
                $q->execute([$id,$sid]);

                if (!$q->fetchColumn()) respond(['ok'=>false,'error'=>'الأداة غير موجودة أو خارج صلاحية الصالون'],404);

                $q=$pdo->prepare("UPDATE tools SET name=?,price=?,quantity=? WHERE id=? AND salon_id=?");
                $q->execute([$name,$price,$qty,$id,$sid]);
                $auditAction='update_tool';
            } else {
                $q=$pdo->prepare("INSERT INTO tools (salon_id,name,price,quantity) VALUES (?,?,?,?)");
                $q->execute([$sid,$name,$price,$qty]);
                $id=(int)$pdo->lastInsertId();
                $auditAction='create_tool';
            }

            write_audit((int)$user['id'],$auditAction,'tool',$id);
            respond(['ok'=>true,'message'=>'تم حفظ الأداة']);

        case 'salon_delete_tool':
            require_manager_scope('salon_admin');

            /* حذف الأداة فقط إذا كانت مرتبطة بالصالون الحالي لمنع الوصول إلى أدوات صالون آخر */
            $sid=(int)$user['salon_id'];
            $id=positive_id($data['id']??0);

            $q=$pdo->prepare("DELETE FROM tools WHERE id=? AND salon_id=?");
            $q->execute([$id,$sid]);

            if ($q->rowCount()<1) respond(['ok'=>false,'error'=>'الأداة غير موجودة أو خارج صلاحية الصالون'],404);

            write_audit((int)$user['id'],'delete_tool','tool',$id);
            respond(['ok'=>true,'message'=>'تم حذف الأداة']);

        case 'salon_percent':
            require_manager_scope('salon_admin');

            /* التحقق من النسبة وحفظ نسبة الحلاق ونسبة الصالون بدقة منزلتين */
            $sid=(int)$user['salon_id'];

            if ($sid<=0) respond(['ok'=>false,'error'=>'الصالون غير محدد'],409);

            $bp=(float)($data['barber_percent']??50);

            if (!is_finite($bp)||$bp<0||$bp>100) {
                respond(['ok'=>false,'error'=>'النسبة غير صحيحة'],422);
            }

            $bp=round($bp,2);
            $sp=round(100-$bp,2);

            $q=$pdo->prepare("INSERT INTO salon_settings (salon_id,barber_percent,salon_percent) VALUES (?,?,?) ON DUPLICATE KEY UPDATE barber_percent=VALUES(barber_percent),salon_percent=VALUES(salon_percent)");
            $q->execute([$sid,$bp,$sp]);

            write_audit((int)$user['id'],'update_salon_percent','salon',$sid);
            respond(['ok'=>true,'message'=>'تم حفظ النسب']);

        case 'salon_withdraw':
            require_manager_scope('salon_admin');

            /* التحقق من المبلغ وقفل حساب الحلاق قبل حساب الرصيد */
            $sid=(int)$user['salon_id'];

            if ($sid<=0) respond(['ok'=>false,'error'=>'الصالون غير محدد'],409);

            $bid=positive_id($data['barber_id']??0,'barber_id');
            ensure_barber_in_salon($bid,$sid);

            $amount=(float)($data['amount']??0);

            if (!is_finite($amount)||$amount<=0) {
                respond(['ok'=>false,'error'=>'المبلغ غير صحيح'],422);
            }

            $amount=round($amount,2);
            $paymentType=trim((string)($data['payment_type']??'نقد'));
            $note=trim((string)($data['note']??''));

            if ($paymentType===''||mb_strlen($paymentType,'UTF-8')>50) {
                respond(['ok'=>false,'error'=>'نوع الدفع غير صحيح'],422);
            }

            if (mb_strlen($note,'UTF-8')>255) {
                respond(['ok'=>false,'error'=>'الملاحظة طويلة جداً'],422);
            }

            $pdo->beginTransaction();

            try {
                /* قفل حساب الحلاق قبل حساب الرصيد لمنع عمليتي سحب متزامنتين */
                $q=$pdo->prepare("SELECT id FROM users WHERE id=? AND role='barber' AND salon_id=? AND deleted_at IS NULL FOR UPDATE");
                $q->execute([$bid,$sid]);

                if (!$q->fetchColumn()) throw new RuntimeException('الحلاق غير تابع للصالون أو غير متاح');

                /* جمع الأرباح والسحوبات الحالية لمعرفة الرصيد الحقيقي المتاح للحلاق */
                $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM barber_earnings WHERE barber_id=?");
                $q->execute([$bid]);
                $earned=round((float)$q->fetchColumn(),2);

                $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM barber_withdrawals WHERE barber_id=?");
                $q->execute([$bid]);
                $withdrawn=round((float)$q->fetchColumn(),2);

                $balance=round($earned-$withdrawn,2);

                if ($amount>$balance) {
                    $pdo->rollBack();
                    respond(['ok'=>false,'error'=>'المبلغ أكبر من رصيد الحلاق'],409);
                }

                $q=$pdo->prepare("INSERT INTO barber_withdrawals (barber_id,salon_id,amount,payment_type,note) VALUES (?,?,?,?,?)");
                $q->execute([$bid,$sid,$amount,$paymentType,$note!==''?$note:null]);
                $withdrawId=(int)$pdo->lastInsertId();

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();

                if ($e->getMessage()==='الحلاق غير تابع للصالون أو غير متاح') {
                    respond(['ok'=>false,'error'=>$e->getMessage()],404);
                }

                throw $e;
            }

            write_audit((int)$user['id'],'create_withdrawal','barber_withdrawal',$withdrawId);
            respond(['ok'=>true,'message'=>'تم تسجيل السحب']);

        case 'barber_data':
            require_manager_scope('barber');

            /* جلب حجوزات الحلاق وجدوله وأرباحه وسحوباته وحساب الرصيد المتبقي */
            $uid=(int)$user['id'];

            $q=$pdo->prepare("SELECT b.id,b.date,b.start_time,b.end_time,b.total_amount,b.status,b.payment_status,c.name AS customer_name,s.name AS service_name FROM bookings b JOIN users c ON c.id=b.customer_id LEFT JOIN services s ON s.id=b.service_id WHERE b.barber_id=? ORDER BY b.date DESC,b.start_time DESC LIMIT 300");
            $q->execute([$uid]);
            $bookings=$q->fetchAll();

            $q=$pdo->prepare("SELECT * FROM barber_schedules WHERE barber_id=? ORDER BY day_of_week");
            $q->execute([$uid]);
            $schedule=$q->fetchAll();

            $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM barber_earnings WHERE barber_id=?");
            $q->execute([$uid]);
            $earned=(float)$q->fetchColumn();

            $q=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM barber_withdrawals WHERE barber_id=?");
            $q->execute([$uid]);
            $withdrawn=(float)$q->fetchColumn();

            respond([
                'ok'=>true,
                'bookings'=>$bookings,
                'schedule'=>$schedule,
                'earned'=>$earned,
                'withdrawn'=>$withdrawn,
                'balance'=>round($earned-$withdrawn,2)
            ]);

        case 'barber_booking_status':
            require_manager_scope('barber');

            /* قفل الحجز والتحقق من انتقال الحالة ثم تسجيل أرباح الحلاق عند إكمال الحجز */
            $id=positive_id($data['id']??0);
            $status=trim((string)($data['status']??''));

            if (!in_array($status,['confirmed','completed','cancelled'],true)) {
                respond(['ok'=>false,'error'=>'الحالة غير مسموحة'],422);
            }

            $pdo->beginTransaction();

            try {
                /* قفل الحجز قبل قراءة حالته لمنع تنفيذ انتقالات متعارضة في الوقت نفسه */
                $q=$pdo->prepare("SELECT * FROM bookings WHERE id=? AND barber_id=? FOR UPDATE");
                $q->execute([$id,(int)$user['id']]);
                $booking=$q->fetch();

                if (!$booking) throw new RuntimeException('الحجز غير موجود');

                /* السماح فقط بانتقالات حالة الحجز المعرفة في النظام */
                $currentStatus=(string)$booking['status'];
                $allowedTransitions=[
                    'pending'=>['confirmed','cancelled'],
                    'confirmed'=>['completed','cancelled'],
                    'completed'=>[],
                    'cancelled'=>[],
                    'rejected'=>[]
                ];

                if (!isset($allowedTransitions[$currentStatus])||!in_array($status,$allowedTransitions[$currentStatus],true)) {
                    throw new RuntimeException('انتقال حالة الحجز غير مسموح');
                }

                $q=$pdo->prepare("UPDATE bookings SET status=? WHERE id=? AND barber_id=? AND status=?");
                $q->execute([$status,$id,(int)$user['id'],$currentStatus]);

                if ($q->rowCount()!==1) throw new RuntimeException('تعذر تحديث حالة الحجز');

                /* عند إكمال الحجز يتم إنشاء أرباح الحلاق مرة واحدة فقط وتسجيل ختم الولاء */
                if ($status==='completed') {
                    $q=$pdo->prepare("SELECT id FROM barber_earnings WHERE booking_id=? LIMIT 1");
                    $q->execute([$id]);

                    if (!$q->fetch()) {
                        $q=$pdo->prepare("SELECT barber_percent,salon_percent FROM salon_settings WHERE salon_id=(SELECT salon_id FROM users WHERE id=?) LIMIT 1");
                        $q->execute([(int)$user['id']]);
                        $set=$q->fetch()?:['barber_percent'=>50,'salon_percent'=>50];

                        $barberAmount=round((float)$booking['total_amount']*((float)$set['barber_percent']/100),2);
                        $salonAmount=(float)$booking['total_amount']-$barberAmount;

                        $q=$pdo->prepare("SELECT salon_id FROM users WHERE id=? LIMIT 1");
                        $q->execute([(int)$user['id']]);
                        $sid=(int)$q->fetchColumn();

                        if ($sid<=0) throw new RuntimeException('الصالون غير مرتبط بالحلاق');

                        $q=$pdo->prepare("INSERT INTO barber_earnings (barber_id,salon_id,booking_id,amount,salon_amount,paid_at,note) VALUES (?,?,?,?,?,NOW(),?)");
                        $q->execute([(int)$user['id'],$sid,$id,$barberAmount,$salonAmount,'booking_'.$id]);

                        $pdo->prepare("INSERT INTO loyalty_cards (customer_id,stamps) VALUES (?,1) ON DUPLICATE KEY UPDATE stamps=LEAST(5,stamps+1)")->execute([(int)$booking['customer_id']]);
                    }
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();

                if (in_array($e->getMessage(),['الحجز غير موجود','انتقال حالة الحجز غير مسموح','تعذر تحديث حالة الحجز','الصالون غير مرتبط بالحلاق'],true)) {
                    respond(['ok'=>false,'error'=>$e->getMessage()],409);
                }

                throw $e;
            }

            write_audit((int)$user['id'],'update_booking_status','booking',$id);
            respond(['ok'=>true,'message'=>'تم تحديث الحجز']);

        case 'barber_schedule':
            require_manager_scope('barber');

            /* استبدال جدول الحلاق بالكامل بالبيانات الجديدة داخل معاملة واحدة */
            $rows=$data['days']??[];

            if (!is_array($rows)) respond(['ok'=>false,'error'=>'بيانات الجدول غير صحيحة'],422);

            $uid=(int)$user['id'];
            $pdo->beginTransaction();

            try {
                $pdo->prepare("DELETE FROM barber_schedules WHERE barber_id=?")->execute([$uid]);
                $insert=$pdo->prepare("INSERT INTO barber_schedules (barber_id,day_of_week,start_time,end_time,is_off) VALUES (?,?,?,?,?)");

                /* فحص كل يوم ووقته قبل إضافته إلى جدول الحلاق */
                foreach ($rows as $day=>$row) {
                    $d=(int)$day;

                    if ($d<0||$d>6||!is_array($row)) continue;

                    $off=!empty($row['is_off'])?1:0;
                    $start=substr((string)($row['start']??'09:00'),0,5);
                    $end=substr((string)($row['end']??'21:00'),0,5);

                    if (!valid_time($start)||!valid_time($end)) continue;
                    if ($start>=$end&&$off===0) continue;

                    $insert->execute([$uid,$d,$start,$end,$off]);
                }

                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            write_audit($uid,'update_barber_schedule','barber',$uid);
            respond(['ok'=>true,'message'=>'تم حفظ الجدول']);

        default:
            respond(['ok'=>false,'error'=>'العملية غير معروفة'],404);
    }
} catch (PDOException $e) {
    /* معالجة أخطاء قاعدة البيانات وإرجاع رسالة آمنة دون كشف تفاصيل SQL للمستخدم */
    error_log($e->getMessage());
    $msg=$e->getCode()==='23000'?'البيانات مكررة أو مرتبطة بسجل آخر':'تعذر تنفيذ العملية';
    respond(['ok'=>false,'error'=>$msg],409);
} catch (Throwable $e) {
    /* تسجيل الخطأ داخلياً وإخفاء تفاصيل الاستثناء عن واجهة المستخدم */
    error_log($e->getMessage());
    respond(['ok'=>false,'error'=>'حدث خطأ داخلي'],500);
}