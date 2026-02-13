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

  if ($employeeId === "" || $leavePolicyId <= 0 || $startDate === "" || $endDate === "" || $days <= 0 || $reason === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Missing required fields"]);
    exit;
  }

  // If no reliever selected, store NULL
  $overseeMemberIdDb = ($overseeMemberId === "") ? null : $overseeMemberId;

  // Optional: validate remaining balance before allowing request
  // (If you want to allow request even if not enough, remove this block)
  $checkSql = "SELECT remaining FROM employee_leave_balances WHERE employee_id=? AND leave_policy_id=? LIMIT 1";
  $checkStmt = $conn->prepare($checkSql);
  $checkStmt->bind_param("si", $employeeId, $leavePolicyId);
  $checkStmt->execute();
  $checkRes = $checkStmt->get_result();
  $row = $checkRes->fetch_assoc();
  $remaining = $row ? (float)$row["remaining"] : 0.0;

  if ($remaining < $days) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Not enough leave balance"]);
    exit;
  }

  // Insert leave request (PENDING)
  $sql = "
    INSERT INTO leave_requests
      (employee_id, leave_policy_id, leave_start_date, leave_end_date, number_of_days,
       reason, oversee_member_id, is_special_request, address, status, requested_at, updated_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDING', NOW(), NOW())
  ";

  $stmt = $conn->prepare($sql);
  // s i s s d s s i s
  $stmt->bind_param(
    "sissdssis",
    $employeeId,
    $leavePolicyId,
    $startDate,
    $endDate,
    $days,
    $reason,
    $overseeMemberIdDb,
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
