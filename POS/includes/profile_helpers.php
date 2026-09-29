<?php
// ==============================================================================
// FILE: includes/profile_helpers.php
// User Profile Management & Salary Payment Details (with HR Approval Gate)
// Kofee Manila POS & Enterprise System
// ==============================================================================

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/store_helpers.php';

/**
 * Mask an account number, preserving only the last 4 digits.
 */
function mask_account_number(string $number): string {
    $clean = preg_replace('/\s+/', '', $number);
    $len = strlen($clean);
    if ($len <= 4) return $clean;
    $last4 = substr($clean, -4);
    return '•••• •••• •••• ' . $last4;
}

/**
 * Mask a Philippine mobile number (0917 ••• 1234).
 */
function mask_mobile_number(string $mobile): string {
    $clean = preg_replace('/\D/', '', $mobile);
    if (strlen($clean) === 11 && str_starts_with($clean, '09')) {
        return substr($clean, 0, 4) . ' ••• ' . substr($clean, -4);
    }
    return substr($clean, 0, 3) . ' ••• ' . substr($clean, -3);
}

/**
 * Fetch user profile combined with employee details if linked.
 */
function get_user_profile(PDO $pdo, int $user_id): ?array {
    $stmt = $pdo->prepare("
        SELECT u.id AS user_id, u.username, u.email, u.firstname, u.lastname, u.role, u.status AS user_status,
               u.phone, u.avatar_path, u.last_login, u.created_at AS user_created_at,
               e.id AS employee_id, e.employee_code, e.position, e.department, e.branch, e.address,
               e.contact_number, e.employment_type, e.payment_method, e.bank_name, e.bank_account_last4
        FROM `users` u
        LEFT JOIN `employees` e ON e.user_id = u.id
        WHERE u.id = :uid
    ");
    $stmt->execute([':uid' => $user_id]);
    $res = $stmt->fetch();
    return $res ?: null;
}

/**
 * Update personal profile information and profile photo.
 */
function update_user_profile(PDO $pdo, int $user_id, array $data, ?array $avatar_file = null): array {
    $user = get_user_profile($pdo, $user_id);
    if (!$user) {
        return ['ok' => false, 'error' => 'User not found.'];
    }

    $firstname = trim($data['firstname'] ?? '');
    $lastname  = trim($data['lastname'] ?? '');
    $email     = trim($data['email'] ?? '');
    $phone     = trim($data['phone'] ?? '');
    $address   = trim($data['address'] ?? '');

    if (!$firstname || !$lastname) {
        return ['ok' => false, 'error' => 'First name and last name are required.'];
    }
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Please provide a valid email address.'];
    }

    // Avatar upload handling
    $avatar_path = $user['avatar_path'];
    if ($avatar_file && !empty($avatar_file['tmp_name']) && $avatar_file['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../uploads/avatars';
        if (!is_dir($upload_dir)) {
            @mkdir($upload_dir, 0755, true);
        }

        $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($avatar_file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, $allowed_exts, true)) {
            return ['ok' => false, 'error' => 'Invalid image format. Allowed: JPG, PNG, WEBP.'];
        }
        if ($avatar_file['size'] > 5 * 1024 * 1024) {
            return ['ok' => false, 'error' => 'Profile picture must not exceed 5MB.'];
        }

        $fname = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
        if (move_uploaded_file($avatar_file['tmp_name'], $upload_dir . '/' . $fname)) {
            $avatar_path = 'uploads/avatars/' . $fname;
        }
    }

    try {
        $pdo->beginTransaction();

        $u_stmt = $pdo->prepare("
            UPDATE `users`
            SET `firstname` = :fn, `lastname` = :ln, `email` = :em, `phone` = :ph, `avatar_path` = :av
            WHERE `id` = :uid
        ");
        $u_stmt->execute([
            ':fn'  => $firstname,
            ':ln'  => $lastname,
            ':em'  => $email,
            ':ph'  => $phone,
            ':av'  => $avatar_path,
            ':uid' => $user_id,
        ]);

        if (!empty($user['employee_id'])) {
            $e_stmt = $pdo->prepare("
                UPDATE `employees`
                SET `firstname` = :fn, `lastname` = :ln, `email` = :em, `contact_number` = :ph, `address` = :addr
                WHERE `id` = :eid
            ");
            $e_stmt->execute([
                ':fn'   => $firstname,
                ':ln'   => $lastname,
                ':em'   => $email,
                ':ph'   => $phone,
                ':addr' => $address,
                ':eid'  => $user['employee_id'],
            ]);
        }

        log_operations_activity(
            $pdo,
            module: 'profile',
            action: 'profile_updated',
            record_ref: "USER-$user_id",
            status: 'info',
            user_id: $user_id,
            details: "Updated personal profile information."
        );

        $pdo->commit();
        return ['ok' => true, 'avatar_path' => $avatar_path];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Fetch active salary payment details for an employee.
 */
function get_employee_active_payment_details(PDO $pdo, int $employee_id): ?array {
    $stmt = $pdo->prepare("
        SELECT * FROM `employee_payment_details`
        WHERE `employee_id` = :eid AND `is_active` = 1
        ORDER BY `id` DESC LIMIT 1
    ");
    $stmt->execute([':eid' => $employee_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Fetch any pending payment change request for an employee.
 */
function get_pending_payment_change_request(PDO $pdo, int $employee_id): ?array {
    $stmt = $pdo->prepare("
        SELECT * FROM `employee_payment_change_requests`
        WHERE `employee_id` = :eid AND `status` = 'pending_review'
        ORDER BY `created_at` DESC LIMIT 1
    ");
    $stmt->execute([':eid' => $employee_id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Submit a salary payment details change request (requires employee confirmation).
 */
function submit_payment_change_request(PDO $pdo, int $employee_id, int $user_id, array $data): array {
    $payout_type = in_array($data['payout_type'] ?? '', ['bank', 'ewallet'], true) ? $data['payout_type'] : 'bank';
    $confirmed   = !empty($data['employee_confirmed']);

    if (!$confirmed) {
        return ['ok' => false, 'error' => 'You must check the confirmation box verifying that the payment details belong to you.'];
    }

    $bank_name = null; $bank_code = null; $account_name = null; $account_number = null; $last4 = null;
    $ewallet_provider = null; $ewallet_account_name = null; $ewallet_mobile = null;

    if ($payout_type === 'bank') {
        $bank_name = trim($data['bank_name'] ?? '');
        $bank_code = trim($data['bank_code'] ?? '');
        $account_name = trim($data['account_name'] ?? '');
        $account_number = preg_replace('/\s+/', '', $data['account_number'] ?? '');

        if (!$bank_name) return ['ok' => false, 'error' => 'Please select your bank.'];
        if (!$account_name) return ['ok' => false, 'error' => 'Please enter the bank account holder name.'];
        if (strlen($account_number) < 8 || strlen($account_number) > 20) {
            return ['ok' => false, 'error' => 'Please enter a valid bank account number (8–20 digits).'];
        }
        $last4 = substr($account_number, -4);
    } else {
        $ewallet_provider = strtolower(trim($data['ewallet_provider'] ?? ''));
        $ewallet_account_name = trim($data['ewallet_account_name'] ?? '');
        $ewallet_mobile = preg_replace('/\D/', '', $data['ewallet_mobile_number'] ?? '');

        if (!in_array($ewallet_provider, ['gcash', 'paymaya', 'grabpay'], true)) {
            return ['ok' => false, 'error' => 'Please select a valid e-wallet provider (GCash, Maya, GrabPay).'];
        }
        if (!$ewallet_account_name) {
            return ['ok' => false, 'error' => 'Please enter the registered e-wallet account name.'];
        }
        if (strlen($ewallet_mobile) !== 11 || !str_starts_with($ewallet_mobile, '09')) {
            return ['ok' => false, 'error' => 'Please enter a valid 11-digit Philippine mobile number (09XXXXXXXXX).'];
        }
        $last4 = substr($ewallet_mobile, -4);
    }

    try {
        // Cancel any prior pending requests
        $pdo->prepare("
            UPDATE `employee_payment_change_requests`
            SET `status` = 'rejected', `review_notes` = 'Superseded by newer request'
            WHERE `employee_id` = :eid AND `status` = 'pending_review'
        ")->execute([':eid' => $employee_id]);

        $ins = $pdo->prepare("
            INSERT INTO `employee_payment_change_requests`
                (`employee_id`, `user_id`, `payout_type`, `bank_code`, `bank_name`, `account_name`,
                 `account_number_encrypted`, `account_number_last4`, `ewallet_provider`, `ewallet_account_name`,
                 `ewallet_mobile_number`, `employee_confirmed`, `status`, `created_at`)
            VALUES
                (:eid, :uid, :pt, :bc, :bn, :an, :enc, :l4, :ep, :ean, :em, 1, 'pending_review', NOW())
        ");
        $ins->execute([
            ':eid' => $employee_id,
            ':uid' => $user_id,
            ':pt'  => $payout_type,
            ':bc'  => $bank_code,
            ':bn'  => $bank_name,
            ':an'  => $account_name,
            ':enc' => $account_number ? base64_encode($account_number) : null,
            ':l4'  => $last4,
            ':ep'  => $ewallet_provider,
            ':ean' => $ewallet_account_name,
            ':em'  => $ewallet_mobile,
        ]);
        $req_id = (int)$pdo->lastInsertId();

        // Audit log with strictly masked numbers
        $masked_desc = $payout_type === 'bank'
            ? "$bank_name (•••• $last4) - $account_name"
            : strtoupper($ewallet_provider) . " (" . mask_mobile_number($ewallet_mobile) . ") - $ewallet_account_name";

        log_operations_activity(
            $pdo,
            module: 'payroll',
            action: 'payment_details_change_requested',
            record_ref: "REQ-$req_id",
            status: 'pending',
            user_id: $user_id,
            details: "Employee #{$employee_id} submitted new salary payout destination: $masked_desc. Awaiting HR review."
        );

        return ['ok' => true, 'request_id' => $req_id, 'masked' => $masked_desc];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * HR / Payroll review and approval of salary payment destination changes.
 */
function review_payment_change_request(PDO $pdo, int $request_id, int $reviewer_id, string $decision, string $notes = ''): array {
    $stmt = $pdo->prepare("SELECT * FROM `employee_payment_change_requests` WHERE `id` = :id");
    $stmt->execute([':id' => $request_id]);
    $req = $stmt->fetch();

    if (!$req) {
        return ['ok' => false, 'error' => 'Payment change request not found.'];
    }
    if ($req['status'] !== 'pending_review') {
        return ['ok' => false, 'error' => "Request is already {$req['status']}."];
    }

    $is_approved = ($decision === 'approved');

    try {
        $pdo->beginTransaction();

        $pdo->prepare("
            UPDATE `employee_payment_change_requests`
            SET `status` = :st, `reviewed_by` = :uid, `reviewed_at` = NOW(), `review_notes` = :notes
            WHERE `id` = :id
        ")->execute([
            ':st'    => $is_approved ? 'approved' : 'rejected',
            ':uid'   => $reviewer_id,
            ':notes' => $notes ?: null,
            ':id'    => $request_id,
        ]);

        if ($is_approved) {
            // Deactivate previous active details
            $pdo->prepare("UPDATE `employee_payment_details` SET `is_active` = 0 WHERE `employee_id` = :eid")
                ->execute([':eid' => $req['employee_id']]);

            // Insert new active payment details
            $pdo->prepare("
                INSERT INTO `employee_payment_details`
                    (`employee_id`, `payout_type`, `bank_code`, `bank_name`, `account_name`,
                     `account_number_encrypted`, `account_number_last4`, `ewallet_provider`,
                     `ewallet_account_name`, `ewallet_mobile_number`, `is_active`, `verified_by_employee`, `created_at`)
                VALUES
                    (:eid, :pt, :bc, :bn, :an, :enc, :l4, :ep, :ean, :em, 1, 1, NOW())
            ")->execute([
                ':eid' => $req['employee_id'],
                ':pt'  => $req['payout_type'],
                ':bc'  => $req['bank_code'],
                ':bn'  => $req['bank_name'],
                ':an'  => $req['account_name'],
                ':enc' => $req['account_number_encrypted'],
                ':l4'  => $req['account_number_last4'],
                ':ep'  => $req['ewallet_provider'],
                ':ean' => $req['ewallet_account_name'],
                ':em'  => $req['ewallet_mobile_number'],
            ]);

            // Sync employees table summary columns
            $pdo->prepare("
                UPDATE `employees`
                SET `payment_method` = :pm, `bank_name` = :bn, `bank_account_last4` = :l4
                WHERE `id` = :eid
            ")->execute([
                ':pm' => $req['payout_type'] === 'ewallet' ? 'ewallet' : 'bank_transfer',
                ':bn' => $req['payout_type'] === 'ewallet' ? strtoupper($req['ewallet_provider']) : $req['bank_name'],
                ':l4' => $req['account_number_last4'],
                ':eid'=> $req['employee_id'],
            ]);
        }

        log_operations_activity(
            $pdo,
            module: 'payroll',
            action: $is_approved ? 'payment_details_approved' : 'payment_details_rejected',
            record_ref: "REQ-$request_id",
            status: $is_approved ? 'success' : 'warning',
            user_id: $reviewer_id,
            details: "HR " . ($is_approved ? 'approved' : 'rejected') . " salary payout destination change for Employee #{$req['employee_id']}." . ($notes ? " Notes: $notes" : '')
        );

        $pdo->commit();
        return ['ok' => true, 'status' => $is_approved ? 'approved' : 'rejected'];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
