# Kofee Manila POS & Enterprise System — Comprehensive Security Architecture

> **Document Type:** System Security Architecture & Source Code Audit  
> **Target Application:** Kofee Manila POS & Management System  
> **Environment:** PHP 8+ / Apache / MySQL (XAMPP / Production LAMP/LEMP)  
> **Last Updated:** October 2026  

> **Implementation status:** This document describes the intended architecture, not proof of a completed deployment. See [the implementation and rollout guide](SECURITY_IMPLEMENTATION.md) for verified changes and remaining configuration steps.

---

## Table of Contents
1. [Executive Summary & Security Philosophy](#1-executive-summary--security-philosophy)
2. [Network, Server & HTTP Infrastructure Hardening](#2-network-server--http-infrastructure-hardening)
3. [Session Hardening & Lifecycle Security](#3-session-hardening--lifecycle-security)
4. [Authentication & Credential Protection](#4-authentication--credential-protection)
5. [Multi-Factor Device Trust & Geolocation Workplace Authorization](#5-multi-factor-device-trust--geolocation-workplace-authorization)
6. [Cross-Site Request Forgery (CSRF) Architecture](#6-cross-site-request-forgery-csrf-architecture)
7. [Role-Based Access Control (RBAC) & Privilege Separation](#7-role-based-access-control-rbac--privilege-separation)
8. [Cryptography & Data Protection at Rest](#8-cryptography--data-protection-at-rest)
9. [Database Security & Concurrency Integrity](#9-database-security--concurrency-integrity)
10. [Secure File Storage & Upload Defenses](#10-secure-file-storage--upload-defenses)
11. [Financial, Payment Gateway & Order Security](#11-financial-payment-gateway--order-security)
12. [Public Endpoints & Anti-Abuse Defenses](#12-public-endpoints--anti-abuse-defenses)
13. [Security Audit Logging & Test Verification](#13-security-audit-logging--test-verification)
14. [Complete Security Feature Matrix](#14-complete-security-feature-matrix)

---

## 1. Executive Summary & Security Philosophy

The **Kofee Manila POS & Enterprise System** implements an enterprise-grade, defense-in-depth security model. The architecture is built around three core principles:

1. **Zero Client Trust:** All authorization, financial calculations, ingredient stock usage, file validation, and state machines are enforced authoritatively on the server. The client is treated as an untrusted rendering layer.
2. **Fail-Closed Design:** In the event of schema mismatches, network partitions, unverified signatures, or missing cryptographic keys, requests fail safely with sanitized error responses rather than permitting unverified access.
3. **Defense-in-Depth:** Security controls are layered across Apache server configurations, PHP runtime boundaries, database transactions with row-level locks, and client-side transparent interceptors.

---

## 2. Network, Server & HTTP Infrastructure Hardening

### 2.1 Apache Server Hardening (`.htaccess`)
- **Directory Browsing Disabled:** `Options -Indexes` is enforced across root [`.htaccess`](../.htaccess), [`uploads/.htaccess`](../uploads/.htaccess), and [`cache/.htaccess`](../cache/.htaccess).
- **Sensitive Metadata & Dependency Cloaking:** Web access to `.git`, `node_modules`, `package.json`, `package-lock.json`, and `vite.config.js` is intercepted and returns `404 Not Found` via `RedirectMatch 404`.
- **Sensitive Extension Blocklist:** Global `Require all denied` on:
  ```apache
  <FilesMatch "(?i)\.(sql|md|log|lock|example|zip|gz|tar|7z|phar|env|ini|bak|backup)$">
    Require all denied
  </FilesMatch>
  ```
- **Internal Subdirectory Lockdown:**
  - `includes/.htaccess`: `Require all denied` blocks browser execution or download of backend helpers, config templates, and mailers.
  - `database/.htaccess`: `Require all denied` blocks access to migration scripts and SQL schema files.
  - `cache/.htaccess`: `Require all denied` prevents direct reading of cached filesystem records.
  - `uploads/.htaccess`: `Require all denied` blocks direct web reads of all private documents (resumes, attendance selfies, business permits, invoices). All files must pass through the authorized reader.
  - Web redirects return `404` for `/scratch/`, `/tests/`, `/tools/`, `/vendor/`, `/dist/`, and test databases (`.test-*`).
  - Explicit denial for diagnostic endpoints like `hash.php`.

### 2.2 Security Response Headers
Configured at both the Apache level and via `send_security_headers()` in [`includes/security.php`](../includes/security.php):
- `X-Content-Type-Options: nosniff`: Enforces strict MIME adherence; blocks MIME sniffing attacks.
- `X-Frame-Options: SAMEORIGIN`: Prevents UI redressing and clickjacking within external frames.
- `Referrer-Policy: strict-origin-when-cross-origin`: Minimizes referrer leakage on outbound links.
- `Permissions-Policy: geolocation=(self), microphone=(), camera=(self)`: Restricts browser hardware access, disabling microphone and locking camera/geolocation to same-origin.
- `Strict-Transport-Security: max-age=31536000; includeSubDomains`: Enforces HTTPS transport in production.
- `Header always unset X-Powered-By` & `header_remove('X-Powered-By')`: Strips server fingerprinting headers.
- `Cache-Control: private, no-cache, no-store, must-revalidate`: Enforced on all dynamic PHP pages to prevent caching of authenticated screens.

### 2.3 Host Header Protection & Request Tracing
- **Host Header Poisoning Defense:** `app_url()` in [`includes/runtime.php`](../includes/runtime.php) validates the configured canonical URL independently of the request Host header. It rejects credentials, query strings, and fragments; explicitly configured ports are preserved.
- **Trusted Reverse Proxy Verification:** `app_https()` in [`includes/runtime.php`](../includes/runtime.php) checks `HTTP_X_FORWARDED_PROTO` only when `REMOTE_ADDR` matches an explicit `TRUSTED_PROXIES` allowlist.
- **Request Tracing:** `request_id()` in [`includes/runtime.php`](../includes/runtime.php) mints a 24-character random hex token (`random_bytes(12)`) sent via the `X-Request-ID` header and logged alongside every security audit record.

### 2.4 Information Leakage Prevention & Exception Masking
- In non-CLI environments, `ini_set('display_errors', '0')` is forced at startup.
- `safe_exception()` in [`includes/runtime.php`](../includes/runtime.php) acts as the global uncaught exception boundary (`set_exception_handler`). It logs the exception class, safe error code, and request ID, without raw exception messages or stack traces, and returns sanitized error payloads to clients.

---

## 3. Session Hardening & Lifecycle Security

Implemented in [`includes/security.php`](../includes/security.php) and [`includes/account_security.php`](../includes/account_security.php):

### 3.1 Hardened Session Cookies
`secure_session_start()` enforces strict cookie parameters:
```php
session_set_cookie_params([
    'lifetime' => 0,          // Deleted when browser closes
    'path'     => '/',
    'domain'   => '',
    'secure'   => $https,     // TLS only in production
    'httponly' => true,       // Inaccessible to JavaScript (XSS defense)
    'samesite' => 'Lax',      // Mitigates cross-site request forgery
]);
ini_set('session.use_strict_mode', '1');   // Rejects uninitialized attacker IDs
ini_set('session.use_only_cookies', '1');  // Blocks session passing via GET/URLs
```

### 3.2 Dynamic Regeneration & Dual-Tier Expiry
- **Periodic ID Rotation:** Rotates the session ID (`session_regenerate_id(true)`) every 1,800 seconds (30 minutes) to mitigate session hijacking risks.
- **Idle Timeout:** Invalidates sessions idle for >30 minutes (`$_SESSION['last_activity']`).
- **Absolute Session Lifetime:** Hard cutoff at 12 hours (`$_SESSION['authenticated_at']`), requiring fresh authentication regardless of activity.

### 3.3 Server-Side Stateful Session Registry (`auth_sessions`)
- Authenticated sessions register in the `auth_sessions` table with a SHA-256 hash of a 32-byte random handle (`$_SESSION['auth_handle']`).
- On every request, `validate_auth_session()` joins `auth_sessions` with `users`. If `revoked_at IS NOT NULL` or `users.status <> 'active'`, the session is instantly terminated.
- `revoke_user_sessions()` performs immediate revocation across all devices whenever an account is blocked, a password is changed, or an administrator revokes access.

---

## 4. Authentication & Credential Protection

### 4.1 Password Storage & Validation Policy
- **Bcrypt Hashing (Cost 12):** Newly issued passwords are hashed using `password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])`, including generated supplier passwords. Hashing is one-way; legacy hashes retain their original work factor until credentials are changed.
- **Truncation & Null-Byte Defense:** `new_password_error()` in [`includes/runtime.php`](../includes/runtime.php) enforces:
  - Minimum 15 unicode characters.
  - Maximum 72 UTF-8 bytes (mitigating Bcrypt's native 72-byte truncation boundary).
  - Explicitly rejects null bytes (`\0`).

### 4.2 Timing Attack Defense on Login
In [`auth/login_process.php`](../auth/login_process.php):
```php
if (!verify_login_password($password, $user['password'] ?? null) || !$user) {
    record_login_attempt($username, false);
    redirect_error('Incorrect username or password.', $username);
}
```
If a username does not exist, `password_verify()` runs against a valid precomputed cost-12 dummy hash. This removes the immediate missing-account shortcut and matches newly issued credentials' bcrypt work factor. It does not guarantee identical total response times, particularly for legacy hashes with different costs. Login and re-authentication reject null bytes and passwords exceeding 72 bytes.

### 4.3 Database-Backed Login Throttle
In [`includes/security.php`](../includes/security.php):
- Persisted in the `auth_throttle` table (immune to cookie clearing).
- **Account Lockout:** 6 failed attempts for a username within 15 minutes triggers a 10-minute lockout.
- **IP Brute-Force Guard:** 24 failed attempts from a single IP triggers an IP lockout, neutralizing distributed dictionary attacks across multiple user accounts.
- Returns `429 Too Many Requests` with a calculated `Retry-After` header.
- Opportunistic cleanup (`random_int(1, 50) === 1`) purges records older than 24 hours.

### 4.4 Step-Up Re-Authentication ("Sudo Mode")
- `require_recent_password()` in [`includes/account_security.php`](../includes/account_security.php) enforces that password verification must have occurred within the last 300 seconds (5 minutes) before executing high-privilege actions:
  - User management (`manage_users.php`).
  - Permissions matrix updates (`manage_permissions.php`).
  - Payroll releases and batch disbursements (`payroll.php`).
  - Employee bank/payout account changes (`profile.php`).
  - SMTP credential modifications (`mailer.php`).
- Client-side transparent modal in [`js/security.js`](../js/security.js) intercepts HTTP `428 Precondition Required`, presents a `<dialog>` prompt, re-authenticates via [`api/reauthenticate.php`](../api/reauthenticate.php), and replays the original request seamlessly.
- Re-authentication rate-limiting: max 5 attempts per 15 minutes per user, 20 per 15 minutes per IP.

### 4.5 Single-Use Hashed Password Resets
In [`auth/forgot_password.php`](../auth/forgot_password.php) and [`auth/reset_password.php`](../auth/reset_password.php):
- Raw token: 64-character hex string (256-bit entropy via `random_bytes(32)`).
- Stored as `SHA-256(token)` in `password_resets`; raw tokens are never saved.
- Strict 30-minute validity.
- Consumed under MySQL `FOR UPDATE` row lock (`consume_password_reset()`).
- Single-use only: marks `used_at = NOW()`, invalidates all pending reset tokens, and immediately revokes all active sessions for that user.
- Anti-enumeration: returns identical success messages regardless of whether the account exists.

---

## 5. Multi-Factor Device Trust & Geolocation Workplace Authorization

### 5.1 Trusted Device Fingerprinting
- In [`includes/account_security.php`](../includes/account_security.php):
  - Validates `kofee_device` cookie against the `auth_devices` table.
  - Tokens are 32-byte cryptographically random strings stored as SHA-256 hashes with 30-day expiration.

### 5.2 Geolocation & Workplace Geofencing Review
In [`includes/login_approval_helpers.php`](../includes/login_approval_helpers.php):
- Logins from untrusted devices are placed in a pending authorization state (`login_authorizations` table).
- Computes Great-Circle distance to the store branch using the **Haversine formula**.
- **Security Invariant:** Coordinates provide telemetry for HR review and are **never** treated as an automated authentication factor or client-side bypass.
- Single-use consumption: `consume_login_approval()` locks the authorization row with `FOR UPDATE`, validates that the token matches the pending browser session (`pending_auth_token`), issues a trusted device cookie, and marks `session_created = 1`.

### 5.3 Anti-Fraud Operational Shift Guard
In [`includes/shift_guard.php`](../includes/shift_guard.php):
- Operational staff (cashiers, crew, warehouse) cannot access POS order workflows or inventory actions without clocking in for an active shift today.
- UI layer: Non-dismissible modal (`render_shift_gate()`) traps keyboard navigation (`Escape`) and scroll.
- API layer: `require_shift_for_api()` halts mutations with HTTP `403` (`SHIFT_REQUIRED`).

---

## 6. Cross-Site Request Forgery (CSRF) Architecture

Implemented in [`includes/security.php`](../includes/security.php), [`includes/request_security.php`](../includes/request_security.php), and [`js/security.js`](../js/security.js):

- **Token Generation:** 32-byte cryptographically random token (`bin2hex(random_bytes(32))`), minted per session.
- **Multiple Verification Vectors:** Checks `_csrf` / `csrf_token` in `$_POST`, JSON request bodies, and the `X-CSRF-Token` HTTP header.
- **Constant-Time Comparison:** Evaluated using `hash_equals()`.
- **Automated HTML Injection:** `browser_security_output()` uses PHP output buffering to automatically inject `<meta name="csrf-token">`, hidden `_csrf` form fields, and idempotency keys into all `<form method="post">` markups.
- **Client-Side Automation (`js/security.js`):**
  - Monkey-patches `window.fetch` to attach `X-CSRF-Token` on all same-origin non-GET requests.
  - Strict origin validation guarantees CSRF tokens are never forwarded to external URLs.
  - Intercepts all `<a href=".../auth/logout.php">` clicks and converts them into explicit `POST` requests with CSRF tokens, preventing image-tag/link-based CSRF logout attacks.

---

## 7. Role-Based Access Control (RBAC) & Privilege Separation

Implemented in [`includes/permissions.php`](../includes/permissions.php) and [`includes/request_security.php`](../includes/request_security.php):

### 7.1 Granular Permissions & Multi-Role Model
- Accounts support multiple roles simultaneously via the `user_roles` table.
- Permissions are evaluated modularly (e.g. `orders.new`, `menu.manage`, `inventory.view`, `payroll.release`, `procurement.finance.review`).
- System roles (e.g. `admin`) are protected from deletion (`is_system = 1`).

### 7.2 Anti-Privilege Escalation Controls
In [`php/manage_users.php`](../php/manage_users.php):
- Only active system administrators can assign the `admin` role.
- Only administrators can modify administrator profiles, credentials, or statuses. Non-admins attempting modification receive `403 Forbidden`.
- **Dynamic Session Synchronization:** `sync_user_session_permissions()` reloads permissions from the database on every authenticated request or whenever cached permissions exceed 10 seconds, immediately applying permission revocations.

### 7.3 Boundary Request Policy Matrix
`api_request_policy()` in [`includes/request_security.php`](../includes/request_security.php) maps every API endpoint and action to:
- Permitted HTTP methods (`GET`, `POST`).
- Required permission keys.
- Sensitivity flag (requiring recent password re-authentication).
- Enforced at entry point via `guard_authenticated_request()`.

---

## 8. Cryptography & Data Protection at Rest

Implemented in [`includes/private_credentials.php`](../includes/private_credentials.php) and [`includes/jobs.php`](../includes/jobs.php):

### 8.1 Authenticated Encryption (AES-256-GCM)
- Utilizes Galois/Counter Mode (`aes-256-gcm`), providing Authenticated Encryption with Associated Data (AEAD).
- 256-bit key from `PRIVATE_DATA_KEY`.
- 12-byte cryptographically random IV per record (`random_bytes(12)`).
- 16-byte authentication tag `$tag` verifies ciphertext integrity; any bit modification or ciphertext tampering fails decryption.
- Versioned storage format: `v1:<base64(IV . TAG . Ciphertext)>`.

### 8.2 Encrypted Database Fields
- **Employee Bank Accounts:** Bank account numbers in `employee_payment_details` and `employee_payment_change_requests` are stored as encrypted blobs. Plaintext account numbers are never retained.
- **SMTP Credentials:** Passwords stored in `procurement_settings` are encrypted with AES-256-GCM. In production, credentials must be environment-managed (`CREDENTIALS_ENVIRONMENT_MANAGED`).
- **Encrypted Background Job Payloads:** Email job payloads queued in `background_jobs` (containing applicant resumes, tracking links, and reset tokens) are encrypted with AES-256-GCM using `JOB_ENCRYPTION_KEY`. Database dumps cannot expose message contents.

### 8.3 Data Masking & Secret Sanitation
- Bank account numbers are masked to last 4 digits: `•••• •••• •••• 1234`.
- Mobile phone numbers are masked: `0917 ••• 1234`.
- Audit logs filter details against an allowlist (`status`, `permission`, `decision`, `reason_code`) to ensure secrets and PII are never persisted in logs.

---

## 9. Database Security & Concurrency Integrity

Implemented in [`includes/db.php`](../includes/db.php) and [`database/migrations/security_v1.php`](../database/migrations/security_v1.php):

### 9.1 Secure PDO Configuration
```php
$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false, // Native prepared statements
    PDO::ATTR_STRINGIFY_FETCHES  => false,
]);
```
- Disabling emulated prepares forces MySQL to perform true two-phase query compilation and parameter binding, eliminating SQL injection vulnerabilities.
- Charset `utf8mb4` prevents multibyte encoding bypasses.

### 9.2 Concurrency Control & Row-Level Locking
- **Row Locking (`FOR UPDATE`):** Applied in order submission, ingredient deduction, password reset consumption, payment claims, and login authorization approval.
- **Deadlock Prevention:** Products and ingredients are ordered by primary key (`ORDER BY ingredient_id`) prior to locking, preventing distributed deadlock cycles between parallel checkout terminals.
- **Advisory Mutex:** Background worker in [`tools/worker.php`](../tools/worker.php) acquires MySQL advisory lock `GET_LOCK('kofee_background_worker', 0)` to guarantee singleton execution without race conditions.

---

## 10. Secure File Storage & Upload Defenses

Implemented in [`includes/private_storage.php`](../includes/private_storage.php) and [`php/download_file.php`](../php/download_file.php):

### 10.1 Physical Storage Outside Web Root
- `private_storage_root()` enforces that sensitive file directories reside outside the web server document root. If located within web root, it throws `STORAGE_CONFIGURATION_INVALID`.
- Direct web requests to `/uploads/` are blocked via Apache `Require all denied`.

### 10.2 Path Traversal Defenses
- `path_within()` verifies canonical paths via `realpath()` with case-insensitive normalization on Windows, neutralizing `..`, null-byte injections, and path traversal tricks.
- `validate_private_logical_path()` whitelists logical paths against strict regex:
  `^uploads/(resumes|attendance|supplier_permits|invoices|receipts|avatars)/[A-Za-z0-9_.-]{1,200}$`.

### 10.3 Content Verification & Denial-of-Service Defenses
- **Magic Byte MIME Detection:** Uses `finfo(FILEINFO_MIME_TYPE)` magic bytes; user-supplied extensions are untrusted.
- **Docx/Zip Bomb Defense:** Every `.docx`, including MIME-recognized DOCX files, requires the ZIP extension, fewer than 1,000 entries, required entries (`[Content_Types].xml`, `word/document.xml`), and no more than 50 MiB of total declared uncompressed content. Archives are inspected without extracting them.
- **Decompression / Pixel Bomb Defense:** Verifies image dimensions via `getimagesize()` (max 4,096 × 4,096 pixels, total pixels $\le$ 16 MP), mitigating memory exhaustion attacks.
- **Randomized File Names:** Files are stored with random 24-character hex names (`bin2hex(random_bytes(12))`).
- **Strict File Permissions:** Files are written with mode `0600` (readable/writable only by server process), directories with `0700`.

### 10.4 Authorized Download Reader (`download_file.php`)
- Validates user authentication.
- Verifies object ownership / RBAC via `can_read_private_file()`:
  - Resumes require `recruitment.manage`.
  - Attendance selfies require `attendance.view` or ownership.
  - Supplier documents require `procurement.view` or ownership.
  - Avatars require ownership or `users.manage`.
- **Cryptographic File Integrity:** Verifies SHA-256 checksum of physical file against `private_files.sha256` using constant-time `hash_equals()` before streaming.
- **Hardened Download Headers:**
  - `Content-Security-Policy: sandbox; default-src 'none'`: Neutralizes stored XSS (e.g., malicious SVG/HTML).
  - `X-Content-Type-Options: nosniff`: Prevents MIME confusion.
  - `Cache-Control: private, no-store`: Disables intermediate proxy caching.
  - `Content-Disposition: attachment` (or inline for approved images).

---

## 11. Financial, Payment Gateway & Order Security

Implemented in [`includes/order_service.php`](../includes/order_service.php), [`includes/payment_events.php`](../includes/payment_events.php), and [`includes/payout_service.php`](../includes/payout_service.php):

### 11.1 Server-Side Price Verification & Centavo Arithmetic
- **Server-Side Pricing Authority:** `validate_order_items()` fetches prices directly from the database under row lock. Prices submitted by client carts are disregarded.
- **Integer Centavo Math:** `money_centavos()` enforces regex `^(\d{1,10})(?:\.(\d{1,2}))?$` and converts currency to integer centavos, eliminating floating-point rounding errors.

### 11.2 Request & Order Idempotency
- Uses the `request_idempotency` table keyed by `(scope, actor_id, key_hash)`.
- Calculates canonical SHA-256 payload digests (`canonical_payload()`).
- Re-submitting an identical request returns the cached response without duplicate billing or double-deducting stock.
- Conflicting payloads with an existing key return `409 Conflict` (`IDEMPOTENCY_CONFLICT`).
- Frontend [`js/security.js`](../js/security.js) preserves `Idempotency-Key` across network retries.

### 11.3 PayMongo Webhook Security & Anti-Replay
- **HMAC-SHA256 Signatures:** Verifies `Paymongo-Signature` header (`t=..., te=...` or `li=...`) against webhook secrets using constant-time `hash_equals()`.
- **Anti-Replay Window:** Rejects webhooks with timestamps older or newer than 300 seconds (5 minutes).
- **Idempotent Webhook Deduplication:** Deduplicates event IDs and payload hashes in `payment_webhook_events`. Duplicate events return immediate acknowledgement without re-execution.
- **Amount & Currency Verification:** Verifies payment status (`paid`), currency (`PHP`), and validates that the paid amount matches the exact order total in centavos (`paid === money_centavos($order['total_amount'])`).
- **Late Payment State Handling:** If a payment arrives for a cancelled/expired order, it flags `reconciliation_required` in `payment_attempts` and logs `late_payment_requires_review` rather than corrupting inventory or order states.

### 11.4 Double-Disbursement & Double-Transfer Prevention
- `claim_transfer()` in [`includes/payout_service.php`](../includes/payout_service.php):
  - Uses `payment_attempts` with unique `operation_key`.
  - Disallows double payouts on payslips already marked paid (`PAYOUT_ALREADY_PAID`).
  - Locks invoice records `FOR UPDATE`, checks already-completed payments, and checks all currently pending/held transfers (`status IN ('processing', 'submitted', 'unknown')`) so simultaneous transfers cannot exceed the unpaid balance (`INVOICE_BALANCE_CONFLICT`).

### 11.5 Procurement 3-Way Matching Controls
- In [`php/three_way_match.php`](../php/three_way_match.php): Compares Purchase Orders (PO), Goods Receipt Notes (GRN), and Invoices with price variance (e.g. 3.0%) and quantity tolerance thresholds before releasing invoices for payment.

---

## 12. Public Endpoints & Anti-Abuse Defenses

### 12.1 Atomic Database-Backed Rate Limiting
Implemented in `rate_limit()` in [`includes/account_security.php`](../includes/account_security.php) using the `security_rate_limits` table:
- Fixed-window counters with `FOR UPDATE` row locks. The existing schema records a window start and attempt count; it does not implement a sliding window.
- **Job Submissions:** 10 submissions per hour per IP.
- **Supplier Applications:** 10 submissions per hour per IP.
- **Password Reset Requests:** 20 requests per hour per IP, 5 per hour per user account.
- **Tracking Recovery:** 20 per hour per IP, 5 per hour per email address.
- Returns `429 Too Many Requests` with `Retry-After` headers.

### 12.2 Applicant Tracking Privacy & Anti-Enumeration
In [`includes/application_tracking.php`](../includes/application_tracking.php):
- Applications cannot be tracked by sequential database IDs.
- Generates 32-byte cryptographically random tokens, stored hashed (`SHA-256`) in `application_tracking_tokens` with 30-day expiration.
- Delivered via email using a **URL fragment** (`#track=<token>`). Because URL fragments are handled entirely by client-side JavaScript, the token is never sent in HTTP request headers, never logged in web server access logs, and never leaked in `Referer` headers.
- Recovery requests return uniform feedback to prevent applicant enumeration.

---

## 13. Security Audit Logging & Test Verification

### 13.1 Centralized Security Audit Trail
The `security_audit` table logs security events with fields:
`actor_id`, `action`, `entity_type`, `entity_id`, `request_id`, `details`, `created_at`.
- Events logged include:
  `login`, `sessions_revoked`, `password_reset`, `reauthenticated`, `device_login_approved`, `device_login_rejected`, `account_status_changed`, `role_permission_changed`, `mutation_requested`, `order_submitted`, `order_cancelled`, `stock_restored`, `payment_confirmed`, `transfer_requested`, `file_downloaded`, `late_payment_requires_review`.

### 13.2 Automated Security Test Suites
- [`tests/security.test.cjs`](../tests/security.test.cjs): Unit tests covering CSRF token binding, cross-origin token leakage prevention, operation key retention during browser network retries, and authorized reader URL rewrites.
- [`tests/backend/SecurityTest.php`](../tests/backend/SecurityTest.php): Unit tests covering webhook signatures, replay window expiration, host header spoofing resistance, password character and byte limits, centavo arithmetic precision, path traversal rejection, payload encryption, browser approval binding, and recent password requirements.
- [`tests/backend/LoginRecoveryTest.php`](../tests/backend/LoginRecoveryTest.php): Process-isolated tests verifying idle session expiration, absolute lifetime expiration, revoked session denial, inactive account lockout, and schema migration integrity.
- [`tests/backend/MySqlIntegrationTest.php`](../tests/backend/MySqlIntegrationTest.php): End-to-end MySQL concurrency tests verifying that parallel child processes cannot oversell stock, duplicate checkouts return identical order records, concurrent password reset attempts permit only one consumption, and device trust tokens expire accurately.

---

## 14. Complete Security Feature Matrix

| Security Layer | Specific Defense Mechanism | Primary Implementation Files |
| :--- | :--- | :--- |
| **Server & HTTP** | No directory indexing (`Options -Indexes`) | [`.htaccess`](../.htaccess), [`uploads/.htaccess`](../uploads/.htaccess) |
| | Block sensitive extensions (`.sql`, `.md`, `.env`, `.bak`, `.zip`) | [`.htaccess`](../.htaccess) |
| | Cloak metadata (`.git`, `node_modules`, `package.json`) | [`.htaccess`](../.htaccess) |
| | Deny internal directories (`/includes/`, `/database/`, `/cache/`) | Subdirectory [`.htaccess`](../includes/.htaccess) files |
| | Security Headers (`nosniff`, `SAMEORIGIN`, `strict-origin`, `HSTS`) | [`security.php`](../includes/security.php), [`.htaccess`](../.htaccess) |
| | Permissions-Policy (`camera=(self), microphone=()`) | [`security.php`](../includes/security.php) |
| | Server fingerprint removal (`X-Powered-By`) | [`security.php`](../includes/security.php), [`.htaccess`](../.htaccess) |
| | Host header poisoning prevention (`parse_url()` validation) | [`runtime.php`](../includes/runtime.php) |
| | Reverse proxy validation (`TRUSTED_PROXIES` allowlist) | [`runtime.php`](../includes/runtime.php) |
| | Request tracing & correlation (`X-Request-ID`) | [`runtime.php`](../includes/runtime.php) |
| | Global exception masking boundary (`display_errors = 0`) | [`runtime.php`](../includes/runtime.php) |
| **Session Security** | Strict cookie flags (`HttpOnly`, `SameSite=Lax`, `Secure`) | [`security.php`](../includes/security.php) |
| | Strict session mode & cookie-only transport | [`security.php`](../includes/security.php) |
| | 30-minute idle inactivity timeout | [`account_security.php`](../includes/account_security.php) |
| | 12-hour absolute session expiration | [`account_security.php`](../includes/account_security.php) |
| | 30-minute periodic session ID rotation | [`security.php`](../includes/security.php) |
| | Database-backed session state table (`auth_sessions`) | [`account_security.php`](../includes/account_security.php), [`security_v1.php`](../database/migrations/security_v1.php) |
| | Real-time global session revocation (`revoke_user_sessions()`) | [`account_security.php`](../includes/account_security.php) |
| **Authentication** | Bcrypt password hashing (Cost 12) | [`manage_users.php`](../php/manage_users.php), [`account_security.php`](../includes/account_security.php) |
| | Timing-attack dummy hash verification | [`login_process.php`](../auth/login_process.php) |
| | Password policy: 15+ unicode chars, $\le$72 UTF-8 bytes, no nulls | [`runtime.php`](../includes/runtime.php) |
| | Persistent brute-force throttle (by username & IP) | [`security.php`](../includes/security.php) |
| | Step-up re-authentication ("Sudo Mode", 5-min window) | [`account_security.php`](../includes/account_security.php), [`security.js`](../js/security.js) |
| | Single-use hashed password reset tokens (SHA-256, 30 min) | [`forgot_password.php`](../auth/forgot_password.php), [`reset_password.php`](../auth/reset_password.php) |
| **Device & Anti-Fraud**| 30-day SHA-256 hashed device trust cookies | [`account_security.php`](../includes/account_security.php) |
| | Haversine formula workplace geofencing telemetry | [`login_approval_helpers.php`](../includes/login_approval_helpers.php) |
| | Manager/HR login authorization review queue | [`login_approval_helpers.php`](../includes/login_approval_helpers.php) |
| | Non-dismissible operational Shift Guard gatekeeper | [`shift_guard.php`](../includes/shift_guard.php) |
| **CSRF Defenses** | 32-byte cryptographically random session tokens | [`security.php`](../includes/security.php) |
| | Constant-time token verification (`hash_equals()`) | [`security.php`](../includes/security.php) |
| | Output buffer auto-injection for forms & meta tags | [`request_security.php`](../includes/request_security.php) |
| | Client-side `fetch` wrapper attaching `X-CSRF-Token` | [`security.js`](../js/security.js) |
| | Cross-origin token leakage prevention | [`security.js`](../js/security.js), [`security.test.cjs`](../tests/security.test.cjs) |
| | Interception of GET logout links into CSRF-protected POST | [`security.js`](../js/security.js) |
| **RBAC & Authorization**| Dynamic multi-role model & granular permission keys | [`permissions.php`](../includes/permissions.php) |
| | Centralized boundary guard (`guard_authenticated_request()`) | [`request_security.php`](../includes/request_security.php) |
| | API route policy matrix (`api_request_policy()`) | [`request_security.php`](../includes/request_security.php) |
| | Anti-privilege escalation checks (admin role protection) | [`manage_users.php`](../php/manage_users.php) |
| | Dynamic database session permission re-sync | [`permissions.php`](../includes/permissions.php) |
| | Safe access-denied redirection (`no_access.php`) | [`permissions.php`](../includes/permissions.php) |
| **Cryptography** | AES-256-GCM authenticated encryption (256-bit, 12-byte IV) | [`private_credentials.php`](../includes/private_credentials.php) |
| | Encrypted employee bank account numbers | [`profile_helpers.php`](../includes/profile_helpers.php), [`private_credentials.php`](../includes/private_credentials.php) |
| | Encrypted SMTP configuration passwords | [`mailer.php`](../includes/mailer.php), [`private_credentials.php`](../includes/private_credentials.php) |
| | Encrypted background job email payloads | [`jobs.php`](../includes/jobs.php) |
| | Masked display for bank accounts and mobile numbers | [`profile_helpers.php`](../includes/profile_helpers.php) |
| **Database Integrity** | Disabled emulated prepares (`ATTR_EMULATE_PREPARES = false`) | [`db.php`](../includes/db.php) |
| | Full `utf8mb4` character set | [`db.php`](../includes/db.php) |
| | Row-level locking (`FOR UPDATE`) with sorted lock order | [`order_service.php`](../includes/order_service.php), [`ingredient_deduction.php`](../includes/ingredient_deduction.php) |
| | MySQL advisory locks for background workers (`GET_LOCK`) | [`worker.php`](../tools/worker.php) |
| **File Storage** | Storage outside web document root | [`private_storage.php`](../includes/private_storage.php) |
| | Path traversal prevention (`path_within` & canonical regex) | [`private_storage.php`](../includes/private_storage.php) |
| | Magic byte MIME verification via `finfo` | [`private_storage.php`](../includes/private_storage.php) |
| | Docx zip bomb & 16-megapixel image bomb validation | [`private_storage.php`](../includes/private_storage.php) |
| | Randomized file naming (`random_bytes(12)`) | [`private_storage.php`](../includes/private_storage.php) |
| | Object access control matrix (`can_read_private_file()`) | [`private_storage.php`](../includes/private_storage.php) |
| | SHA-256 file integrity checksum verification | [`download_file.php`](../php/download_file.php) |
| | Sandboxed download delivery (`CSP: sandbox; default-src 'none'`) | [`download_file.php`](../php/download_file.php) |
| **Financial & Payments**| Authoritative server-side price recalculation | [`order_service.php`](../includes/order_service.php) |
| | Centavo integer arithmetic (`money_centavos()`) | [`order_service.php`](../includes/order_service.php) |
| | Request & order idempotency framework (`request_idempotency`) | [`order_service.php`](../includes/order_service.php), [`security.js`](../js/security.js) |
| | PayMongo webhook HMAC-SHA256 signature verification | [`runtime.php`](../includes/runtime.php), [`payment_events.php`](../includes/payment_events.php) |
| | 300-second webhook replay window | [`runtime.php`](../includes/runtime.php) |
| | Idempotent webhook event deduplication table | [`payment_events.php`](../includes/payment_events.php) |
| | Double-disbursement & balance over-allocation locks | [`payout_service.php`](../includes/payout_service.php) |
| | Late payment reconciliation exception state | [`order_service.php`](../includes/order_service.php), [`payment_events.php`](../includes/payment_events.php) |
| | Procurement 3-Way Matching tolerance controls | [`three_way_match.php`](../php/three_way_match.php) |
| **Public Anti-Abuse** | Sliding-window DB rate limiter with `Retry-After` headers | [`account_security.php`](../includes/account_security.php) |
| | Unguessable 32-byte hashed applicant tracking tokens | [`application_tracking.php`](../includes/application_tracking.php) |
| | URL fragment status delivery (no web server log leaks) | [`application_tracking.php`](../includes/application_tracking.php) |
| | Anti-enumeration uniform responses | [`forgot_password.php`](../auth/forgot_password.php), [`application_tracking.php`](../includes/application_tracking.php) |
| **Audit & Testing** | Comprehensive `security_audit` logging table | [`runtime.php`](../includes/runtime.php), [`security_v1.php`](../database/migrations/security_v1.php) |
| | Automated concurrency, unit & integration test suites | [`tests/backend/`](../tests/backend/), [`tests/security.test.cjs`](../tests/security.test.cjs) |
