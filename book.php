<?php
mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli("localhost", "root", "", "aura_haven");

if ($conn->connect_error) {
    exit("Database connection failed. Please check XAMPP.");
}

$conn->set_charset("utf8mb4");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    exit("Please submit the booking form.");
}

/* Read form values */
$guest_name = trim($_POST["guest_name"] ?? "");
$phone = trim($_POST["phone"] ?? "");
$email = trim($_POST["email"] ?? "");
$check_in = trim($_POST["check_in"] ?? "");
$check_out = trim($_POST["check_out"] ?? "");
$guests = filter_var(
    $_POST["guests"] ?? "",
    FILTER_VALIDATE_INT
);
$room_type = trim($_POST["room_type"] ?? "");
$number_of_rooms = filter_var(
    $_POST["number_of_rooms"] ?? "",
    FILTER_VALIDATE_INT
);

/* Allowed room types must match the HTML form and database */
$allowed_rooms = [
    "Deluxe Room",
    "Poolside Suite",
    "Private Villa"
];

/* Validate dates */
$in_date = DateTimeImmutable::createFromFormat("!Y-m-d", $check_in);
$out_date = DateTimeImmutable::createFromFormat("!Y-m-d", $check_out);
$today = new DateTimeImmutable("today");

if (
    $guest_name === "" ||
    !preg_match('/^[0-9]{10}$/', $phone) ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    !$in_date || !$out_date ||
    $in_date->format("Y-m-d") !== $check_in ||
    $out_date->format("Y-m-d") !== $check_out ||
    $in_date < $today ||
    $out_date <= $in_date ||
    $guests === false || $guests < 1 || $guests > 10 ||
    $number_of_rooms === false ||
    $number_of_rooms < 1 || $number_of_rooms > 3 ||
    !in_array($room_type, $allowed_rooms, true)
) {
    http_response_code(400);
    exit("Invalid booking details. Please check your information.");
}

/* Begin booking transaction */
$conn->begin_transaction();

try {
    /*
     * Lock the inventory row to prevent concurrent bookings
     * from exceeding the available inventory.
     */
    $stmt = $conn->prepare(
        "SELECT total_rooms
         FROM room_inventory
         WHERE room_type = ?
         FOR UPDATE"
    );

    if (!$stmt) {
        throw new Exception("Could not check room inventory.");
    }

    $stmt->bind_param("s", $room_type);
    $stmt->execute();
    $stmt->bind_result($total_rooms);

    if (!$stmt->fetch()) {
        $stmt->close();
        throw new Exception(
            "This room type is missing from the room inventory."
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
        throw new Exception("Could not check existing bookings.");
    }

    $stmt->bind_param(
        "sss",
        $room_type,
        $check_out,
        $check_in
    );

    $stmt->execute();
    $stmt->bind_result($booked_rooms);
    $stmt->fetch();
    $stmt->close();

    $remaining_rooms = (int)$total_rooms - (int)$booked_rooms;

    if ($remaining_rooms < $number_of_rooms) {
        throw new Exception(
            "Sorry! Only " . max(0, $remaining_rooms) .
            " room(s) are available for your selected dates."
        );
    }

    /* Save booking */
    $status = "Confirmed";

    $stmt = $conn->prepare(
        "INSERT INTO bookings
         (guest_name, phone, email, check_in, check_out,
          guests, room_type, status, number_of_rooms)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if (!$stmt) {
        throw new Exception("Could not prepare the booking.");
    }

    $stmt->bind_param(
        "sssssissi",
        $guest_name,
        $phone,
        $email,
        $check_in,
        $check_out,
        $guests,
        $room_type,
        $status,
        $number_of_rooms
    );

    if (!$stmt->execute()) {
        $stmt->close();
        throw new Exception("Could not save the booking.");
    }

    $stmt->close();
$conn->commit();

    echo "<!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Booking Confirmed | Aura Haven</title>
        <link rel='preconnect' href='https://fonts.googleapis.com'>
        <link rel='preconnect' href='https://fonts.gstatic.com' crossorigin>
        <link href='https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,500;0,600;1,400&family=Plus+Jakarta+Sans:wght@300;400;500;600&display=swap' rel='stylesheet'>
        <link rel='stylesheet' href='style.css'>
    </head>
    <body class='confirmation-body'>
        <div class='confirmation-card'>
            <div class='brand-mark'>A</div>
            <div class='brand-name'>Aura Haven</div>
            <div><span class='badge-check'>Reservation Confirmed</span></div>
            <h1>Thank You, <em>" . htmlspecialchars($guest_name, ENT_QUOTES, 'UTF-8') . "</em></h1>
            <p class='subtitle'>Your luxury getaway is set. A reservation record has been generated for your stay.</p>
            
            <div class='receipt-box'>
                <div class='receipt-row'>
                    <span class='label'>Accommodation</span>
                    <span class='value gold'>" . htmlspecialchars($room_type, ENT_QUOTES, 'UTF-8') . "</span>
                </div>
                <div class='receipt-row'>
                    <span class='label'>Check-in Date</span>
                    <span class='value'>" . htmlspecialchars($check_in, ENT_QUOTES, 'UTF-8') . "</span>
                </div>
                <div class='receipt-row'>
                    <span class='label'>Check-out Date</span>
                    <span class='value'>" . htmlspecialchars($check_out, ENT_QUOTES, 'UTF-8') . "</span>
                </div>
                <div class='receipt-row'>
                    <span class='label'>Number of Rooms</span>
                    <span class='value'>" . (int)$number_of_rooms . " Room(s)</span>
                </div>
                <div class='receipt-row'>
                    <span class='label'>Guests</span>
                    <span class='value'>" . (int)$guests . " Guest(s)</span>
                </div>
            </div>

            <a href='index.html' class='btn-home'>Back to Aura Haven</a>
        </div>
    </body>
    </html>";

} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(400);

    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    echo "<p><a href='index.html'>Go back and try again</a></p>";
}

$conn->close();
?>