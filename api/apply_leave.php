<?php
        header("Content-Type: application/json");
        require_once __DIR__ . "/../assets/includes/db_connect.php";


        $data = json_decode(file_get_contents("php://input"), true);

        $employee_id = $data["employee_id"];
        $leave_type_id = $data["leave_type_id"];
        $date_from = $data["date_from"];
        $date_to = $data["date_to"];
        $total_days = $data["total_days"];
        $reason = $data["reason"];
        $address = $data["address_while_on_leave"];
        $reliever = $data["reliever_employee_id"];
        $without_reliever = $data["proceed_without_reliever"];
        $attachment_name = $data["attachment_name"];
        $attachment_url = $data["attachment_url"];

        $status = "Pending";
        $hod_required = 1;
        $hr_required = 0;

        $sql = "INSERT INTO leave_requests
        (employee_id, leave_type_id, date_from, date_to, total_days, reason, address_while_on_leave, reliever_employee_id, proceed_without_reliever, attachment_name, attachment_url, status, hod_approval_required,
        hr_approval_required, created_at, updated_at ) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())";

        $stmt = $conn->prepare($sql);

        $stmt->bind_param(
        "iissississssii",
        $employee_id,
        $leave_type_id,
        $date_from,
        $date_to,
        $total_days,
        $reason,
        $address,
        $reliever,
        $without_reliever,
        $attachment_name,
        $attachment_url,
        $status,
        $hod_required,
        $hr_required
        );

        if ($stmt->execute()) {
            echo json_encode(["success" => true]);
        } else {
            echo json_encode(["success" => false]);
}
