# Digitz PMS

Digitz PMS is an offline-first Progressive Web App for freelance real-estate brokers and property owners in the UAE. It covers multi-level sub-leasing and rentals (property, then room, then bed space), PDC cheques, accounting and OCR receipt scanning. It also has two optional modules: a Health Tracker, and My Family with an encrypted local document vault. The backend is PHP 8.1+ with MySQL 8 / MariaDB 10.6+. Cloud sync is an optional paid add-on, and the server only ever stores JSON text.

## Files

```
index.html            The whole app: HTML + CSS + vanilla JS (ES module), EN/ML dictionary inside
manifest.json         PWA manifest (192/512 + maskable icons, standalone)
sw.js                 Service worker: app-shell + Tesseract precache, network-first API, Web Push
vendor/               Tesseract.js 5 (self-hosted so OCR works offline) + English LSTM data
icons/                App icons
install.php           Web installer → config.php, schema, SUPERADMIN, VAPID keys, sample plans
config.sample.php     Configuration template (WhatsApp Cloud API, DB, JWT, mail)
schema.sql            Database schema
api/auth.php          Sign-up + WhatsApp OTP, PIN/staff login, refresh, reset PIN, change secret
api/sync.php          Push/pull sync, newest-updated_at-wins, conflict audit, 402 gating
api/subscriptions.php Plans, subscription requests, invoices/receipts (sequential numbers)
api/commissions.php   Vendor rates, payouts (oldest-first, partial), statements
api/vacancies.php     Inter-agency vacancy sharing (text metadata only)
api/admin.php         Staff console API (agencies, modules, users, vendors, settings, logs)
api/push.php          Web Push subscribe + pending messages
api/lib/              Bootstrap (security, binary rejection, tokens, scoping), billing, push, WhatsApp
cron/reminders.php    PDC email + push reminders (today / 3 / 7 days), expiries, housekeeping
.htaccess             HTTPS redirect, blocks config/schema/lib/cron from the web
```

## Deploy (GitHub → test → production)

See **DEPLOY.md**. Push to `develop` deploys to the test server, and merging into `main` deploys to production after approval. `deploy/setup-server.sh` prepares a fresh Ubuntu 24.04 server with a single command.

## Install (manual, single server)

1. Upload everything to an HTTPS host running Apache with PHP 8.1+. The host needs the PDO MySQL, OpenSSL, cURL and mbstring extensions.
2. Create an empty MySQL database, then open `https://your-host/install.php`.
3. In the installer, enter:
   - the database credentials
   - the public URL
   - your WhatsApp Business Cloud API phone-number ID and token
   - optionally, a default Client Admin. Self sign-up needs exactly one default or active agency.
4. Log in with **SUPERADMIN / Superadmin@123** through *Staff / admin login*. The app asks you to choose a new password before anything else.
5. Add the cron job: `*/30 * * * * php /path/to/cron/reminders.php`
6. Delete `install.php`. `install.lock` already blocks a re-run.

On Nginx, reproduce the `.htaccess` rules: deny `config.php`, `schema.sql`, `api/lib/` and `cron/`, and serve `.wasm` as `application/wasm`.

### WhatsApp template
Create an **Authentication** template, for example `digitz_otp`, in English, with the code as body parameter `{{1}}` and a copy-code button. Set `whatsapp.template_name` in `config.php`. If your template has no button, set `button_param => false`.

For testing without WhatsApp, tick *Development OTP mode* in the installer. Codes are then returned in the API response and shown on the OTP screen. **Turn this off in production** by setting `otp_dev_mode` to `false`.

## How the spec is implemented (key decisions)

- **Money and dates.** All amounts are integer fils and are shown as `AED 1,234.50`. Dates are stored in ISO UTC and shown as DD/MM/YYYY in Asia/Dubai time.
- **IDs.** Every record has a client UUID v4 plus `created_at`, `updated_at`, `deleted_at` and `owner_user_id`.
- **No binaries in the cloud.** The protection works at three levels:
  - Photos, receipts and vault files live only in IndexedDB.
  - `SYNC_FIELDS` (client) and `SYNC_ENTITIES` (server) whitelist the fields that can sync. `vault_files` and `attachments` are not syncable at all.
  - Every API request passes `input()` before any endpoint logic runs. It rejects multipart and non-JSON requests (415), payloads over 1 MB (413), and data URIs, long base64 runs and file signatures (422).
- **Vault.**
  - A random AES-GCM key encrypts the files.
  - That key is wrapped twice: by a PBKDF2 key derived from the PIN (310k iterations), and by a server-held *recovery secret*. The recovery secret is only released after an OTP-verified reset.
  - Because of the second wrap, a PIN reset keeps the vault readable, so the reset is non-destructive as required.
  - Biometrics can open the vault only on authenticators that support the WebAuthn PRF extension. On others the vault asks for the PIN.
  - Decrypted content is cleared from memory when the app locks.
- **Auto-lock.** The app locks on `visibilitychange: hidden` or window `blur`. Lock is briefly suppressed while the camera or file picker, the biometric prompt or the print dialog is open. Without this, scanning a receipt on a phone would lock the app mid-capture.
- **PIN login works offline.** It is checked against a local PBKDF2 verifier. Lockout starts at 5 minutes after 5 failures and doubles on each repeat. The server applies the same policy.
- **Sync.**
  - The client queues changes and pushes batches of at most 400 records and about 800 KB.
  - When the Cloud Sync add-on is inactive, push gets HTTP 402 (`SYNC_INACTIVE`), but pull stays allowed.
  - The newest `updated_at` wins, and the server logs both versions to `sync_conflicts`.
  - Deletions sync as tombstones.
- **Statuses.** A unit's status is derived from its leases:
  - **Leased** when a lease covers today.
  - **Booked** when a lease starts in the future.
  - **Vacant** otherwise.
  - **Under Maintenance** is manual and sticky.

  When a unit stops being vacant, any shared listing for it is expired by the client and again by the cron job.
- **Vacancy sharing.** This was interpreted as: brokers (end users) share their own vacant units under their agency. The Super Admin enables sharing per agency. The agency decides which users may share and whether its users can see other agencies' listings. Each listing carries a commission split, and photos are requested over WhatsApp.
- **Web Push.** Pushes are sent with VAPID and **no payload**, so no encryption library is needed. The service worker fetches the message text from `api/push.php?action=pending`.
- **Visibility.** Super Admin rows and logs are excluded from every Client Admin, Vendor and user query. Every query is scoped to the caller's agency.

## Testing done
- An API smoke test covered 47 checks across sign-up/OTP, lockout, refresh-token rotation and reuse detection, 402 gating, binary rejection, the field whitelist, conflict handling, invoice to receipt to commission, partial payouts, statements, vacancy sharing with auto-expiry, and the non-destructive PIN reset. All passed.
- Headless Chrome end-to-end runs covered:
  - sign-up, then adding a property, room, bed space, tenant and lease, with the schedule and cheques generated automatically
  - marking a payment as paid, status changes, accounting, the two-step delete followed by restore from Trash, a health log, and an encrypted vault upload (stored bytes verified as ciphertext)
  - the PIN keypad and lockout, Malayalam mode, the mobile layout, and the Super Admin and Client Admin consoles
  - **offline OCR**: a sample bill was read as `ACE HARDWARE / 163.28 / 14-11-2026`

  The runs produced no console errors.

## Not covered / notes
- Payments are recorded manually by the Super Admin or Client Admin. No payment gateway was specified.
- Email uses PHP `mail()`. Configure your host's sendmail or an SMTP relay.
- OCR ships English data only. Arabic-only receipts will need `ara.traineddata` added to `vendor/lang` and the SW list.
- The vault and receipt images are device-only by design. Use *Backup & restore* with attachments ticked to move them between devices. Vault files stay encrypted inside the backup.
