<?php
declare(strict_types=1);

function find_active_barber(int $id): ?array
{
    $q = db()->prepare("SELECT u.id,u.name,u.rating,u.is_available,u.location,s.name AS salon_name,s.location AS salon_location
                        FROM users u LEFT JOIN salons s ON s.id=u.salon_id AND s.deleted_at IS NULL
                        WHERE u.id=? AND u.role='barber' AND u.deleted_at IS NULL LIMIT 1");
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

function get_barber_services(int $barberId): array
{
    $q = db()->prepare("SELECT id,name,price,duration,is_vip,flash_price,flash_sale_end
                        FROM services
                        WHERE barber_id=? AND deleted_at IS NULL
                        ORDER BY id DESC");
    $q->execute([$barberId]);
    return $q->fetchAll();
}

function available_slots(int $barberId, string $date, int $duration): array
{
    if (!valid_date($date) || $duration < 1 || $duration > 1440) {
        return [];
    }

    $day = (int)(new DateTimeImmutable($date))->format('w');
    $q = db()->prepare("SELECT start_time,end_time,is_off FROM barber_schedules WHERE barber_id=? AND day_of_week=? LIMIT 1");
    $q->execute([$barberId,$day]);
    $schedule = $q->fetch();
    if (!$schedule || (int)$schedule['is_off']) return [];

    $q = db()->prepare("SELECT start_time,end_time FROM bookings
                        WHERE barber_id=? AND date=? AND status IN ('pending','confirmed')
                        ORDER BY start_time");
    $q->execute([$barberId,$date]);
    $booked = $q->fetchAll();

    $start = new DateTimeImmutable("$date {$schedule['start_time']}");
    $end = new DateTimeImmutable("$date {$schedule['end_time']}");
    $now = new DateTimeImmutable('now');
    $slots = [];

    for ($cursor=$start; $cursor->modify("+{$duration} minutes") <= $end; $cursor=$cursor->modify('+10 minutes')) {
        if ($date === $now->format('Y-m-d') && $cursor <= $now->modify('+5 minutes')) continue;
        $slotEnd = $cursor->modify("+{$duration} minutes");
        $free = true;
        foreach ($booked as $b) {
            $bs = new DateTimeImmutable("$date {$b['start_time']}");
            $be = new DateTimeImmutable("$date {$b['end_time']}");
            if ($cursor < $be && $slotEnd > $bs) {
                $free = false; break;
            }
        }
        if ($free) $slots[] = $cursor->format('H:i');
    }
    return $slots;
}

function write_audit(?int $userId, string $action, ?string $entityType=null, ?int $entityId=null, array $details=[]): void
{
    $q = db()->prepare("INSERT INTO audit_logs(user_id,action,entity_type,entity_id,ip_address,details)
                        VALUES(?,?,?,?,?,?)");
    $q->execute([
        $userId, $action, $entityType, $entityId,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $details ? json_encode($details, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null
    ]);
}
