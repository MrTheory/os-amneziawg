# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

OPNsense plugin for AmneziaWG — an obfuscated WireGuard fork for bypassing DPI blocking. Provides a native VPN client with GUI configuration, selective routing, diagnostics, and watchdog auto-recovery. Runs on OPNsense 25.x/26.x (FreeBSD 14.x amd64).

All documentation and user-facing text is in **Russian**.

## Installation & Deployment

There is no build system — the plugin is deployed via filesystem copy. The single entry point is `install.sh`:

```bash
# Install/upgrade on OPNsense
sh install.sh

# Uninstall
sh install.sh uninstall
```

The installer handles: pkg integrity checks, FreeBSD quarterly repo setup, `amnezia-kmod`/`amnezia-tools` installation, kernel module ABI verification, file deployment to `/usr/local/opnsense/`, and configd restart.

## Operational Commands (on OPNsense)

```bash
configctl amneziawg version          # Verify installed version
configctl amneziawg start/stop/restart/reconfigure
awg show                             # Tunnel status (like wg show)
tail -f /var/log/amneziawg.log       # Plugin log
```

All configd actions are defined in `plugin/service/conf/actions.d/actions_amneziawg.conf` — 12 actions mapping to PHP scripts.

## Architecture

### Request Flow

```
OPNsense Web UI (Volt template)
  → API Controllers (PHP, AJAX POST)
    → configd RPC (configctl amneziawg <action>)
      → PHP scripts in plugin/scripts/AmneziaWG/
        → System tools: awg-quick, awg, kldload, ifconfig
```

### Key Layers

**API Controllers** (`plugin/mvc/app/controllers/OPNsense/AmneziaWG/Api/`):
- `InstanceController.php` — tunnel config CRUD, keypair generation; intercepts private key reads/writes (SEC-1)
- `ServiceController.php` — start/stop/restart/status via configd
- `ImportController.php` — `.conf` file parser (POST-only, SEC-3)
- `GeneralController.php` — enabled/watchdog flags

**Core Engine** (`plugin/scripts/AmneziaWG/amneziawg-service-control.php`, ~590 lines):
- `awg_up()` / `awg_down()` — write config, call awg-quick, manage sentinel daemon
- `awg_exec_timeout()` — proc_open with 30s timeout to prevent hangs
- flock protection against concurrent operations (auto-kill stale locks after 120s)
- Actions dispatched by CLI argument: start, stop, restart, reconfigure, status, version, gen_keypair, validate

**Models** (`plugin/mvc/app/models/OPNsense/AmneziaWG/`):
- `Instance.xml` — 25 fields: network config, obfuscation params (Jc, Jmin, Jmax, S1, S2, H1-H4), peer settings
- `General.xml` — 2 flags: enabled, watchdog
- Data stored in OPNsense `config.xml` under `//OPNsense/amneziawg/`

**Web UI** (`plugin/mvc/app/views/OPNsense/AmneziaWG/general.volt`):
- Tabs: Instance (config form), General (flags), Diagnostics (ifstats), Log (tail)
- Status polling every 10s, AJAX form save/load

### Critical Design Decisions

1. **Flat single-tunnel model** — one tunnel (awg0-awg99), no array/multi-instance nesting. Covers client use-case.

2. **Private key sentinel** — `config.xml` stores `::file::` placeholder; real key lives in `/usr/local/etc/amnezia/private.key` (0600). Prevents key leakage in OPNsense config backups.

3. **Table = off** — `awg-quick` does NOT manage routing tables. Routing is handled by OPNsense Firewall Rules + Gateways for selective routing control.

4. **PID sentinel daemon** — `awg-quick up` exits after creating the interface. A long-lived `daemon -p` process is spawned so OPNsense Dashboard can detect running service via PID file (`/var/run/amneziawg.pid`).

5. **Stopped flag** — `/tmp/amneziawg_stopped` prevents watchdog from restarting a manually stopped tunnel.

### Security Tags in Code

Comments tagged `SEC-1`, `SEC-2`, `SEC-3` mark security-critical sections. `HIGH-1`, `HIGH-4` mark high-priority validations. `IMP-3/8/9/10` mark implementation robustness points. Preserve these tags when editing.

## File Conventions

- PHP scripts in `plugin/scripts/` are standalone CLI scripts invoked by configd (not web-accessible)
- Shell scripts use `/bin/sh` (FreeBSD sh, not bash)
- Log entries use format: `[YYYY-MM-DD HH:MM:SS] [LEVEL] message`
- All API mutation endpoints must be POST-only
- OPNsense MVC patterns: XML model defines fields/validation, PHP controller handles API, Volt template renders UI
