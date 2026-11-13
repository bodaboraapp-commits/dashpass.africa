# Contact form email (Help page): Resend SMTP setup

The public **Help → Contact us** form (`POST /help/contact`) sends through the project's
shared mail layer (`App\Integrations\Mail\MailManager`). In this project that layer is
already wired to **Resend over SMTP** (`smtp.resend.com:465`, implicit TLS, username
`resend`, password = Resend API key).

## Current configuration (2026-11-13)

| Key | Value |
|---|---|
| `MAIL_DRIVER` | `smtp` |
| `MAIL_SMTP_HOST` / `PORT` | `smtp.resend.com` / `465` |
| `MAIL_SMTP_USERNAME` | `resend` |
| `MAIL_SMTP_PASSWORD` | Resend API key (`.env` only; never commit) |
| `MAIL_FROM_ADDRESS` | `onboarding@resend.dev` (default) |
| `MAIL_CONTACT_TO` | *(new)* contact-form inbox; falls back to `MAIL_ALERT_TO`, then `app.support_email` (`hello@dashpass.africa`) |

The controller sets the visitor's email as **Reply-To**, so replying to the notification
answers the sender directly. Delivery failures are caught: the visitor sees a generic
"could not send" message and the admin log records only the event/exception class (never
the visitor's PII or provider details).

## Verified status (local test, 2026-11-13)

- `php scripts/mail-test.php --check` → **OK SMTP authentication succeeded** (Resend SMTP
  credentials are valid).
- `php scripts/mail-test.php --to=hello@dashpass.africa` → **FAIL `MAIL_SEND_FAILED: SMTP
  DATA body failed (status 550)`**.

The 550 is the Resend **sender-domain restriction**: while the project sends from the
sandbox sender `onboarding@resend.dev`, Resend only allows delivery to the Resend account
owner's own address. It is **not** an application bug.

## Owner console steps to make the contact form deliver to hello@dashpass.africa

1. In the **Resend dashboard → Domains**, add and verify `dashpass.africa` (DNS records:
   SPF/MX/DKIM as shown). This is the same account the `RESEND_API_KEY` belongs to.
2. Set the production `.env`:
   - `MAIL_DRIVER=smtp`
   - `MAIL_FROM_ADDRESS=hello@dashpass.africa` (a verified-domain sender; any address on
     the verified domain works)
   - `MAIL_FROM_NAME=DashPASS`
   - `MAIL_CONTACT_TO=hello@dashpass.africa` (or whichever inbox should receive enquiries)
   - keep `MAIL_SMTP_HOST/PORT/USERNAME/PASSWORD/ENCRYPTION` as above
3. Restart PHP-FPM/Apache so the new env is loaded, then verify:
   - `php scripts/mail-test.php --check` → OK
   - `php scripts/mail-test.php --to=hello@dashpass.africa` → OK (message arrives)
   - Submit the Help contact form once and confirm the email arrives with the visitor as
     Reply-To.
4. Keep the sender address on the verified domain in production. `onboarding@resend.dev`
   is a testing-only sender and will keep returning 550 for non-owner recipients.
