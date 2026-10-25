<?php
header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_OFF);

function sendResponse($available, $message, $remaining = 0) {
    echo json_encode([
        "available" => $available,
        "message" => $message,
        "remaining_rooms" => $remaining
    ]);
    exit;
}

$conn = new mysqli("localhost", "root", "", "aura_haven");

if ($conn->connect_error) {
    http_response_code(500);
    sendResponse(false, "Database connection failed.");
}

$conn->set_charset("utf8mb4");

$check_in = trim($_POST['check_in'] ?? '');
$check_out = trim($_POST['check_out'] ?? '');

$guests = filter_var(
    $_POST['guests'] ?? 1,
    FILTER_VALIDATE_INT
);

$room_type = trim($_POST['room_type'] ?? '');
$requested_rooms = filter_var(
    $_GET['number_of_rooms'] ?? '1',
    FILTER_VALIDATE_INT
);

$allowed_rooms = [
    'Deluxe Room',
    'Poolside Suite',
    'Private Villa'
];

$in_date = DateTimeImmutable::createFromFormat('!Y-m-d', $check_in);
$out_date = DateTimeImmutable::createFromFormat('!Y-m-d', $check_out);

if (
    !$in_date ||
    !$out_date ||
    $in_date->format('Y-m-d') !== $check_in ||
    $out_date->format('Y-m-d') !== $check_out ||
    $in_date < new DateTimeImmutable('today') ||
    $out_date <= $in_date ||
    $guests === false || $guests < 1 || $guests > 10 ||
    $requested_rooms === false ||
    $requested_rooms < 1 || $requested_rooms > 3 ||
    !in_array($room_type, $allowed_rooms, true)
) {
    http_response_code(400);
    sendResponse(false, "Please enter valid booking details.");
}

/* Get total rooms for the selected room type */
$stmt = $conn->prepare(
    "SELECT total_rooms FROM room_inventory WHERE room_type = ?"
);

if (!$stmt) {
    http_response_code(500);
    sendResponse(false, "Could not check room inventory.");
}

$stmt->bind_param("s", $room_type);
$stmt->execute();
$stmt->bind_result($total_rooms);

if (!$stmt->fetch()) {
    $stmt->close();
    sendResponse(
        false,
        "Room type not found in room_inventory. Please add it first."
    );
}

$stmt->close();

/* Count rooms already booked for overlapping dates */
$stmt = $conn->prepare(
    "SELECT COALESCE(SUM(number_of_rooms), 0)
     FROM bookings
     WHERE room_type = ?
       AND status = 'Confirmed'
       AND check_in < ?
       AND check_out > ?"
);

if (!$stmt) {
    http_response_code(500);
    sendResponse(false, "Could not check existing bookings.");
}

$stmt->bind_param("sss", $room_type, $check_out, $check_in);
$stmt->execute();
$stmt->bind_result($booked_rooms);
$stmt->fetch();
$stmt->close();

$remaining_rooms = max(0, (int)$total_rooms - (int)$booked_rooms);

if ($remaining_rooms < $requested_rooms) {
    sendResponse(
        false,
        "Sorry! Only $remaining_rooms room(s) available for your selected dates.",
        $remaining_rooms
    );
}

sendResponse(
    true,
    "Great! $remaining_rooms room(s) are available.",
    $remaining_rooms
);
?>