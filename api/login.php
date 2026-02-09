<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

// Convert PHP warnings/notices into JSON (prevents <br/> breaking jsonDecode)
set_error_handler(function ($severity, $message, $file, $line) {
  http_response_code(500);
  echo json_encode([
    "success" => false,
    "message" => "PHP Error: $message",
    "file" => basename($file),
    "line" => $line
  ]);
  exit;
});

// Only POST
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
  http_response_code(405);
  echo json_encode(["success" => false, "message" => "Method not allowed"]);
  exit;
}

// Read JSON body
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!is_array($data)) {
  http_response_code(400);
  echo json_encode(["success" => false, "message" => "Invalid JSON body"]);
  exit;
}

$username = trim($data["username"] ?? "");
$password = trim($data["password"] ?? "");

if ($username === "" || $password === "") {
  http_response_code(400);
  echo json_encode(["success" => false, "message" => "Username and password required"]);
  exit;
}

// Query
$stmt = $conn->prepare("
  SELECT
    e.id,
    e.username,
    e.password,
    e.employee_no,
    e.full_name,
    e.contact_no,
    e.is_active,
    d.name AS department
  FROM employees e
  JOIN departments d ON d.id = e.department_id
  WHERE e.username = ?
  LIMIT 1
");

if (!$stmt) {
  http_response_code(500);
  echo json_encode(["success" => false, "message" => "Server error: prepare failed"]);
  exit;
}

$stmt->bind_param("s", $username);
$stmt->execute();
$res = $stmt->get_result();

if (!$res || $res->num_rows === 0) {
  http_response_code(401);
  echo json_encode(["success" => false, "message" => "Invalid username or password"]);
  $stmt->close();
  $conn->close();
  exit;
}

$row = $res->fetch_assoc();

// Block disabled users (optional)
if ((int)$row["is_active"] !== 1) {
  http_response_code(403);
  echo json_encode(["success" => false, "message" => "Account is disabled"]);
  $stmt->close();
  $conn->close();
  exit;
}

// Plain compare
if ($password !== $row["password"]) {
  http_response_code(401);
  echo json_encode(["success" => false, "message" => "Invalid username or password"]);
  $stmt->close();
  $conn->close();
  exit;
}

// Success (NEVER return password)
http_response_code(200);
echo json_encode([
  "success" => true,
  "message" => "Login success",
  "user" => [
    "id" => (int)$row["id"],
    "username" => $row["username"],
    "employeeNo" => $row["employee_no"],
    "name" => $row["full_name"],
    "department" => $row["department"],
    "contact" => $row["contact_no"]
  ]
]);

$stmt->close();
$conn->close();
exit;
