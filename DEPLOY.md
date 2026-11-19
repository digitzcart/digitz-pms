# Deploying Digitz PMS: GitHub → Test server → Production

```
 your changes ──► develop branch ──(automatic)──► TEST server      https://test.yourdomain.ae
                       │
                 test it, then merge
                       ▼
                  main branch ──(you click Approve)──► PRODUCTION   https://pms.yourdomain.ae
```

- Every push runs **CI**: PHP syntax, JavaScript syntax, translation coverage, and a check that no secrets were committed.
- A push to `develop` deploys to the **test** server automatically.
- A push to `main` deploys to **production** only after an approver clicks **Approve** in GitHub.
- Each deploy uploads the code, applies database updates and runs a smoke test. The smoke test checks that the app loads, the API answers, and `config.php` is blocked.
- Server-only files (`config.php`, `install.lock`) are never overwritten. Photos and documents stay on users' devices, so a deploy cannot touch them.

## 1. GitHub (one time)

1. Create a **private** repository named `digitz-pms` under your account. Leave it empty: no README and no .gitignore.
2. Push this code. The `main` and `develop` branches are already prepared.
3. In **Settings → Environments**, create two environments:
   - `test`: no protection.
   - `production`: tick **Required reviewers** and add yourself. Optionally set **Deployment branches** to `main` only.

## 2. Test server (low cost)

1. **Rent a small Ubuntu 24.04 VPS** with 2 GB RAM or more, for example Hetzner Cloud, Contabo or DigitalOcean. Prices changed during 2026, so check them on the provider's site.
2. **Point a domain at it.** At your domain provider, add an A record: `test.yourdomain.ae` → the server's IP address.
3. **Log in as root and run the setup script:**
   ```bash
   curl -fsSL https://raw.githubusercontent.com/<you>/digitz-pms/develop/deploy/setup-server.sh -o setup.sh
   DOMAIN=test.yourdomain.ae EMAIL=you@yourdomain.ae ENV_NAME=test bash setup.sh
   ```
   The repository is private, so the raw link needs a token. Alternatively, copy `deploy/setup-server.sh` to the server yourself (for example with WinSCP) and run it.

   The script installs Nginx, PHP, MariaDB, a free HTTPS certificate, a firewall, fail2ban, automatic security updates and the reminders job. It then prints everything you need for the next step.
4. **Add the printed values to GitHub.** In the repository, go to **Settings → Environments → test** and add:
   - Secrets: `SSH_HOST`, `SSH_USER`, `DEPLOY_PATH` and `SSH_KEY` (the whole private key).
   - Variable: `APP_URL`.
5. **Deploy.** Push to `develop`, or open **Actions → Deploy → Run workflow** on `develop`. The first deploy also installs the database.
6. **Open** `https://test.yourdomain.ae`. Sign in as `SUPERADMIN` / `Superadmin@123`; you will be asked to change the password. On test, sign-up codes show on screen ("DEV: 123456"), so you do not need WhatsApp yet.

## 3. Testing checklist (on the test server)

- [ ] Sign up a broker; install the app on a phone (Add to Home Screen)
- [ ] Properties → rooms → bed spaces; lease with cheques; mark payments; bounced cheque
- [ ] Scan a real bill (OCR) with the phone camera
- [ ] Agency Admin: add vendor, assign user, record payout, print statement
- [ ] Subscription: request plan → mark Paid → Cloud Sync works on a second device
- [ ] Malayalam on every screen you use; light theme
- [ ] Backup → Restore on another phone
- [ ] Push and email cheque reminders arrive (needs Cloud Sync + a mail relay)

## 4. Production

1. Rent a second server (sized for real users) and point `pms.yourdomain.ae` at it.
2. Run the same script with production settings:
   ```bash
   DOMAIN=pms.yourdomain.ae EMAIL=you@yourdomain.ae ENV_NAME=production \
   WA_PHONE_ID=<from Meta> WA_TOKEN=<from Meta> bash setup.sh
   ```
   On production, sign-up codes are sent on WhatsApp, not shown on screen.
3. Add the printed values to **Settings → Environments → production**.
4. Open a pull request from `develop` → `main`, merge it, then approve the deploy under **Actions**.

## Everyday workflow

| You want to… | Do this |
| --- | --- |
| Try a change | Commit to `develop` → test server updates in ~1 minute |
| Release to customers | Merge `develop` into `main` → approve the deploy |
| Undo a bad release | In GitHub, revert the merge commit on `main` → approve |
| Back up the database | `mysqldump digitz_pms > backup.sql` on the server (schedule it daily) |

## Before production, also
- Email: install a mail relay (e.g. Postfix with an SMTP provider) so cheque reminder emails are delivered.
- Back up the database daily to somewhere off the server.
- Change the default agency password that the setup script printed.

## Optional: front-end on Netlify

Netlify can host the app screens (HTML, service worker, OCR files) for free. The PHP API and MySQL still run on your server.

1. In Netlify, choose **Add new project → Import an existing project → GitHub**, then pick `digitzcart/digitz-pms`. Use the `develop` branch for the test site and `main` for production. The build settings come from `netlify.toml`.
2. Under **Site configuration → Environment variables**, add:
   - `API_URL`: where `api/*.php` is served, for example `https://test.yourdomain.ae/api`.
   - `NETLIFY_ENV`: `test` or `production`. Production sites are left indexable by search engines; test sites are not.
3. On the server, allow the Netlify address to call the API by setting `FRONTEND_ORIGINS` when you run the setup script, for example `FRONTEND_ORIGINS=https://digitz-test.netlify.app`. If the server is already installed, add the address to `'allowed_origins'` in `config.php` instead.

Without `API_URL`, the Netlify site still opens and works offline, but sign-up, sync and the staff console need the API.
