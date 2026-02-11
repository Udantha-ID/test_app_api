<?php
header("Content-Type: application/json; charset=UTF-8");
require_once __DIR__ . "/../assets/includes/db_connect.php";

ini_set("display_errors", 0);
error_reporting(E_ALL);
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

  $email = $data["email"] ?? "";
  $newPassword = trim($data["newPassword"] ?? "");

  if ($email === "" || $newPassword === "") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "email and newPassword required"]);
    exit;
  }

  if ($newPassword === "Test@123") {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Cannot use default password"]);
    exit;
  }

  // Hash password (IMPORTANT)
  $hash = password_hash($newPassword, PASSWORD_BCRYPT);

  // Update by email (change table/column names if needed)
  $sql = "UPDATE users SET password = ?, updated_at = NOW() WHERE email = ?";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param("ss", $hash, $email);
  $stmt->execute();

  if ($stmt->affected_rows === 0) {
    echo json_encode(["success" => false, "message" => "User not found or password unchanged"]);
    exit;
  }

  echo json_encode(["success" => true, "message" => "Password updated successfully"]);
  $stmt->close();
  $conn->close();
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    "success" => false,
    "message" => "EXCEPTION: " . $e->getMessage(),
  ]);
}
