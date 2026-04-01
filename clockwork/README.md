# Clockwork — Dolibarr Time Tracking Module

[![License](https://img.shields.io/badge/license-GPL--3.0-blue.svg)](https://www.gnu.org/licenses/gpl-3.0.html)
[![Dolibarr](https://img.shields.io/badge/dolibarr-17%2B-green.svg)](https://www.dolibarr.org/)

Clockwork is a comprehensive time tracking module for Dolibarr ERP. It provides clock-in/clock-out functionality with multiple breaks, real-time notifications via Discord/Slack/Teams, HR reporting, and a token-authenticated JSON API for integrations.

---

## Table of Contents

- [Features](#features)
- [Install](#install)
- [Configuration](#configuration)
- [User Guide](#user-guide)
- [Notifications & Alerts](#notifications--alerts)
- [Cron Jobs](#cron-jobs)
- [API Reference](#api-reference)
- [IP Restriction](#ip-restriction)
- [Troubleshooting](#troubleshooting)
- [Development](#development)

---

## Features

### Core Time Tracking
- **Clock In/Out** — Simple one-click time tracking for employees
- **Break Management** — Start/end multiple breaks during a shift
- **Shift Notes** — Add notes to shifts for context
- **Real-time Status** — See who's currently clocked in, on break, or offline

### HR Management
- **Shift Dashboard** — View all employee shifts with filtering
- **Totals Report** — Aggregated worked/break/net hours per employee
- **Date Range Filtering** — Filter by custom date ranges
- **Daily Breakdown** — Detailed per-day breakdown in reports

### Smart Alerts & Notifications
- **Missed Clock-In Detection** — Alert when employees forget to clock in
- **Overwork Detection** — Alert when working continuously without breaks
- **Logout Reminders** — Remind employees to clock out at end of day
- **Maximum Shift Length** — Alert when shifts exceed configured duration
- **Escalating Break Reminders** — Progressive reminders during long shifts
- **Weekly Overtime** — Alert when weekly hours exceed threshold
- **Fatigue Management** — Detect insufficient rest between shifts
- **Auto-Close Shifts** — Automatically close forgotten open shifts
- **Concurrent Session Detection** — Detect multiple active shifts
- **Shift Pattern Violations** — Detect clock-ins outside expected patterns

### Network Security
- **IP Restriction** — Restrict clock-in/out to specific IP ranges (VPN/office)
- **Network Change Monitoring** — Alert when IP changes during a shift
- **Location Detection** — GeoIP-based location verification

### Integrations
- **Discord Webhooks** — Rich embed notifications
- **Slack Webhooks** — Native Slack message format
- **Microsoft Teams** — Adaptive card notifications
- **Browser Notifications** — Native browser push notifications
- **REST API** — JSON API for MCP/LLM integrations

---

## Install

### Release ZIP
1. Download the release ZIP from [GitHub Releases](https://github.com/glimz/dollibar-clockwork/releases).
2. Extract `clockwork/` into your Dolibarr instance at `htdocs/custom/clockwork/`.
3. In Dolibarr: **Home → Setup → Modules/Applications**, enable **Clockwork**.
4. Configure the module (setup page) and create a dedicated API user if you plan to use the MCP API.

### From Source
```bash
cd htdocs/custom/
git clone https://github.com/glimz/dollibar-clockwork.git clockwork
```
Then enable the module in Dolibarr admin.

### Database
The module creates the following tables on installation:
- `llx_clockwork_shift` — Main shift records
- `llx_clockwork_break` — Break records within shifts

---

## Configuration

### Module Settings
Navigate to **Clockwork → Setup** to configure:

#### General Settings
| Setting | Description | Default |
|---------|-------------|---------|
| Allow CORS for Clockwork API | Enable cross-origin requests for API | Disabled |
| Allow API token in query string | Allow `api_key` query param (not recommended) | Disabled |

#### Notification Settings
| Setting | Description | Default |
|---------|-------------|---------|
| Enable clock-in alerts | Send webhook on clock-in | Enabled |
| Enable break alerts | Send webhook on break start/end | Enabled |
| Enable missed clock-in alerts | Alert when user hasn't clocked in by cutoff | Enabled |
| Enable weekly summary | Send weekly worked hours summary | Enabled |
| Exclude logins (denylist) | Comma-separated logins to exclude | `admin,user.api` |

#### Webhook URLs
| Setting | Description |
|---------|-------------|
| Default webhook URL | Fallback URL if per-type webhook is empty |
| Clock-in webhook URL | Override for clock-in alerts |
| Break webhook URL | Override for break alerts |
| Missed clock-in webhook URL | Override for missed clock-in alerts |
| Weekly summary webhook URL | Override for weekly summaries |
| Overwork webhook URL | Override for overwork alerts |
| Logout reminder webhook URL | Override for logout reminders |
| Network change webhook URL | Override for network change alerts |
| Maximum shift webhook URL | Override for max shift alerts |
| Weekly overtime webhook URL | Override for overtime alerts |
| Slack webhook URL | Slack Incoming Webhook URL |
| Microsoft Teams webhook URL | Teams Incoming Webhook URL |

#### Missed Clock-In Policy
| Setting | Description | Default |
|---------|-------------|---------|
| Timezone | Timezone for cutoff checks | `Africa/Lagos` |
| Cutoff time (HH:MM) | Time after which to check for missed clock-ins | `09:30` |
| Grace period (minutes) | Additional minutes after cutoff | `0` |
| Weekdays to check | 1=Mon..7=Sun, comma-separated | `1,2,3,4,5` |
| Skip if on approved leave | Respect Dolibarr holiday module | Enabled |
| Skip public holidays | Skip on national holidays | Enabled |
| Public holiday country code | Override company country code | (empty) |

#### Weekly Summary Schedule
| Setting | Description | Default |
|---------|-------------|---------|
| Timezone | Timezone for scheduling | `Africa/Lagos` |
| Day of week | 1=Mon..7=Sun | `1` (Monday) |
| Time (HH:MM) | Time to send summary | `09:35` |

#### IP Restriction
| Setting | Description | Default |
|---------|-------------|---------|
| Allowed IP ranges | CIDR notation, comma-separated | (empty = allow all) |
| Monitor network changes | Alert on IP change during shift | Enabled |

#### Overwork Detection
| Setting | Description | Default |
|---------|-------------|---------|
| Enable overwork alerts | Alert on continuous work without breaks | Enabled |
| Overwork threshold (hours) | Hours of continuous work before alert | `4` |

#### Logout Reminder
| Setting | Description | Default |
|---------|-------------|---------|
| Enable logout reminders | Remind users to clock out | Enabled |
| Reminder cutoff time (HH:MM) | Time after which to send reminders | `23:00` |
| Reminder timezone | Timezone for cutoff | `Africa/Lagos` |

#### Maximum Shift Length
| Setting | Description | Default |
|---------|-------------|---------|
| Enable maximum shift alerts | Alert when shift exceeds duration | Enabled |
| Maximum shift duration (hours) | Max allowed shift length | `12` |

#### Escalating Break Reminders
| Setting | Description | Default |
|---------|-------------|---------|
| Enable escalating break reminders | Progressive reminders | Enabled |
| Break reminder intervals (hours) | Comma-separated hours | `2,3,3.5,4` |

#### Weekly Overtime
| Setting | Description | Default |
|---------|-------------|---------|
| Enable weekly overtime alerts | Alert on weekly overtime | Enabled |
| Weekly overtime threshold (hours) | Weekly hour limit | `48` |

#### Fatigue Management
| Setting | Description | Default |
|---------|-------------|---------|
| Enable fatigue management alerts | Alert on insufficient rest | Enabled |
| Minimum rest between shifts (hours) | Required rest between shifts | `8` |

#### Auto-Close Shifts
| Setting | Description | Default |
|---------|-------------|---------|
| Enable automatic shift closure | Auto-close long shifts | Enabled |
| Auto-close after (hours) | Max shift duration before auto-close | `16` |

#### Concurrent Session Detection
| Setting | Description | Default |
|---------|-------------|---------|
| Enable concurrent session detection | Alert on multiple active shifts | Enabled |

#### Shift Pattern Violations
| Setting | Description | Default |
|---------|-------------|---------|
| Enable shift pattern violation detection | Alert on pattern violations | Disabled |
| Grace period (minutes) | Allowed deviation from pattern | `15` |

---

## User Guide

### Clock In/Out
1. Navigate to **HRM → My Time**
2. Click **Clock In** to start your shift
3. Add an optional note describing your work
4. Click **Clock Out** when finished

### Taking Breaks
1. While clocked in, click **Start Break**
2. Add an optional note (e.g., "Lunch")
3. Click **End Break** to resume work

### Viewing Your Time
- **My Time** page shows your current status
- View today's shift details with clock-in time, breaks, and worked hours

### HR View (Managers)
- **HRM → Shifts (HR)** — List all employee shifts
- **HRM → Totals (HR)** — Aggregated hours report
- Filter by date range and employee

---

## Notifications & Alerts

### Notification Types

| Type | Trigger | Color |
|------|---------|-------|
| Clock-In | User clocks in | Green |
| Break Start | User starts break | Blue |
| Break End | User ends break | Blue |
| Missed Clock-In | User hasn't clocked in by cutoff | Orange |
| Overwork | Continuous work without break | Red |
| Logout Reminder | User hasn't clocked out by cutoff | Yellow |
| Network Change | IP changed during shift | Purple |
| Max Shift Exceeded | Shift exceeds max duration | Red |
| Break Reminder | Progressive break reminders | Yellow |
| Weekly Overtime | Weekly hours exceed threshold | Orange |
| Fatigue Alert | Insufficient rest between shifts | Orange |
| Auto-Close | Shift auto-closed | Red |
| Concurrent Session | Multiple active shifts | Purple |
| Shift Pattern Violation | Clock-in outside pattern | Orange |

### Browser Notifications
Enable browser notifications in the module settings. Click "Enable Notifications" on the My Time page to grant permission.

### Webhook Platforms
Clockwork supports three webhook platforms simultaneously:
- **Discord** — Rich embed messages with fields
- **Slack** — Block Kit messages
- **Microsoft Teams** — Adaptive cards

Configure each platform's webhook URL in the module settings. Notifications are sent to all configured platforms.

---

## Cron Jobs

Clockwork includes 11 automated cron jobs. Configure them in **Home → Setup → Cron jobs**.

| Job | Frequency | Description |
|-----|-----------|-------------|
| Missed Clock-In | Every 5 min | Checks for users who haven't clocked in |
| Weekly Summary | Every hour | Sends weekly hours summary (scheduled) |
| Overwork Detection | Every 5 min | Alerts on continuous work without breaks |
| Logout Reminder | Every 5 min | Reminds users to clock out (scheduled) |
| Max Shift Length | Every 5 min | Alerts when shifts exceed max duration |
| Escalating Break Reminders | Every 5 min | Progressive break reminders |
| Weekly Overtime | Every hour | Alerts on weekly overtime |
| Fatigue Management | Every hour | Alerts on insufficient rest between shifts |
| Auto-Close Shifts | Every hour | Automatically closes long shifts |
| Concurrent Sessions | Every 5 min | Detects multiple active shifts |
| Shift Pattern Violations | Every hour | Detects clock-ins outside patterns |

### Setting Up Cron Execution
1. Ensure Dolibarr cron is configured (`cron/cron_run_jobs.php`)
2. Add to system crontab:
```bash
*/5 * * * * /usr/bin/php /path/to/dolibarr/htdocs/cron/cron_run_jobs.php
```

---

## API Reference

### Authentication
All API endpoints require authentication using a Dolibarr user's `api_key`:

```
Authorization: Bearer <api_key>
```

Or via header:
```
X-API-Key: <api_key>
```

The API user must have Clockwork read rights (permission `500201` or `500205`).

### Endpoints

#### Get Current Status
```
GET /custom/clockwork/api/clockwork.php?action=status
```

#### Clock In
```
POST /custom/clockwork/api/clockwork.php?action=clockin
{
  "note": "Starting morning shift"
}
```

#### Clock Out
```
POST /custom/clockwork/api/clockwork.php?action=clockout
```

#### Start Break
```
POST /custom/clockwork/api/clockwork.php?action=breakstart
{
  "note": "Lunch break"
}
```

#### End Break
```
POST /custom/clockwork/api/clockwork.php?action=breakend
```

#### Get Shifts
```
GET /custom/clockwork/api/clockwork.php?action=shifts&date_from=2024-01-01&date_to=2024-01-31
```

#### Get Totals
```
GET /custom/clockwork/api/clockwork.php?action=totals&date_from=2024-01-01&date_to=2024-01-31
```

---

## IP Restriction

### Configuring Allowed IPs
1. Go to **Clockwork → Setup**
2. Enter allowed IP ranges in CIDR notation
3. Separate multiple ranges with commas

Examples:
- `10.0.0.0/8` — Entire private network
- `192.168.1.0/24` — Specific subnet
- `10.0.0.0/8,192.168.1.0/24` — Multiple ranges

### How It Works
- When a user attempts to clock in/out, their IP is checked
- If the IP is not in an allowed range, access is denied
- The user sees their detected IP and location
- Network changes during active shifts trigger alerts

---

## Troubleshooting

### Common Issues

#### "Access Denied" on Clock-In
- Check your IP is in the allowed ranges
- Verify VPN/office network connection
- Contact IT administrator

#### Webhook Notifications Not Sending
- Verify webhook URL is correct
- Check webhook is accessible from server
- Test with "Send test webhook" button in setup
- Check server firewall allows outbound HTTPS

#### Cron Jobs Not Running
- Verify Dolibarr cron is configured
- Check cron job status in **Home → Setup → Cron jobs**
- Ensure module is enabled
- Check cron user has sufficient permissions

#### Disabled Users Still Getting Alerts
- Fixed: All cron methods now filter by `u.statut = 1`
- If you see alerts for disabled users, they may have open shifts from before being disabled
- Close their shifts manually or wait for auto-close

#### Timezone Issues
- Verify timezone settings in module configuration
- Check server timezone matches expected timezone
- All scheduling uses configured timezone, not server timezone

---

## Development

### Module Structure
```
clockwork/
├── admin/
│   └── setup.php              # Module configuration page
├── class/
│   ├── actions_clockwork.class.php  # Hook handlers
│   └── clockworkcron.class.php      # Cron job methods
├── clockwork/
│   ├── clock.php              # Employee clock-in page
│   ├── hr_shifts.php          # HR shift list
│   └── hr_totals.php          # HR totals report
├── core/
│   └── modules/
│       └── modClockwork.class.php   # Module descriptor
├── cron/
│   └── clockwork_cron.php     # Cron entry point
├── langs/
│   └── en_US/
│       └── clockwork.lang     # Language file
├── lib/
│   ├── clockwork.lib.php      # Core functions
│   ├── clockwork_webhook.lib.php   # Webhook functions
│   └── clockwork_ipcheck.lib.php   # IP checking functions
├── sql/
│   └── llx_clockwork_shift.sql     # Database schema
└── README.md
```

### Adding New Notification Types
1. Define constant in `lib/clockwork_webhook.lib.php`:
```php
define('CLOCKWORK_NOTIFY_TYPE_MYTYPE', 'mytype');
```
2. Add webhook URL constant in `modClockwork.class.php`:
```php
$this->const[N] = array('CLOCKWORK_WEBHOOK_MYTYPE', 'chaine', '', '...', 0);
```
3. Add notify function in `lib/clockwork_webhook.lib.php`
4. Add language strings in `langs/en_US/clockwork.lang`

### Branches
- `main` — Stable release
- `feature/ip-restriction-overwork-alerts` — Current development

---

## License

GPL-3.0 — See [COPYING](COPYING) for details.

## Author

Developed for Dolibarr ERP time tracking needs.