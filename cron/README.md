# Renewal Reminder Cron Job

`send_renewal_reminders.php` sends a WhatsApp reminder (via WAHA) to the **agent**
(not the customer directly) covering every policy of theirs that is still
`renewal_status = 'pending'` and expiring:

- in exactly **30**, **20**, or **10** days — included once each
- in **fewer than 10** days (including today) — included **every day** until the
  policy is renewed/lapsed/cancelled, since these need urgent follow-up/confirmation

**One message per agent, not one per policy.** All of an agent's due policies for
the day are grouped into a single digest message, sorted most-urgent first. If an
agent has more than `MAX_POLICIES_PER_MESSAGE` (default 25) policies due on the same
day, the list is paginated into multiple messages (`Bagian 1/3`, `2/3`, ...) instead
of one message per policy — so an agent with 100+ renewals due doesn't get 100+
separate WhatsApp messages.

Each policy's reminder is still logged individually to `follow_up_logs`
(`channel = 'whatsapp_reminder'`) keyed by policy + date, so a re-run on the same
day never double-sends — a policy already reminded today is simply left out of that
agent's digest on the next run.

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
Renewal reminders done: agents_notified=12, messages_sent=15, policies_included=142, skipped_no_agent_whatsapp=1, skipped_already_sent_today=0
```

## Notes

- Requires `.env` to have `WAHA_BASE_URL`, `WAHA_SESSION`, `WAHA_API_KEY` set (see
  `notification/WHATSAPP_WAHA_GUIDE.md`).
- Runs across **all companies** in one pass — there's no per-tenant scheduling.
- Recipient is the policy's `issuing_agent_id`, falling back to whoever `created_by`
  the policy if no issuing agent is set. Their number comes from
  `app_user.phone_number` (same field used for the OTP WhatsApp flow).
- Policies whose agent (and fallback creator) have no phone number on file are
  skipped and counted in `skipped_no_agent_whatsapp`.
- To change how many policies fit in one message before it paginates, edit
  `MAX_POLICIES_PER_MESSAGE` at the top of the script.
