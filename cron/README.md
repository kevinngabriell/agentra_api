# Renewal Reminder Cron Job

`send_renewal_reminders.php` sends a WhatsApp reminder (via WAHA) to the customer of
every policy that is still `renewal_status = 'pending'` and expiring:

- in exactly **30**, **20**, or **10** days — one reminder each
- in **fewer than 10** days (including today) — sent **every day** until the policy
  is renewed/lapsed/cancelled, since these need urgent follow-up/confirmation

Each send is logged to `follow_up_logs` (`channel = 'whatsapp_reminder'`) keyed by
policy + date, so re-running the script on the same day never double-sends.

## VPS crontab setup

The script itself doesn't need any special timezone handling — `getConn()` already
sets the MySQL session to `+07:00` (WIB), so all date math (`DATEDIFF`, `CURDATE()`)
is computed in Jakarta time regardless of the server's OS timezone.

What you *do* need to get right is the crontab schedule, since cron runs on the
**server's local time**, not WIB, unless the server itself is set to WIB.

**If the VPS OS timezone is already Asia/Jakarta** (check with `timedatectl` or `date`):

```cron
0 9 * * * php /path/to/agentra_api/cron/send_renewal_reminders.php >> /path/to/agentra_api/cron/renewal_reminders.log 2>&1
```

**If the VPS OS timezone is UTC** (09:00 WIB = 02:00 UTC):

```cron
0 2 * * * php /path/to/agentra_api/cron/send_renewal_reminders.php >> /path/to/agentra_api/cron/renewal_reminders.log 2>&1
```

Adjust the hour if the server runs on a different timezone. Verify with:

```bash
date  # server's current local time/timezone
```

## Manual test run

```bash
php cron/send_renewal_reminders.php
```

Prints a one-line summary, e.g.:

```
Renewal reminders done: sent=3, skipped_no_whatsapp=1, skipped_already_sent_today=0
```

## Notes

- Requires `.env` to have `WAHA_BASE_URL`, `WAHA_SESSION`, `WAHA_API_KEY` set (see
  `notification/WHATSAPP_WAHA_GUIDE.md`).
- Runs across **all companies** in one pass — there's no per-tenant scheduling.
- Customer WhatsApp number is `personal_whatsapp` (individual) or `pic_whatsapp`
  (company), whichever is set.
- Policies with no WhatsApp number on file are skipped and counted in
  `skipped_no_whatsapp`.
