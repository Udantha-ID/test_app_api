<?php
ob_start();
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    ob_clean();
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
  }

  $data = json_decode(file_get_contents("php://input"), true);
  if (!is_array($data)) {
    http_response_code(400);
    ob_clean();
    echo json_encode(["success" => false, "message" => "Invalid JSON"]);
    exit;
  }

  $employeeId = trim($data["employeeId"] ?? "");
  $departmentId = trim($data["departmentId"] ?? "");
  $fromDate = trim($data["fromDate"] ?? "");
  $toDate = trim($data["toDate"] ?? "");

  if ($employeeId === "" || $departmentId === "" || $fromDate === "" || $toDate === "") {
    http_response_code(400);
    ob_clean();
    echo json_encode(["success" => false, "message" => "employeeId, departmentId, fromDate, toDate required"]);
    exit;
  }

  // same department employees except self, ACTIVE only, and NOT on approved leave overlapping the date range
  $sql = "
    SELECT
      e.employee_id,
      CONCAT_WS(' ', e.first_name, e.middle_name, e.last_name) AS name
    FROM employees e
    JOIN employee_job ej ON ej.employee_id = e.employee_id
    WHERE ej.department_id = ?
      AND e.employment_status = 'ACTIVE'
      AND e.employee_id <> ?
      AND NOT EXISTS (
        SELECT 1
        FROM leave_requests lr
        WHERE lr.employee_id = e.employee_id
          AND lr.status = 'APPROVED'
          AND NOT (lr.leave_end_date < ? OR lr.leave_start_date > ?)
      )
    ORDER BY name
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ssss", $departmentId, $employeeId, $fromDate, $toDate);
  $stmt->execute();
  $res = $stmt->get_result();

  $members = [];
  while ($row = $res->fetch_assoc()) {
    $members[] = [
      "id" => $row["employee_id"],
      "name" => $row["name"],
    ];
  }

  ob_clean();
  echo json_encode([
    "success" => true,
    "message" => "Relievers loaded",
    "members" => $members
  ]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  ob_clean();
  echo json_encode(["success" => false, "message" => $e->getMessage()]);
  exit;
}
