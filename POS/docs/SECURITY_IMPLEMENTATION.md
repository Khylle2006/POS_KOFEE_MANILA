# Security implementation and rollout

The architecture in [SECURITY_ANALYSIS.md](SECURITY_ANALYSIS.md) was already largely present in the working tree. The changes below preserve that implementation and repair confirmed gaps. Authentication, permissions, personal files, and payment retries are affected. No application database schema, deployment configuration, or existing private data was changed during this work.

## Step 1: Server and request boundaries

- Added Permissions-Policy to both Apache configurations, denied developer tool metadata and PHPUnit/PHPStan cache directories, and corrected the literal test-directory match.
- Runtime responses now receive no-store, MIME, framing, referrer, hardware-access, request-correlation, and HTTPS security headers even when an endpoint does not call the HTML header helper.
- Session startup preserves the private cache policy. JSON input is limited to 8 MiB while reading, including requests without a Content-Length value.
- HTTP tests exercise headers, malformed/oversized JSON, token aliases, and sanitized exceptions. Apache configuration syntax was checked; production HTTPS, proxy behavior, and deployed access rules still require a server smoke test.

## Step 2: Authentication, sessions, and permissions

- Missing users receive bcrypt verification against a valid cost-12 dummy hash. Login and re-authentication reject bcrypt truncation and null-byte inputs; malformed login fields are handled explicitly.
- Both account and IP lockouts are checked, even when the account lockout has already expired. Supplier credentials now use cost 12.
- Removed the obsolete geolocation auto-login branch. Browser approval consumption locks the active user before the approval, following the reset/revocation lock order.
- Administrator account protection binds both role lookups and returns 403. Secondary role assignments prevent role deletion. Permission helpers start hardened sessions.
- Session expiry/revocation, password resets, device trust, permissions, and lockout regressions are covered by backend tests. Existing lower-cost password hashes need a later credential change to adopt cost 12; the system does not claim constant total login timing.

## Step 3: CSRF and financial retries

- Server form injection adds tokens only to same-origin POST forms, including checking submit-button action overrides. Existing token/key fields are retained, and different forms receive different request keys.
- Browser form submission checks effective submitter action/method; external submissions remove session token fields. External logout links are not intercepted.
- Financial fetches keep separate keys for outstanding request bodies, persist body digests rather than plaintext payloads, preserve explicit keys/headers, and replay an unconsumed Request after re-authentication.
- Successful retries clear only their own operation key. Failed or pending requests retain theirs, including across a page reload. Browser tests exercise overlapping operations, failed responses, Request bodies, confirmation retries, and external-origin isolation.
- Server price calculation, centavo arithmetic, idempotency, stock locking, signed webhook processing, and payout claims were already implemented. The isolated MySQL suite checks concurrent orders/resets, stock rollback/restoration, duplicate webhook handling, late-payment review, and transfer replay. Live provider calls were not made.

## Step 4: Private uploads and encryption

- All DOCX uploads undergo archive inspection, even when MIME detection recognizes the document. Validation requires fewer than 1,000 entries, required document entries, and at most 50 MiB of declared expanded content. Missing ZIP support fails closed.
- Existing private storage, authorized downloads, AES-GCM credentials, encrypted email jobs, tracking tokens, and audit filtering remain in use.
- Upload tests verify missing-extension denial, valid DOCX, incomplete structure, entry limits, expansion limits, and image dimensions. DOCX tests were also run with the bundled ZIP extension explicitly enabled.

## Step 5: Verify application readiness

Run the read-only checker from the repository root:

```powershell
C:/xampp/php/php.exe POS/tools/security_preflight.php
```

The current local preflight passes URL and payment-mode checks but reports these prerequisites:

| Check | Required action |
| --- | --- |
| PRIVATE_DATA_KEY | Configure a securely generated base64-encoded 32-byte key and keep a recoverable backup. |
| JOB_ENCRYPTION_KEY | Configure a separate securely generated base64-encoded 32-byte key. |
| ZIP extension | Enable the bundled PHP ZIP extension in the deployment runtime before accepting DOCX uploads. |
| Private storage | Configure an existing directory outside the actual Apache document root, owned by the PHP account. |
| Application database | Restore/configure connectivity, then verify the security migration version and tables. |

The preflight prints safe check results without showing credentials or creating storage directories. Deployment also needs a canonical HTTPS URL, an explicit trusted-proxy list where applicable, least-privilege database credentials, and configured SMTP/payment credentials for features that are enabled. Do not replace existing encryption keys on a database that already contains encrypted records.

## Step 6: Review and approve database rollout

The repository's AGENTS.md requires approval before altering database schemas. The existing [migration](../tools/migrate.php) and [security schema](../database/migrations/security_v1.php) are ready for review; they have not been applied to the application database.

1. Back up the application database, existing private files, encryption keys, and deployment configuration. Verify restore access before migration.
2. Configure migration credentials through MIGRATION_DB_HOST, MIGRATION_DB_NAME, MIGRATION_DB_USER, and MIGRATION_DB_PASS in the process environment. Use a separate maintenance account where available.
3. After approval, run `C:/xampp/php/php.exe POS/tools/migrate.php` during maintenance. This applies existing security/legacy migrations and records version `20261008_security_v1`; invalid legacy reset tokens are consumed. MySQL DDL is not transactionally reversible: an interrupted or incompatible migration needs inspection, and rollback requires the database backup.
4. Run `C:/xampp/php/php.exe POS/tools/backfill_private_credentials.php` with the correct private-data key. This converts supported legacy bank-account values and verifies existing encrypted records.
5. Run `C:/xampp/php/php.exe POS/tools/backfill_private_files.php` with private storage configured. This copies and checksums existing uploads while preserving original files. Deletion of original uploads requires separate approval.
6. Inspect legacy SMTP settings separately: the bank-account backfill does not convert plaintext SMTP passwords. Production uses environment-managed SMTP credentials; do not assume a legacy stored password is already encrypted.
7. Rerun preflight and smoke-test sign-in, role revocation, password reset email, approved device login, authorized/denied downloads, and an order against the configured payment mode.

## Step 7: Activate background processing and validate deployment

Run `C:/xampp/php/php.exe POS/tools/worker.php` under a server scheduler using the same configuration as the application. Verify encrypted email delivery, reconciliation jobs, failed-job visibility, and the singleton advisory lock. No scheduler or live provider setup was performed in this implementation pass.

Confirm HTTPS/HSTS, Secure session/device cookies, the actual proxy allowlist, and denied direct access to internal directories, private uploads, developer metadata, caches, and database dumps. Exercise PayMongo signatures and reconciliation in a provider test environment before authorizing live transfers. CSP remains report-only unless explicitly configured for enforcement after compatibility review.

## Verification

- Final `npm test` run: 27 browser security/UI tests and 81 PHP unit/HTTP/MySQL tests passed (205 PHP assertions, no skipped tests). The separate ZIP-enabled upload run passed all 5 tests.
- The MySQL integration suite runs against an isolated database whose name ends in `_test`, never the application database. It truncates its fixtures; only use disposable test databases.
- DOCX archive tests additionally run with `php -d extension=zip POS/vendor/bin/phpunit --configuration POS/phpunit.xml --filter PrivateUploadTest`.
- `npm run typecheck`, `npm run lint`, `npm run build`, `git diff --check`, and Apache syntax checks were run. PHP/JS syntax, PHPStan, the build, diff checks, and Apache syntax pass. Nine existing mixed-line-ending warnings in untouched legacy PHP files keep the full linter from passing; no checks were disabled.
- The build regenerates `POS/css/index.css`. The application database, private-data backfills, encryption configuration, PHP extension configuration, scheduler, and live provider behavior remain rollout prerequisites.
