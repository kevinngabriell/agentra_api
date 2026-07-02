# WhatsApp Notification API Using WAHA - Complete Guide

## Overview
WhatsApp notifications are sent through WAHA (WhatsApp HTTP API), a self-hosted solution that wraps real WhatsApp Web sessions via HTTP endpoints.

## Configuration
WAHA settings are loaded from `.env` (see `.env.example`) into constants in `config.php`:

```php
define('WAHA_BASE_URL', $_ENV['WAHA_BASE_URL'] ?? '');
define('WAHA_SESSION',  $_ENV['WAHA_SESSION']  ?? '');
define('WAHA_API_KEY',  $_ENV['WAHA_API_KEY']  ?? '');
```

Required `.env` entries:

```
WAHA_BASE_URL=https://your-waha-host.example.com
WAHA_SESSION=your_session_name
WAHA_API_KEY=your_waha_api_key
```

## Core Helper Function

`sendWhatsAppText()` in `notification/notification.php` handles HTTP communication with WAHA:

- Accepts `chatId` (phone format: `<digits>@c.us`), message text, and session name (defaults to `WAHA_SESSION`)
- Returns a structured response containing `success`, `httpCode`, and `data`/`raw`
- Uses CURL with a 10 second timeout and reports CURL errors
- Adds an `X-Api-Key` header when `WAHA_API_KEY` is configured

## Usage Pattern

Phone numbers must be sanitized before use:
- Strip all non-digit characters (`preg_replace('/[^0-9]/', '', $phone)`)
- Append `@c.us` to form the `chatId`

Call `sendWhatsAppText()` *after* the primary business logic completes, and treat its result as non-fatal — a WhatsApp delivery failure should never block the main request. See `auth/forgot-password.php` for the reference implementation (OTP delivery on password reset).

```php
require_once __DIR__ . '/../notification/notification.php';

$chatId = "{$phone}@c.us";
$text   = "Your message here";

sendWhatsAppText($chatId, $text);
```

## Testing WAHA Connectivity

Verify session authentication:
```
GET {WAHA_BASE_URL}/api/sessions/{session_name}
```

Test message delivery directly:
```bash
curl -X POST "{WAHA_BASE_URL}/api/sendText" \
  -H "Content-Type: application/json" \
  -H "X-Api-Key: {WAHA_API_KEY}" \
  -d '{"chatId":"628xxxxxxxxxx@c.us","text":"Test message","session":"{WAHA_SESSION}"}'
```

## Common Issues

| Symptom | Likely Cause |
|---|---|
| `success: false`, `httpCode: 0` | WAHA host unreachable, or CURL/network error (see `error` field) |
| `httpCode: 401/403` | Missing or invalid `WAHA_API_KEY` |
| `httpCode: 404` on sendText | Session name in `WAHA_SESSION` doesn't exist on the WAHA server |
| Message not delivered despite `success: true` | WhatsApp session logged out / QR code expired — re-scan on the WAHA dashboard |
| Malformed `chatId` | Number wasn't stripped of `+`, spaces, or leading `0`s before appending `@c.us` |
