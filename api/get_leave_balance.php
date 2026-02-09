<?php
header("Content-Type: application/json");
require_once __DIR__ . "/../assets/includes/db_connect.php";

$employee_id = intval($_GET["employee_id"] ?? 0);
$year = intval($_GET["year"] ?? date("Y"));

if ($employee_id <= 0) {
  http_response_code(400);
  echo json_encode(["success" => false, "message" => "employee_id required"]);
  exit;
}

$stmt = $conn->prepare("SELECT employee_id, year, annual_days, sick_days, casual_days
                        FROM leave_balances
                        WHERE employee_id = ? AND year = ?
                        LIMIT 1");
$stmt->bind_param("ii", $employee_id, $year);
$stmt->execute();
$res = $stmt->get_result();

if ($res->num_rows === 0) {
  // return empty default if no record
  echo json_encode([
    "success" => true,
    "data" => [
      "employee_id" => $employee_id,
      "year" => $year,
      "annual_days" => 0,
      "sick_days" => 0,
      "casual_days" => 0
    ]
  ]);
  exit;
}

$row = $res->fetch_assoc();

echo json_encode(["success" => true, "data" => $row]);
