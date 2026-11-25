<?php
declare(strict_types=1);

require_once __DIR__ . '/customer_auth.php';

function favorite_car_ids(): array
{
    static $ids = null;
    if ($ids === null) {
        $ids = [];
        if ($user = current_user()) {
            $stmt = db()->prepare('SELECT car_id FROM customer_favorites WHERE user_id = ?');
            $stmt->execute([$user['user_id']]);
            $ids = array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
        }
    }
    return $ids;
}

function request_test_drive(int $userId, int $carId, string $day, string $hour): ?string
{
    $value = $day . ' ' . $hour;
    $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value);
    if (!$date || $date->format('Y-m-d H:i') !== $value || $date->getTimestamp() <= time()) {
        return 'Choose a future date and time.';
    }
    $opens = $date->format('N') === '5' ? '16:00' : '09:00';
    if ($date->format('i') !== '00' || $hour < $opens || $hour > '20:00') {
        return 'Choose an hourly slot during showroom hours. Friday appointments start at 16:00.';
    }
    try {
        db()->prepare('INSERT INTO test_drives (user_id, car_id, starts_at) VALUES (?, ?, ?)')
            ->execute([$userId, $carId, $date->format('Y-m-d H:i:s')]);
    } catch (PDOException $error) {
        if (($error->errorInfo[1] ?? 0) === 1062) return 'That car or your account already has a booking at this time.';
        throw $error;
    }
    return null;
}

function list_test_drives(?int $userId): array
{
    $sql = 'SELECT d.drive_id, d.starts_at, d.status, c.car_id, c.car_name, u.full_name, u.email
              FROM test_drives d JOIN cars c ON c.car_id = d.car_id JOIN users u ON u.user_id = d.user_id';
    if ($userId !== null) $sql .= ' WHERE d.user_id = ?';
    $stmt = db()->prepare($sql . ' ORDER BY (d.starts_at >= ?) DESC, d.starts_at, d.drive_id');
    $params = $userId === null ? [] : [$userId];
    $params[] = date('Y-m-d H:i:s');
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function update_test_drive(int $id, string $action, ?int $owner): void
{
    if ($action === 'confirm_drive' && $owner === null) {
        $sql = "UPDATE test_drives SET status='confirmed' WHERE drive_id=? AND status='requested' AND starts_at > ?";
    } elseif ($action === 'cancel_drive') {
        $sql = "UPDATE test_drives SET status='cancelled' WHERE drive_id=? AND status IN ('requested','confirmed') AND starts_at > ?";
    } else {
        return;
    }
    $params = [$id, date('Y-m-d H:i:s')];
    if ($owner !== null) {
        $sql .= ' AND user_id=?';
        $params[] = $owner;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    flash($stmt->rowCount() ? 'success' : 'info', $stmt->rowCount() ? 'Appointment updated.' : 'That appointment is no longer available to change.');
}
