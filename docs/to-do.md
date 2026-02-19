## Clockwork (Dolibarr module) — v1 Milestone Checklist (with acceptance criteria)

### M0 — Repo + module identity
- [ ] Create public repo `dolibarr-module-clockwork`
- [x] Define module identifiers (folder/module name `clockwork`, rights class `clockwork`, unique module/rights IDs)
- [x] Add install docs for “release zip → extract to `htdocs/custom/clockwork/` → enable in Dolibarr”

**Acceptance**
- A release zip can be installed without editing core Dolibarr files.

---

### M1 — Database schema (shifts + breaks)
- [x] Table `llx_clockwork_shift`
  - fields: `fk_user`, `clockin`, `clockout`, `status`, `worked_seconds`, `break_seconds`, `net_seconds`, `note`, `ip`, `user_agent`, `entity`, `datec`, `tms`
- [x] Table `llx_clockwork_break`
  - fields: `fk_shift`, `break_start`, `break_end`, `seconds`, `note`, `entity`, `datec`, `tms`
- [x] Indexes for performance (user/date/status, open shifts, open breaks)

**Acceptance**
- Can store an open shift, multiple breaks, and compute totals deterministically.

---

### M2 — Core rules + calculations
- [x] Clock-in: prevent double clock-in (one open shift max per user)
- [x] Clock-out: requires open shift; closes open break if configured (decide behavior)
- [x] Break start: requires open shift and no open break
- [x] Break end: requires open break
- [x] Totals recompute on every state change (clockout, break end, edits)

**Acceptance**
- `net_seconds = (clockout - clockin) - sum(breaks)` and matches stored fields.

---

### M3 — Employee UI (Clockwork “My time”)
- [x] Page: current status + actions
  - Clock in / Clock out
  - Start break / End break
- [x] Show today’s shifts summary (at least last shift)
- [x] Use Dolibarr CSRF token + `$user` context, server time via `dol_now()`

**Acceptance**
- Employee can complete a full day cycle with multiple breaks without errors.

---

### M4 — HR/Admin UI
- [x] Shifts list with filters
  - date range (required), user filter, status filter
- [x] View shift details including breaks and totals
- [x] Totals report: per-employee totals for a date range
  - include daily breakdown toggle

**Acceptance**
- HR can answer: “How many hours did each employee work last week?” from UI.

---

### M5 — Permissions
- [x] Rights:
  - Employee: clock actions + read own
  - HR: read all + manage (edit/close)
  - API: read-only (for MCP)
- [x] Enforce permissions on every page/endpoint

**Acceptance**
- Non-HR cannot view other users’ attendance.

---

### M6 — MCP API (public, token-auth, rich + audit)
- [x] Auth: `Authorization: Bearer <token>` (dedicated Dolibarr user)
- [x] Endpoint: `GET /custom/clockwork/api/active.php`
  - Who is currently clocked in (+ breaks, computed “so far”)
- [x] Endpoint: `GET /custom/clockwork/api/shifts.php`
  - Requires `date_from/date_to`, supports `user_id/status/limit/offset`
- [x] Endpoint: `GET /custom/clockwork/api/totals.php`
  - Requires `date_from/date_to`, returns per-employee totals + optional daily breakdown
- [x] Audit fields included by default (`ip`, `user_agent`) + allow `include_audit=0`

**Acceptance**
- MCP can fetch (1) active users, (2) raw shifts, (3) totals for any period.

---

### M7 — Enforce VPN-only access for `crm.talenttic.com`
- [ ] Restrict `crm.talenttic.com` to VPN subnet (WireGuard, e.g. `10.8.0.0/24`)
- [ ] Confirm policy for admin exceptions (optional)
- [ ] Verify it doesn’t break internal services/Let’s Encrypt challenges

**Acceptance**
- From outside VPN: `crm.talenttic.com` blocked.
- From VPN: works normally.

---

## After v1 (optional)
### M8 — Discord webhook alerts
- [ ] Settings: Discord webhook URL, cadence/thresholds
- [ ] Notifications: missing clock-in, open shift too long, summaries

---

If you want, the next planning step is to assign owners and estimate effort per milestone (S/M/L) and define the first implementation slice (usually M1→M2→M3).
