# Clockwork — Dolibarr time tracking module

Clockwork is a Dolibarr module for clock-in/clock-out time tracking with multiple breaks, HR reporting, and a token-authenticated JSON API for MCP/LLM integrations.

## Install (release ZIP)
1. Download the release ZIP from GitHub Releases.
2. Extract `clockwork/` into your Dolibarr instance at `htdocs/custom/clockwork/`.
3. In Dolibarr: **Home → Setup → Modules/Applications**, enable **Clockwork**.
4. Configure the module (setup page) and create a dedicated API user if you plan to use the MCP API.

## API authentication
Clockwork API endpoints authenticate using a Dolibarr user's `api_key`:
- Send `Authorization: Bearer <api_key>`
- The API user must have Clockwork read rights.

