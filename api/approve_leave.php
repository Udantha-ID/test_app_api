<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$leave_request_id = $_POST['leave_request_id'] ?? '';
$manager_id = $_POST['manager_id'] ?? '';

if ($leave_request_id === '' || $manager_id === '') {
  echo json_encode(["success" => false, "message" => "leave_request_id and manager_id required"]);
  exit;
}

try {
  $conn->begin_transaction();

  // 1) Security check + read request details
  $q = "
    SELECT
      lr.leave_request_id,
      lr.employee_id,
      lr.leave_policy_id,
      lr.number_of_days,
      lr.status
    FROM leave_requests lr
    JOIN employee_job ej ON ej.employee_id = lr.employee_id
    WHERE lr.leave_request_id = ?
      AND ej.reporting_manager_id = ?
    FOR UPDATE
  ";
  $stmt = $conn->prepare($q);
  $stmt->bind_param("is", $leave_request_id, $manager_id);
  $stmt->execute();
  $res = $stmt->get_result();

  if ($res->num_rows === 0) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => "Not allowed / not found"]);
    exit;
  }

  $row = $res->fetch_assoc();

  // prevent double approve
  if (in_array($row["status"], ["APPROVED", "REJECTED", "CANCELED"])) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => "Already processed"]);
    exit;
  }

  $empId = $row["employee_id"];
  $policyId = (int)$row["leave_policy_id"];
  $days = (float)$row["number_of_days"]; // ✅ ONLY use number_of_days

  if ($days <= 0) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => "Invalid number_of_days"]);
    exit;
  }

  // 2) Lock employee_leave_balance row and check remaining
  $q2 = "
    SELECT leave_balance_id, remaining
    FROM employee_leave_balances
    WHERE employee_id = ?
      AND leave_policy_id = ?
    FOR UPDATE
  ";
  $stmt2 = $conn->prepare($q2);
  $stmt2->bind_param("si", $empId, $policyId);
  $stmt2->execute();
  $res2 = $stmt2->get_result();

  if ($res2->num_rows === 0) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => "Leave balance row not found for this employee/policy"]);
    exit;
  }

  $bal = $res2->fetch_assoc();
  $remaining = (float)$bal["remaining"];

  if ($remaining < $days) {
    $conn->rollback();
    echo json_encode([
      "success" => false,
      "message" => "Not enough leave balance (remaining: $remaining, needed: $days)"
    ]);
    exit;
  }

  // 3) Update leave balance
  $q3 = "
    UPDATE employee_leave_balances
    SET total_taken = total_taken + ?,
        remaining = remaining - ?,
        updated_at = NOW()
    WHERE employee_id = ?
      AND leave_policy_id = ?
  ";
  $stmt3 = $conn->prepare($q3);
  $stmt3->bind_param("ddsi", $days, $days, $empId, $policyId);
  $stmt3->execute();

  // 4) Approve request
  $q4 = "
    UPDATE leave_requests
    SET status='APPROVED',
        manager_comment=NULL,
        updated_at=NOW()
    WHERE leave_request_id=?
  ";
  $stmt4 = $conn->prepare($q4);
  $stmt4->bind_param("i", $leave_request_id);
  $stmt4->execute();

  $conn->commit();

  echo json_encode(["success" => true, "message" => "Approved + Leave balance updated"]);
} catch (Throwable $e) {
  if ($conn->errno) $conn->rollback();
  http_response_code(500);
  echo json_encode(["success" => false, "message" => $e->getMessage()]);
}
