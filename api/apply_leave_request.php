<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
  }

  $data = json_decode(file_get_contents("php://input"), true);
  if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON"]);
    exit;
  }

  $employeeId = trim($data["employeeId"] ?? "");
  $leavePolicyId = (int)($data["leavePolicyId"] ?? 0);
  $startDate = trim($data["startDate"] ?? "");
  $endDate = trim($data["endDate"] ?? "");
  $days = (float)($data["numberOfDays"] ?? 0);
  $reason = trim($data["reason"] ?? "");
  $overseeMemberId = trim($data["overseeMemberId"] ?? "");
  $isSpecial = (int)($data["isSpecialRequest"] ?? 0);
  $address = trim($data["address"] ?? "");

  // NEW: single column half day session
  // "" => normal leave, MORNING/EVENING => half day
  $halfDaySession = strtoupper(trim($data["halfDaySession"] ?? ""));

  // Basic required fields
  if ($employeeId === "" || $leavePolicyId <= 0 || $startDate === "" || $reason === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Missing required fields"]);
    exit;
  }

  // Half day rules (no balance check)
  if ($halfDaySession !== "") {
    if (!in_array($halfDaySession, ["MORNING", "EVENING"], true)) {
      http_response_code(400);
      echo json_encode(["success" => false, "message" => "Invalid half day session"]);
      exit;
    }
    // force same day and half day count
    $endDate = $startDate;
    $days = 0.5;
  } else {
    // normal leave must have endDate and days > 0
    $halfDaySession = null;

    if ($endDate === "" || $days <= 0) {
      http_response_code(400);
      echo json_encode(["success" => false, "message" => "Missing required fields"]);
      exit;
    }
  }

  // If no reliever selected, store NULL
  $overseeMemberIdDb = ($overseeMemberId === "") ? null : $overseeMemberId;

  // Insert leave request (PENDING) with half_day_session
  $sql = "
    INSERT INTO leave_requests
      (employee_id, leave_policy_id, leave_start_date, leave_end_date, number_of_days,
       half_day_session,
       reason, oversee_member_id, is_special_request, address, status, requested_at, updated_at)
    VALUES
      (?, ?, ?, ?, ?,
       ?,
       ?, ?, ?, ?, 'PENDING', NOW(), NOW())
  ";

  $stmt = $conn->prepare($sql);

  // types: s i s s d s s s i s
  $stmt->bind_param(
    "sissdsssis",
    $employeeId,
    $leavePolicyId,
    $startDate,
    $endDate,
    $days,
    $halfDaySession,     // can be NULL
    $reason,
    $overseeMemberIdDb,  // can be NULL
    $isSpecial,
    $address
  );

  $stmt->execute();

  echo json_encode([
    "success" => true,
    "message" => "Leave request submitted",
    "leaveRequestId" => $conn->insert_id
  ]);

  $stmt->close();
  $conn->close();
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "success" => false,
    "message" => "EXCEPTION: " . $e->getMessage()
  ]);
}
