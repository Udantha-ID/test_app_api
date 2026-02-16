<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);

/**
 * Return JSON on fatal errors (prevents empty response)
 */
register_shutdown_function(function () {
  $err = error_get_last();
  if ($err && in_array($err["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    http_response_code(500);
    echo json_encode([
      "success" => false,
      "message" => "FATAL: " . $err["message"],
      "file" => basename($err["file"]),
      "line" => $err["line"],
    ]);
  }
});

/**
 * Convert warnings/notices to JSON
 */
set_error_handler(function ($severity, $message, $file, $line) {
  http_response_code(500);
  echo json_encode([
    "success" => false,
    "message" => "PHP: $message",
    "file" => basename($file),
    "line" => $line,
  ]);
  exit;
});

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
  if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
  }

  $raw = file_get_contents("php://input");
  $data = json_decode($raw, true);

  if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Invalid JSON body"]);
    exit;
  }

  $email = trim($data["email"] ?? "");
  $password = trim($data["password"] ?? "");

  if ($email === "" || $password === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Email and password required"]);
    exit;
  }

  /**
   * NOTE:
   * Your users table column seems to be: u.password (as you used)
   * If your real column name is user_password, change SELECT u.password AS user_password accordingly.
   */
  $sql = "
    SELECT
      u.id AS user_id,
      u.email,
      u.password AS user_password,
      u.employee_id,

      e.employee_code,
      e.first_name,
      e.middle_name,
      e.last_name,
      e.employment_status,
      e.date_of_birth,

      ej.department_id,
      d.name AS department_name,

      ej.job_title_id,
      ej.employment_type,
      ej.employment_level,
      ej.date_of_joining,
      ej.probation_end_date,
      ej.reporting_manager_id,
      ej.work_location_id,

      ec.contact_value AS primary_contact,
      ec.contact_type AS primary_contact_type,

      -- manager details (self join)
      m.employee_code AS manager_code,
      m.first_name AS manager_first_name,
      m.middle_name AS manager_middle_name,
      m.last_name AS manager_last_name

    FROM users u
    JOIN employees e ON e.employee_id = u.employee_id
    LEFT JOIN employee_job ej ON ej.employee_id = e.employee_id
    LEFT JOIN departments d ON d.department_id = ej.department_id
    LEFT JOIN employee_contacts ec
      ON ec.employee_id = e.employee_id AND ec.is_primary = 1
    LEFT JOIN employees m ON m.employee_id = ej.reporting_manager_id

    WHERE u.email = ?
    LIMIT 1
  ";

  $stmt = $conn->prepare($sql);
  $stmt->bind_param("s", $email);
  $stmt->execute();
  $res = $stmt->get_result();

  if ($res->num_rows === 0) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid email or password"]);
    exit;
  }

  $row = $res->fetch_assoc();

  // Optional: block inactive employees
  if (($row["employment_status"] ?? "") !== "ACTIVE") {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Employee is not active"]);
    exit;
  }

  /**
   *   PASSWORD CHECK (HASHED)
   * - If your DB already has hashed passwords -> password_verify only
   * - If you still have plain text passwords in DB -> this block supports BOTH (temporary)
   *
   * RECOMMENDED:
   * 1) Keep this "plain OR hash" for a short time
   * 2) After all users reset password, remove the plain check
   */
  $stored = (string)($row["user_password"] ?? "");
  $ok = false;

  if ($stored !== "") {
    // hashed check
    if (password_verify($password, $stored)) {
      $ok = true;
    } else {
      // temporary fallback for old plain-text rows
      if (hash_equals($stored, $password)) {
        $ok = true;

        // OPTIONAL: auto-upgrade plain password to hashed on successful login
        // Uncomment if you want to migrate automatically
        /*
        $newHash = password_hash($password, PASSWORD_BCRYPT);
        $u2 = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
        $u2->bind_param("si", $newHash, $row["user_id"]);
        $u2->execute();
        $u2->close();
        */
      }
    }
  }

  if (!$ok) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Invalid email or password"]);
    exit;
  }

  $managerName = trim(
    ($row["manager_first_name"] ?? "") . " " .
    ($row["manager_middle_name"] ?? "") . " " .
    ($row["manager_last_name"] ?? "")
  );

  $fullName = trim(
    ($row["first_name"] ?? "") . " " .
    ($row["last_name"] ?? "")
  );

  echo json_encode([
    "success" => true,
    "message" => "Login success",
    "user" => [
      "userId" => (int)$row["user_id"],
      "employeeId" => $row["employee_id"],
      "email" => $row["email"],

      "employeeCode" => $row["employee_code"],
      "name" => $fullName,
      "lastName" => $row["last_name"] ?? "",
      "firstName" => $row["first_name"] ?? "",
      "middleName" => $row["middle_name"] ?? "",
      "dateOfBirth" => $row["date_of_birth"] ?? "",

      "departmentId" => $row["department_id"],
      "department" => $row["department_name"] ?? "",

      "jobTitleId" => $row["job_title_id"],
      "employmentType" => $row["employment_type"],
      "employmentLevel" => $row["employment_level"],
      "dateOfJoining" => $row["date_of_joining"],
      "probationEndDate" => $row["probation_end_date"],
      "reportingManagerId" => $row["reporting_manager_id"],
      "reportingManagerName" => $managerName,
      "workLocationId" => $row["work_location_id"],

      "primaryContact" => $row["primary_contact"] ?? ""
    ]
  ]);

  $stmt->close();
  $conn->close();
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "success" => false,
    "message" => "EXCEPTION: " . $e->getMessage(),
    "file" => basename($e->getFile()),
    "line" => $e->getLine(),
  ]);
}
