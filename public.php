<?php
declare(strict_types=1);
define('API_REQUEST', true);
require_once dirname(__DIR__) . '/app/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];
$data = request_data();
$action = trim((string)($data['action'] ?? ''));

if ($method === 'POST') verify_csrf();

$user = current_user();

try {
    switch ($action) {
        case 'barbers':
            $q = db()->query("SELECT u.id,u.name,u.rating,u.is_available,u.location,
                                     s.name AS salon_name,s.location AS salon_location
                              FROM users u
                              LEFT JOIN salons s ON s.id=u.salon_id AND s.deleted_at IS NULL
                              WHERE u.role='barber' AND u.deleted_at IS NULL
                              ORDER BY u.rating DESC,u.id DESC");
            respond(['ok'=>true,'barbers'=>$q->fetchAll()]);
        case 'services':
            $id = positive_id($data['barber_id'] ?? 0,'barber_id');
            respond(['ok'=>true,'services'=>get_barber_services($id)]);
        case 'offers':
            $flash = db()->query("SELECT s.id,s.barber_id,s.name,s.price,s.flash_price,s.flash_sale_end,u.name AS barber_name
                                  FROM services s JOIN users u ON u.id=s.barber_id
                                  WHERE s.deleted_at IS NULL AND u.deleted_at IS NULL
                                  AND s.flash_price IS NOT NULL AND s.flash_sale_end>NOW()
                                  ORDER BY s.flash_sale_end")->fetchAll();
            $vip = db()->query("SELECT s.id,s.barber_id,s.name,s.price,u.name AS barber_name
                                FROM services s JOIN users u ON u.id=s.barber_id
                                WHERE s.deleted_at IS NULL AND u.deleted_at IS NULL AND s.is_vip=1
                                ORDER BY s.id DESC LIMIT 100")->fetchAll();
            respond(['ok'=>true,'flash'=>$flash,'vip'=>$vip]);
        case 'slots':
            $barber = positive_id($data['barber_id'] ?? 0,'barber_id');
            $date = trim((string)($data['date'] ?? ''));
            $duration = positive_id($data['duration'] ?? 0,'duration');
            if (!valid_date($date)) respond(['ok'=>false,'error'=>'التاريخ غير صحيح'],422);
            respond(['ok'=>true,'slots'=>available_slots($barber,$date,$duration)]);
        case 'book':
            if (!$user || $user['role'] !== 'customer') respond(['ok'=>false,'error'=>'سجّل دخولك كزبون أولاً'],401);
            $barberId = positive_id($data['barber_id'] ?? 0,'barber_id');
            $date = trim((string)($data['date'] ?? ''));
            $time = trim((string)($data['time'] ?? ''));
            $ids = $data['service_ids'] ?? [];
            if (!is_array($ids) || !$ids) respond(['ok'=>false,'error'=>'اختر خدمة واحدة على الأقل'],422);
            if (!valid_date($date) || !valid_time($time)) respond(['ok'=>false,'error'=>'التاريخ أو الوقت غير صحيح'],422);
            if ($date < date('Y-m-d')) respond(['ok'=>false,'error'=>'لا يمكن الحجز في تاريخ سابق'],422);

            $ids = array_values(array_unique(array_map('intval',$ids)));
            if (count($ids)>20 || min($ids)<1) respond(['ok'=>false,'error'=>'الخدمات غير صحيحة'],422);

            $place = implode(',',array_fill(0,count($ids),'?'));
            $q = db()->prepare("SELECT id,name,price,duration,flash_price,flash_sale_end
                                FROM services
                                WHERE barber_id=? AND deleted_at IS NULL AND id IN ($place)");
            $q->execute(array_merge([$barberId],$ids));
            $services = $q->fetchAll();
            if (count($services)!==count($ids)) respond(['ok'=>false,'error'=>'إحدى الخدمات لم تعد متاحة'],409);

            $total=0; $duration=0;
            foreach($services as $s){
                $price = ($s['flash_price'] !== null && $s['flash_sale_end'] !== null && $s['flash_sale_end'] > date('Y-m-d H:i:s'))
                    ? (float)$s['flash_price'] : (float)$s['price'];
                $total += $price;
                $duration += (int)$s['duration'];
            }

            $start = new DateTimeImmutable("$date $time");
            $end = $start->modify("+{$duration} minutes");
            $day = (int)$start->format('w');
            $sq = $pdoSchedule = db()->prepare("SELECT start_time,end_time,is_off FROM barber_schedules WHERE barber_id=? AND day_of_week=? LIMIT 1");
            $sq->execute([$barberId,$day]);
            $schedule = $sq->fetch();
            if(!$schedule || (int)$schedule['is_off']) respond(['ok'=>false,'error'=>'الحلاق لا يعمل في هذا اليوم'],409);
            $workStart = new DateTimeImmutable("$date {$schedule['start_time']}");
            $workEnd = new DateTimeImmutable("$date {$schedule['end_time']}");
            if($start < $workStart || $end > $workEnd) respond(['ok'=>false,'error'=>'الموعد خارج ساعات عمل الحلاق'],409);
            $lockName = "salon_booking_{$barberId}_{$date}";
            $pdo = db();
            $lock = $pdo->query("SELECT GET_LOCK(".$pdo->quote($lockName).",5)")->fetchColumn();
            if ((int)$lock !== 1) respond(['ok'=>false,'error'=>'تعذر حجز الموعد الآن، حاول مرة أخرى'],409);

            try {
                $pdo->beginTransaction();
                $over = $pdo->prepare("SELECT id FROM bookings
                                       WHERE barber_id=? AND date=? AND status IN ('pending','confirmed')
                                       AND start_time < ? AND end_time > ?
                                       LIMIT 1 FOR UPDATE");
                $over->execute([$barberId,$date,$end->format('H:i:s'),$start->format('H:i:s')]);
                if ($over->fetch()) {
                    $pdo->rollBack();
                    respond(['ok'=>false,'error'=>'هذا الموعد حُجز للتو، اختر موعداً آخر'],409);
                }

                $barber = find_active_barber($barberId);
                if (!$barber) { $pdo->rollBack(); respond(['ok'=>false,'error'=>'الحلاق غير متاح'],404); }

                $ins=$pdo->prepare("INSERT INTO bookings(customer_id,barber_id,service_id,date,start_time,end_time,total_amount,total_duration,status)
                                    VALUES(?,?,?,?,?,?,?,?, 'pending')");
                $ins->execute([$user['id'],$barberId,$services[0]['id'],$date,$start->format('H:i:s'),$end->format('H:i:s'),number_format($total,2,'.',''),$duration]);
                $bookingId=(int)$pdo->lastInsertId();

                $item=$pdo->prepare("INSERT INTO booking_items(booking_id,service_id,service_name,unit_price,duration) VALUES(?,?,?,?,?)");
                foreach($services as $s){
                    $price = ($s['flash_price'] !== null && $s['flash_sale_end'] !== null && $s['flash_sale_end'] > date('Y-m-d H:i:s'))
                        ? $s['flash_price'] : $s['price'];
                    $item->execute([$bookingId,$s['id'],$s['name'],$price,$s['duration']]);
                }
                $pdo->commit();
                write_audit((int)$user['id'],'create_booking','booking',$bookingId,['barber_id'=>$barberId]);
                respond(['ok'=>true,'message'=>'تم إرسال الحجز بنجاح','booking_id'=>$bookingId]);
            } finally {
                $pdo->query("SELECT RELEASE_LOCK(".$pdo->quote($lockName).")");
            }

        case 'my_bookings':
            if (!$user || $user['role'] !== 'customer') respond(['ok'=>false,'error'=>'غير مصرح'],401);
            $q=db()->prepare("SELECT b.id,b.date,b.start_time,b.end_time,b.total_amount,b.status,
                                     u.name AS barber_name,s.name AS service_name
                              FROM bookings b JOIN users u ON u.id=b.barber_id
                              LEFT JOIN services s ON s.id=b.service_id
                              WHERE b.customer_id=? ORDER BY b.id DESC LIMIT 100");
            $q->execute([$user['id']]);
            respond(['ok'=>true,'bookings'=>$q->fetchAll()]);
        case 'loyalty':
            if (!$user || $user['role'] !== 'customer') respond(['ok'=>false,'error'=>'غير مصرح'],401);
            $q=db()->prepare("SELECT stamps FROM loyalty_cards WHERE customer_id=?");
            $q->execute([$user['id']]); $st=$q->fetchColumn();
            respond(['ok'=>true,'stamps'=>(int)($st??0)]);
        case 'pending_rating':
            if (!$user || $user['role'] !== 'customer') respond(['ok'=>true,'booking'=>null]);
            $q=db()->prepare("SELECT b.id,b.barber_id,b.date,u.name AS barber_name,s.name AS service_name
                              FROM bookings b JOIN users u ON u.id=b.barber_id
                              LEFT JOIN services s ON s.id=b.service_id
                              LEFT JOIN ratings r ON r.booking_id=b.id
                              WHERE b.customer_id=? AND b.status='completed' AND r.id IS NULL
                              ORDER BY b.date DESC,b.id DESC LIMIT 1");
            $q->execute([$user['id']]); respond(['ok'=>true,'booking'=>$q->fetch()?:null]);
        case 'rate':
            if (!$user || $user['role'] !== 'customer') respond(['ok'=>false,'error'=>'غير مصرح'],401);
            $bookingId=positive_id($data['booking_id']??0,'booking_id');
            $rating=(int)($data['rating']??0);
            $comment=trim((string)($data['comment']??''));
            if($rating<1||$rating>5||mb_strlen($comment,'UTF-8')>500) respond(['ok'=>false,'error'=>'التقييم غير صحيح'],422);
            $q=db()->prepare("SELECT barber_id FROM bookings WHERE id=? AND customer_id=? AND status='completed' LIMIT 1");
            $q->execute([$bookingId,$user['id']]); $booking=$q->fetch();
            if(!$booking) respond(['ok'=>false,'error'=>'الحجز غير صالح للتقييم'],404);
            try {
                db()->beginTransaction();
                db()->prepare("INSERT INTO ratings(booking_id,customer_id,barber_id,rating,comment) VALUES(?,?,?,?,?)")
                    ->execute([$bookingId,$user['id'],$booking['barber_id'],$rating,$comment?:null]);
                db()->prepare("UPDATE users SET rating=(SELECT ROUND(AVG(rating),1) FROM ratings WHERE barber_id=?) WHERE id=?")
                    ->execute([$booking['barber_id'],$booking['barber_id']]);
                db()->commit();
            } catch(Throwable $e){ if(db()->inTransaction())db()->rollBack(); respond(['ok'=>false,'error'=>'تم تقييم الحجز مسبقاً أو تعذر الحفظ'],409); }
            respond(['ok'=>true,'message'=>'تم حفظ التقييم']);
        default:
            respond(['ok'=>false,'error'=>'العملية غير معروفة'],404);
    }
} catch (Throwable $e) {
    error_log($e->getMessage());
    respond(['ok'=>false,'error'=>'حدث خطأ داخلي'],500);
}
