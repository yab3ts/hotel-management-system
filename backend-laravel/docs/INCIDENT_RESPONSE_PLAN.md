# Tableza / Café X Incident Response & Recovery Runbook (#129–#134)

## 1. Severity Classification
- **SEV-1 (Critical)**: Cross-tenant leakage, unauthorized admin access, ransomware, active DB outage, or payment webhook key compromise.
- **SEV-2 (High)**: Repeated brute-force lockouts, bulk PII export alerts, unauthorized VIP guest record access, or impersonation anomaly.
- **SEV-3 (Medium)**: After-hours staff login anomaly, expired stale holds, single-device offline sync failures.

## 2. Immediate Containment Playbook
1. **Compromised Staff/Admin Account**: Call `PATCH /api/staff/{user}/status` (`is_active: false`) to disable account and revoke all Sanctum tokens.
2. **Compromised QR Table Placard**: Call `POST /api/tables/{table}/regenerate-qr` to invalidate the old 128-bit token immediately.
3. **Branch Ordering Emergency Stop**: Call `POST /api/branches/toggle-qr` to pause QR ordering without taking POS offline.
4. **Compromised Impersonation Session**: Call `POST /api/auth/impersonation/stop` and review `security_alerts` + `audit_logs`.
5. **Compromised Gateway Webhook Secret**: Rotate `tenants.webhook_secret` (encrypted at rest) or global `PAYMENT_WEBHOOK_SECRET`.

## 3. Ransomware & Disaster Recovery (#129, #130, #131)
1. Provision clean PostgreSQL 16 instance (or promote standby replica).
2. Verify off-server AES-256-GCM backup integrity via SHA-256 companion `.json` manifest.
3. Execute `php artisan cafex:restore --force` using `BACKUP_ENCRYPTION_KEY`.
4. Verify `GET /api/security/readiness` confirms `database.healthy = true` and `rls_protected_tables > 0`.

## 4. Offline Outage Fallback (#134)
1. Terminal queues orders locally with unique UUID `idempotency_key` and UTC timestamp.
2. Upon reconnection, sync via `POST /api/offline/sync`. Server recomputes authoritative prices from DB and deduplicates replayed keys.
