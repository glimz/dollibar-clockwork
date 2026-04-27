# Clockwork - Dolibarr Time Tracking Module

[![License](https://img.shields.io/badge/license-GPL--3.0-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.html)
[![Dolibarr](https://img.shields.io/badge/dolibarr-17%2B-green.svg)](https://www.dolibarr.org/)

Clockwork is a Dolibarr module for attendance tracking, compliance reporting, payroll support, and notifications. It includes clock-in/clock-out, breaks, compliance and payslip generation, in-app alerts, webhook integrations, and JSON APIs.

---

## Table of Contents

- [Features](#features)
- [Install](#install)
- [Configuration](#configuration)
- [User Guide](#user-guide)
- [Payroll and Payslips](#payroll-and-payslips)
- [Notifications and Alerts](#notifications-and-alerts)
- [PWA Support](#pwa-support)
- [Cron Jobs](#cron-jobs)
- [API Reference](#api-reference)
- [IP Restriction](#ip-restriction)
- [Database and Migrations](#database-and-migrations)
- [Troubleshooting](#troubleshooting)
- [Development](#development)

---

## Features

### Core Time Tracking
- Clock in / clock out per user
- Multiple breaks per shift
- Shift notes
- Real-time shift status

### HR and Compliance
- HR shift listing and totals
- Monthly compliance with hour-based day calculation
- Expected day and deduction calculations
- Exclusions management for notifications/compliance behavior

### Payroll and Payslips
- Monthly payslip generation from compliance data
- Dedicated PDF payslip renderer
- Configurable email and PDF template files
- Employee self-service payslip listing and download
- Optional auto-email on payslip generation

### Alerts and Notifications
- Missed clock-in detection
- Overwork detection
- Idle shift detection (no activity while shift is open)
- Logout reminders
- Maximum shift duration alerts
- Escalating break reminders
- Weekly overtime alerts
- Fatigue management alerts
- Auto-close long open shifts
- Concurrent session detection
- Shift pattern violation detection
- In-app notifications center on the Clockwork page

### Integrations
- Discord webhooks (including dedicated idle webhook)
- Slack webhooks
- Microsoft Teams webhooks
- Browser notifications
- Dolibarr AI module integration for personalized idle insights
- JSON API endpoints for external tooling

### Security and Access
- IP restriction (CIDR allowlist)
- Network change monitoring during shifts
- User-right-based access control

---

## Install

### Release ZIP
1. Download a release from [GitHub Releases](https://github.com/glimz/dollibar-clockwork/releases).
2. Extract `clockwork/` into `htdocs/custom/clockwork/`.
3. Enable module in Dolibarr: **Home -> Setup -> Modules/Applications -> Clockwork**.
4. Open **Clockwork -> Setup** and configure constants.

### From Source
```bash
cd htdocs/custom/
git clone https://github.com/glimz/dollibar-clockwork.git clockwork
```

---

## Configuration

Open **Clockwork -> Setup**.

### Key notification toggles
- `CLOCKWORK_NOTIFY_CLOCKIN`
- `CLOCKWORK_NOTIFY_BREAK`
- `CLOCKWORK_NOTIFY_MISSED_CLOCKIN`
- `CLOCKWORK_NOTIFY_WEEKLY_SUMMARY`
- `CLOCKWORK_NOTIFY_OVERWORK`
- `CLOCKWORK_NOTIFY_LOGOUT_REMINDER`
- `CLOCKWORK_NOTIFY_NETWORK_CHANGE`
- `CLOCKWORK_NOTIFY_OVERTIME`
- `CLOCKWORK_NOTIFY_MAX_SHIFT`
- `CLOCKWORK_NOTIFY_FATIGUE`
- `CLOCKWORK_NOTIFY_IDLE`

### Webhook URLs
- `CLOCKWORK_WEBHOOK_DEFAULT`
- Per-type webhook overrides (clock-in, break, missed, summary, overwork, logout, network change, overtime, max shift, idle)
- `CLOCKWORK_WEBHOOK_SLACK`
- `CLOCKWORK_WEBHOOK_TEAMS`

### Idle detection
- `CLOCKWORK_IDLE_THRESHOLD_MINUTES` (default `20`)
- `CLOCKWORK_IDLE_REMINDER_MINUTES` (default `30`)

### AI personalization (plugin-only)
- `CLOCKWORK_AI_ENABLE_IDLE_INSIGHTS` (default `0`)
- `CLOCKWORK_AI_IDLE_PROMPT` (default: `Write one short actionable sentence for an HR idle shift alert. Mention whether user should clock out or resume activity.`)
- `CLOCKWORK_AI_IDLE_MAX_CHARS` (default `280`, bounded to `80..1000`)

Notes:
- Uses existing Dolibarr AI module (`AI`) when enabled.
- No modification is required in Dolibarr core AI files.
- Fallback behavior stays deterministic if AI is disabled/unavailable.

### Compliance and deductions
- `CLOCKWORK_HOURS_PER_DAY` (default `8`)
- `CLOCKWORK_DEDUCTION_PERCENT_PER_MISSED_DAY` (default `10`)
- `CLOCKWORK_DEDUCTION_MIN_COMPLIANCE` (default `90`)
- `CLOCKWORK_DEDUCTION_MAX_PERCENT` (default `100`)

### Payslip generation
- `CLOCKWORK_PAYSLIP_EMAIL_ON_GENERATE`
- `CLOCKWORK_PAYSLIP_MIN_AMOUNT`
- `CLOCKWORK_PAYSLIP_EMAIL_TEMPLATE_FILE`
- `CLOCKWORK_PAYSLIP_PDF_TEMPLATE_FILE`

### Network/IP controls
- `CLOCKWORK_ALLOWED_IPS`
- `CLOCKWORK_MONITOR_NETWORK_CHANGES`

---

## User Guide

### Employees
- **Clockwork -> My Time** (`clockwork/clock.php`):
  - Clock in/out
  - Start/end break
  - See live session stats
  - Receive in-app notifications

- **Clockwork -> My Payslips** (`clockwork/my_payslips.php`):
  - View generated payslips
  - Download PDF payslips

### HR/Admin
- **HR Shifts** (`clockwork/hr_shifts.php`)
- **HR Totals** (`clockwork/hr_totals.php`)
- **Monthly Compliance** (`clockwork/monthly_compliance.php`)
- **Exclusions** (`clockwork/exclusions.php`)

---

## Payroll and Payslips

Implemented flow:
1. Compute monthly compliance and deduction metrics.
2. Generate payslip records and dedicated PDF files.
3. Store file mapping in DB.
4. Optionally send payslip email on generation.
5. Employees access payslips in self-service page.

Template defaults:
- `templates/payslip_pdf_template.html`
- `templates/payslip_email_template.html`

Download endpoint:
- `clockwork/payslip_download.php`

---

## Notifications and Alerts

### Supported alert types
- Clock-in
- Break start/end
- Missed clock-in
- Overwork
- Logout reminder
- Network change
- Weekly summary
- Weekly overtime
- Fatigue
- Max shift exceeded
- Shift pattern violation
- Idle shift

### In-app notifications
- Stored in DB and shown on My Time page.
- Endpoint for list/mark-read:
  - `ajax/notifications.php?action=list`
  - `ajax/notifications.php?action=mark_all_read`

### Idle detection behavior
- Activity heartbeat updates active open shift timestamps.
- Idle cron checks inactivity and sends alerts (in-app + webhook).
- Optional AI enrichment can append personalized one-line guidance to idle alerts.

---

## PWA Support

Clockwork includes PWA assets:
- `pwa/manifest.json`
- `pwa/service-worker.js`
- `pwa/offline.html`
- `img/pwa-icon-192.png`
- `img/pwa-icon-512.png`

This enables installability via browser "Add to Home Screen" / "Install app" for Clockwork pages and provides basic offline fallback.

---

## Cron Jobs

Clockwork registers 12 cron jobs:
1. Missed clock-in notifications
2. Weekly summary
3. Overwork notifications
4. Logout reminders
5. Max shift alerts
6. Escalating break reminders
7. Weekly overtime alerts
8. Fatigue management alerts
9. Auto-close shifts
10. Concurrent session detection
11. Shift pattern violation detection
12. Idle detection

Configure and monitor in **Home -> Setup -> Cron jobs**.

---

## API Reference

### Authentication
Use a Dolibarr user API key:
- `Authorization: Bearer <api_key>`
- or `X-API-Key: <api_key>`

### Endpoints
- `GET /custom/clockwork/api/active.php`
- `GET /custom/clockwork/api/shifts.php?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`
- `GET /custom/clockwork/api/totals.php?date_from=YYYY-MM-DD&date_to=YYYY-MM-DD`

Session-authenticated AJAX endpoints:
- `POST /custom/clockwork/ajax/heartbeat.php`
- `GET /custom/clockwork/ajax/notifications.php?action=list`
- `POST /custom/clockwork/ajax/notifications.php?action=mark_all_read`

---

## IP Restriction

Use CIDR ranges in setup (`CLOCKWORK_ALLOWED_IPS`), for example:
- `10.0.0.0/8`
- `192.168.1.0/24`
- `10.0.0.0/8,192.168.1.0/24`

If enabled, clock actions are blocked outside allowed ranges.

---

## Database and Migrations

Base tables:
- `llx_clockwork_shift`
- `llx_clockwork_break`

Upgrades included in this implementation:
- `sql/llx_clockwork_upgrade_v3.sql`
- `sql/llx_clockwork_upgrade_v4.sql`
- `sql/llx_clockwork_upgrade_v5.sql`

Notable additions:
- Exclusion support tables
- Payslip mapping and PDF file metadata
- Shift activity and idle notification tracking fields
- In-app notifications table

---

## Troubleshooting

### Payslip PDF not generated
- Check compliance generation completed for the month.
- Verify template file constants if custom paths are set.
- Verify writable document/temp directories.
- Check records in `llx_clockwork_payslip_map`.

### Idle alerts not triggering
- Verify heartbeat endpoint calls from My Time page.
- Confirm open shifts have `last_activity_at` updates.
- Ensure Idle cron job is enabled.
- Check `CLOCKWORK_NOTIFY_IDLE` and webhook constants.

### Webhooks not sending
- Verify URLs and outbound HTTPS connectivity.
- Test with setup test actions.
- Check Dolibarr/PHP logs for request errors.

### Cron not running
- Verify Dolibarr cron execution (`cron_run_jobs.php`).
- Confirm jobs are enabled in Dolibarr cron admin.

---

## Development

### Module structure
```
clockwork/
|-- admin/
|   `-- setup.php
|-- ajax/
|   |-- heartbeat.php
|   `-- notifications.php
|-- api/
|   |-- _common.php
|   |-- active.php
|   |-- shifts.php
|   `-- totals.php
|-- class/
|   |-- actions_clockwork.class.php
|   |-- clockworkbreak.class.php
|   |-- clockworkcompliance.class.php
|   |-- clockworkcron.class.php
|   |-- clockworknotification.class.php
|   `-- clockworkshift.class.php
|-- clockwork/
|   |-- clock.php
|   |-- exclusions.php
|   |-- hr_shifts.php
|   |-- hr_totals.php
|   |-- monthly_compliance.php
|   |-- my_payslips.php
|   `-- payslip_download.php
|-- core/
|   |-- modules/modClockwork.class.php
|   `-- triggers/interface_99_modClockwork_ClockworkTriggers.class.php
|-- img/
|   |-- pwa-icon-192.png
|   `-- pwa-icon-512.png
|-- langs/en_US/clockwork.lang
|-- lib/
|   |-- clockwork.lib.php
|   |-- clockwork_email.lib.php
|   |-- clockwork_ipcheck.lib.php
|   `-- clockwork_webhook.lib.php
|-- pwa/
|   |-- manifest.json
|   |-- offline.html
|   `-- service-worker.js
|-- sql/
|   |-- llx_clockwork_break.sql
|   |-- llx_clockwork_shift.sql
|   |-- llx_clockwork_upgrade_v2.sql
|   |-- llx_clockwork_upgrade_v3.sql
|   |-- llx_clockwork_upgrade_v4.sql
|   `-- llx_clockwork_upgrade_v5.sql
|-- templates/
|   |-- payslip_email_template.html
|   `-- payslip_pdf_template.html
`-- README.md
```

---

## License

GPL-3.0.

## Author

Clockwork module for Dolibarr time tracking and payroll workflows.
