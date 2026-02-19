# Dolibarr Clockwork (Monorepo)

This repository contains Dolibarr modules in a monorepo-style layout.

## Module: Clockwork
- Path: `clockwork/`
- Purpose: clock-in/clock-out time tracking with multiple breaks, HR reporting, and a JSON API for MCP/LLM integrations.

## Install (Release ZIP)
1. Download a release ZIP (e.g., `clockwork-v1.0.0.zip`) from GitHub Releases.
2. Extract the `clockwork/` folder into your Dolibarr instance at `htdocs/custom/clockwork/`.
3. In Dolibarr: **Home → Setup → Modules/Applications**, enable **Clockwork**.

## Releases
Tag a version like `v1.0.0` to trigger the GitHub Actions workflow that builds the ZIP and attaches it to the release.
