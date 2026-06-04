#!/bin/sh
# AmneziaWG OPNsense Plugin Installer
# Obfuscated WireGuard (bypass DPI) for OPNsense 25.x / FreeBSD 14.x
#
# Usage:
#   sh install.sh            — install
#   sh install.sh uninstall  — remove

set -e
set -u

PLUGIN_VERSION="3.0.0"
PLUGIN_DIR="$(dirname "$0")/plugin"
VERSION_FILE="/usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/version.txt"

# ─────────────────────────────────────────────────────────────────────────────
# HELPERS
# ─────────────────────────────────────────────────────────────────────────────
warn() { echo "[WARN] $*" >&2; }
die()  { echo "[ERROR] $*" >&2; exit 1; }

# ─────────────────────────────────────────────────────────────────────────────
# UNINSTALL
# ─────────────────────────────────────────────────────────────────────────────
if [ "${1:-}" = "uninstall" ]; then
    echo "==> Stopping AmneziaWG..."
    /usr/local/opnsense/scripts/AmneziaWG/amneziawg-service-control.php stop 2>/dev/null || true

    # Unlock amnezia-kmod if locked (we lock it during install to prevent accidental upgrade)
    pkg unlock -qy amnezia-kmod 2>/dev/null || true

    # Offer to remove amnezia packages
    if pkg info amnezia-kmod >/dev/null 2>&1 || pkg info amnezia-tools >/dev/null 2>&1; then
        echo ""
        printf "  Remove amnezia-kmod and amnezia-tools packages? [y/N] "
        read -r _RP < /dev/tty 2>/dev/null || _RP="n"
        case "$_RP" in
            [yY]*)
                pkg delete -y amnezia-tools 2>/dev/null || true
                pkg delete -y amnezia-kmod 2>/dev/null || true
                sed -i '' '/^if_amn_load/d' /boot/loader.conf 2>/dev/null || true
                echo "[OK]  Packages removed"
                ;;
            *) echo "  Keeping packages." ;;
        esac
        echo ""
    fi

    echo "==> Removing plugin files..."
    rm -f  /usr/local/opnsense/scripts/AmneziaWG/amneziawg-service-control.php
    rm -f  /usr/local/opnsense/scripts/AmneziaWG/amneziawg-ifstats.php
    rm -f  /usr/local/opnsense/scripts/AmneziaWG/amneziawg-testconnect.php
    rm -f  /usr/local/opnsense/scripts/AmneziaWG/amneziawg-watchdog.php
    rmdir  /usr/local/opnsense/scripts/AmneziaWG 2>/dev/null || true
    rm -f  /usr/local/opnsense/service/conf/actions.d/actions_amneziawg.conf
    rm -rf /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG  # includes version.txt
    rm -f  /usr/local/etc/amnezia/private.key
    rm -f  /usr/local/etc/amnezia/*.key
    rm -f  /usr/local/etc/amnezia/*.conf
    rmdir  /usr/local/etc/amnezia 2>/dev/null || true
    rm -rf /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG
    rm -rf /usr/local/opnsense/mvc/app/views/OPNsense/AmneziaWG
    rm -f  /usr/local/etc/inc/plugins.inc.d/amneziawg.inc
    rm -f  /etc/newsyslog.conf.d/amneziawg.conf
    rm -f  /usr/local/etc/rc.syshook.d/start/50-amneziawg
    rm -f  /var/run/amneziawg_stopped.flag

    echo "==> Restarting configd..."
    service configd restart

    echo "==> Clearing cache..."
    rm -f /var/lib/php/tmp/opnsense_menu_cache.xml

    echo ""
    echo "=============================="
    echo "  AmneziaWG plugin removed."
    echo "=============================="
    echo "Refresh browser with Ctrl+F5."
    exit 0
fi

# ─────────────────────────────────────────────────────────────────────────────
# INSTALL
# ─────────────────────────────────────────────────────────────────────────────

# ─────────────────────────────────────────────────────────────────────────────
# VERSION CHECK & CONFIRMATION
# ─────────────────────────────────────────────────────────────────────────────
CURRENT_VERSION="not installed"
if [ -f "$VERSION_FILE" ]; then
    CURRENT_VERSION=$(cat "$VERSION_FILE" 2>/dev/null || echo "unknown")
fi

echo "============================================================"
echo "  os-amneziawg plugin installer"
echo "============================================================"
echo ""
echo "  Current version : ${CURRENT_VERSION}"
echo "  New version     : ${PLUGIN_VERSION}"
echo ""

if [ "$CURRENT_VERSION" = "$PLUGIN_VERSION" ]; then
    echo "  Version ${PLUGIN_VERSION} is already installed."
    printf "  Reinstall? [y/N] "
    read -r _CONFIRM < /dev/tty 2>/dev/null || _CONFIRM="n"
    case "$_CONFIRM" in
        [yY]*) ;;
        *) echo "  Installation cancelled."; exit 0 ;;
    esac
elif [ "$CURRENT_VERSION" != "not installed" ]; then
    printf "  Upgrade from ${CURRENT_VERSION} to ${PLUGIN_VERSION}? [Y/n] "
    read -r _CONFIRM < /dev/tty 2>/dev/null || _CONFIRM="y"
    case "$_CONFIRM" in
        [nN]*) echo "  Installation cancelled."; exit 0 ;;
        *) ;;
    esac
else
    printf "  Install version ${PLUGIN_VERSION}? [Y/n] "
    read -r _CONFIRM < /dev/tty 2>/dev/null || _CONFIRM="y"
    case "$_CONFIRM" in
        [nN]*) echo "  Installation cancelled."; exit 0 ;;
        *) ;;
    esac
fi

echo ""

# ─────────────────────────────────────────────────────────────────────────────
# FreeBSD QUARTERLY REPO HELPER
#
# Пакеты amnezia-kmod и amnezia-tools отсутствуют в репозитории OPNsense,
# но есть в FreeBSD quarterly. Создаём временный конфиг репо для pkg,
# устанавливаем пакеты и удаляем конфиг. URL не содержит хэшей — pkg сам
# резолвит нужную версию по имени пакета.
# ─────────────────────────────────────────────────────────────────────────────
FREEBSD_REPO_CONF="/usr/local/etc/pkg/repos/freebsd-quarterly.conf"
FREEBSD_REPO_CREATED=0

setup_freebsd_repo() {
    if [ -f "$FREEBSD_REPO_CONF" ]; then
        echo "[OK]  FreeBSD quarterly repo already configured"
        return 0
    fi
    install -d /usr/local/etc/pkg/repos
    cat > "$FREEBSD_REPO_CONF" << 'REPOEOF'
FreeBSD-quarterly: {
    url: "pkg+http://pkg.FreeBSD.org/${ABI}/quarterly",
    mirror_type: "srv",
    signature_type: "fingerprints",
    fingerprints: "/usr/share/keys/pkg",
    enabled: yes
}
REPOEOF
    FREEBSD_REPO_CREATED=1
    echo "[OK]  Temporary FreeBSD quarterly repo configured"
}

cleanup_freebsd_repo() {
    if [ "$FREEBSD_REPO_CREATED" = "1" ]; then
        rm -f "$FREEBSD_REPO_CONF"
        echo "[OK]  Temporary FreeBSD repo config removed"
    fi
}

# Check if amnezia-kmod ABI matches the running kernel (prevents kernel panics)
check_kernel_compat() {
    KERN_VER=$(uname -r | sed 's/\([0-9]*\.[0-9]*\).*/\1/')
    DRY_OUT=$(pkg install -n -r FreeBSD-quarterly amnezia-kmod 2>&1 || true)
    if echo "$DRY_OUT" | grep -qi "ABI.*change\|wrong ABI\|incompatible\|not compatible"; then
        echo ""
        warn "ABI MISMATCH: amnezia-kmod may be built for a different FreeBSD version!"
        warn "Running kernel: FreeBSD $KERN_VER"
        warn "Installing incompatible kmod may cause kernel panics and reboots."
        echo ""
        printf "  Continue anyway? [y/N] "
        read -r _KM < /dev/tty 2>/dev/null || _KM="n"
        case "$_KM" in [yY]*) return 0 ;; *) return 1 ;; esac
    fi
    return 0
}

# ─────────────────────────────────────────────────────────────────────────────
# PKG INTEGRITY PRE-CHECK
# If pkg was previously corrupted (e.g. upgraded from quarterly repo),
# detect and offer automatic recovery before proceeding.
# Checks: 1) pkg info works, 2) pkg not replaced by FreeBSD quarterly version
# ─────────────────────────────────────────────────────────────────────────────
echo "==> Pre-check: Verifying pkg integrity..."

pkg_recover() {
    printf "  Attempt automatic recovery? [Y/n] "
    read -r _RECOV < /dev/tty 2>/dev/null || _RECOV="y"
    case "$_RECOV" in
        [nN]*) die "Fix pkg manually: pkg-static install -f pkg && pkg-static update -f" ;;
        *)
            echo "  Reinstalling pkg from OPNsense repo..."
            if pkg-static install -fy pkg 2>/dev/null; then
                echo "[OK]  pkg restored"
                pkg-static update -f 2>/dev/null || warn "pkg update failed"
            else
                die "Recovery failed. Run manually: pkg-static install -f pkg"
            fi
            ;;
    esac
}

PKG_NEEDS_FIX=0

# Check 1: can pkg query itself at all?
if ! pkg info pkg >/dev/null 2>&1; then
    warn "pkg appears broken (cannot query package database)."
    PKG_NEEDS_FIX=1
fi

# Check 2: was pkg replaced by a FreeBSD (non-OPNsense) version?
# On OPNsense, pkg should come from the "OPNsense" repo. If it came from
# "FreeBSD" or "FreeBSD-quarterly", it's incompatible and causes segfaults.
if [ "$PKG_NEEDS_FIX" = "0" ]; then
    _PKG_REPO=$(pkg-static query '%R' pkg 2>/dev/null || echo "")
    if [ -n "$_PKG_REPO" ] && ! echo "$_PKG_REPO" | grep -qi "OPNsense"; then
        _PKG_VER=$(pkg-static query '%v' pkg 2>/dev/null || echo "unknown")
        warn "pkg v${_PKG_VER} was installed from '${_PKG_REPO}' repo instead of OPNsense!"
        warn "This is known to cause segfaults in pkg update."
        PKG_NEEDS_FIX=1
    fi
fi

if [ "$PKG_NEEDS_FIX" = "1" ]; then
    echo ""
    pkg_recover
else
    echo "[OK]  pkg is healthy"
fi

echo ""
echo "==> Step 1: Checking AmneziaWG packages..."

NEED_KMOD=0
NEED_TOOLS=0

if [ ! -x /usr/local/bin/awg ]; then
    NEED_TOOLS=1
fi
if ! kldstat -q -m if_amn 2>/dev/null; then
    NEED_KMOD=1
fi

if [ "$NEED_KMOD" = "1" ] || [ "$NEED_TOOLS" = "1" ]; then
    echo ""
    echo "  Missing packages detected:"
    [ "$NEED_TOOLS" = "1" ] && echo "    - amnezia-tools (awg, awg-quick)"
    [ "$NEED_KMOD" = "1" ]  && echo "    - amnezia-kmod  (if_amn kernel module)"
    echo ""
    printf "  Install from FreeBSD quarterly repo? [Y/n] "
    read -r _REPLY < /dev/tty 2>/dev/null || _REPLY="y"
    case "$_REPLY" in
        [nN]*)
            echo ""
            warn "Skipping package install. Plugin will be installed but"
            warn "AmneziaWG will NOT start until packages are in place."
            echo ""
            echo "  Manual install:"
            echo "    pkg install -r FreeBSD-quarterly amnezia-kmod amnezia-tools"
            echo "    kldload if_amn"
            echo "    echo 'if_amn_load=\"YES\"' >> /boot/loader.conf"
            ;;
        *)
            setup_freebsd_repo

            # Lock pkg to prevent self-upgrade from quarterly repo (causes segfault)
            PKG_LOCKED_BY_US=0
            if pkg lock -qy pkg 2>/dev/null; then
                PKG_LOCKED_BY_US=1
                echo "[OK]  pkg locked (preventing self-upgrade from quarterly)"
            fi

            pkg update -r FreeBSD-quarterly 2>/dev/null || warn "pkg update failed — trying install anyway"

            if [ "$NEED_KMOD" = "1" ]; then
                # Check kernel compatibility before installing kmod
                KMOD_COMPAT=1
                if ! check_kernel_compat; then
                    warn "Skipping amnezia-kmod install due to ABI mismatch."
                    KMOD_COMPAT=0
                fi

                if [ "$KMOD_COMPAT" = "1" ]; then
                    echo "  Installing amnezia-kmod..."
                    if pkg install -y -r FreeBSD-quarterly amnezia-kmod 2>/dev/null; then
                        echo "[OK]  amnezia-kmod installed"
                        # Lock kmod to prevent accidental upgrade by future pkg operations
                        pkg lock -qy amnezia-kmod 2>/dev/null && \
                            echo "[OK]  amnezia-kmod locked (prevents accidental upgrade)" || true
                        kldload if_amn 2>/dev/null || true
                        grep -q 'if_amn_load' /boot/loader.conf 2>/dev/null || \
                            echo 'if_amn_load="YES"' >> /boot/loader.conf
                    else
                        warn "Failed to install amnezia-kmod via pkg."
                        echo "       Try manually: pkg add <URL from pkg.freebsd.org>"
                    fi
                fi
            fi

            if [ "$NEED_TOOLS" = "1" ]; then
                echo "  Installing amnezia-tools..."
                if pkg install -y -r FreeBSD-quarterly amnezia-tools 2>/dev/null; then
                    echo "[OK]  amnezia-tools installed"
                else
                    warn "Failed to install amnezia-tools via pkg."
                    echo "       Try manually: pkg add <URL from pkg.freebsd.org>"
                fi
            fi

            cleanup_freebsd_repo

            # Unlock pkg if we locked it
            if [ "$PKG_LOCKED_BY_US" = "1" ]; then
                pkg unlock -qy pkg 2>/dev/null || true
            fi

            # Post-install: verify pkg wasn't corrupted despite the lock
            if ! pkg info pkg >/dev/null 2>&1; then
                warn "pkg was corrupted during installation — restoring..."
                pkg-static install -fy pkg 2>/dev/null || true
                pkg-static update -f 2>/dev/null || true
                if pkg info pkg >/dev/null 2>&1; then
                    echo "[OK]  pkg restored automatically"
                else
                    warn "Could not auto-restore pkg. Run: pkg-static install -f pkg"
                fi
            fi
            ;;
    esac
else
    echo "[OK]  awg: $(awg --version 2>/dev/null || echo 'installed')"
    echo "[OK]  if_amn kernel module loaded"
    # Ensure kmod is locked even if it was installed by a previous version of this script
    if pkg info amnezia-kmod >/dev/null 2>&1; then
        _KMOD_LOCKED=$(pkg query '%k' amnezia-kmod 2>/dev/null || echo "0")
        if [ "$_KMOD_LOCKED" != "1" ]; then
            pkg lock -qy amnezia-kmod 2>/dev/null && \
                echo "[OK]  amnezia-kmod locked (prevents accidental upgrade)" || true
        fi
    fi
fi

# Final binary check
BINARIES_OK=1
[ ! -x /usr/local/bin/awg ] && BINARIES_OK=0
kldstat -q -m if_amn 2>/dev/null || BINARIES_OK=0

if [ "$BINARIES_OK" = "0" ]; then
    echo ""
    warn "One or more binaries/modules are still missing."
    warn "Plugin will be installed but AmneziaWG will NOT start."
fi

# ─────────────────────────────────────────────────────────────────────────────
# DETECT EXISTING CONFIG (MED-8)
# Multi-instance (3.0.0): checks both the new collection path and the legacy
# flat node. A legacy node is migrated automatically by the model migration
# (M2_0_0) on first model access after install — no manual import needed.
# ─────────────────────────────────────────────────────────────────────────────
echo ""
echo "==> Step 2: Checking for existing AmneziaWG configuration..."

CONFIG_XML_HAS_AWG=0

if [ -x /usr/local/bin/php ]; then
    CONFIG_XML_HAS_AWG=$(/usr/local/bin/php -r '
        set_include_path("/usr/local/etc/inc" . PATH_SEPARATOR . get_include_path());
        @include_once("config.inc");
        try {
            $cfg = OPNsense\Core\Config::getInstance()->object();
            // New multi-instance path
            $new = isset($cfg->OPNsense->amneziawg->instances->instance) ? "1" : "0";
            // Legacy flat node (pre-3.0.0) — migrated automatically by M2_0_0
            $legacy = (string)($cfg->OPNsense->amneziawg->instance->peer_public_key ?? "");
            echo ($new === "1" || $legacy !== "") ? "1" : "0";
        } catch (Exception $e) {
            echo "0";
        }
    ' 2>/dev/null || echo "0")
fi

if [ "$CONFIG_XML_HAS_AWG" = "1" ]; then
    echo "[OK]  Existing configuration found in config.xml — will not overwrite."
    echo "      A pre-3.0.0 single-tunnel config is migrated automatically on first GUI access."
else
    # Stray .conf files are reported only — import via GUI 'Import .conf' dialog
    for _f in /usr/local/etc/amnezia/awg*.conf; do
        if [ -f "$_f" ]; then
            echo "  Found tunnel config file: $_f"
            echo "  Use the GUI 'Import .conf' dialog to add it as a tunnel instance."
        fi
    done
    echo "[OK]  No existing configuration found (clean install)."
fi

echo ""
echo "==> Step 3: Installing plugin files..."

install -d /usr/local/opnsense/scripts/AmneziaWG
install -m 0755 "$PLUGIN_DIR/scripts/AmneziaWG/amneziawg-service-control.php" \
                /usr/local/opnsense/scripts/AmneziaWG/
install -m 0755 "$PLUGIN_DIR/scripts/AmneziaWG/amneziawg-ifstats.php" \
                /usr/local/opnsense/scripts/AmneziaWG/
install -m 0755 "$PLUGIN_DIR/scripts/AmneziaWG/amneziawg-testconnect.php" \
                /usr/local/opnsense/scripts/AmneziaWG/
install -m 0755 "$PLUGIN_DIR/scripts/AmneziaWG/amneziawg-watchdog.php" \
                /usr/local/opnsense/scripts/AmneziaWG/

install -m 0644 "$PLUGIN_DIR/service/conf/actions.d/actions_amneziawg.conf" \
                /usr/local/opnsense/service/conf/actions.d/

install -d /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/Menu
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/AmneziaWG/General.xml" \
                /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/AmneziaWG/General.php" \
                /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/AmneziaWG/Instance.xml" \
                /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/AmneziaWG/Instance.php" \
                /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/AmneziaWG/Menu/Menu.xml" \
                /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/Menu/

# Multi-instance (3.0.0): model migration from the legacy flat layout
install -d /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/Migrations
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/AmneziaWG/Migrations/M2_0_0.php" \
                /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/Migrations/

# IMP-6: install ACL definitions for API endpoints
install -d /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/ACL
install -m 0644 "$PLUGIN_DIR/mvc/app/models/OPNsense/AmneziaWG/ACL/ACL.xml" \
                /usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/ACL/

# Write version file
echo "$PLUGIN_VERSION" > "$VERSION_FILE"
chmod 0644 "$VERSION_FILE"

install -d /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/Api
install -d /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/forms
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/AmneziaWG/IndexController.php" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/AmneziaWG/Api/GeneralController.php" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/Api/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/AmneziaWG/Api/InstanceController.php" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/Api/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/AmneziaWG/Api/ServiceController.php" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/Api/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/AmneziaWG/Api/ImportController.php" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/Api/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/AmneziaWG/forms/general.xml" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/forms/
install -m 0644 "$PLUGIN_DIR/mvc/app/controllers/OPNsense/AmneziaWG/forms/dialogInstance.xml" \
                /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/forms/
# Remove the pre-3.0.0 single-instance form if present
rm -f /usr/local/opnsense/mvc/app/controllers/OPNsense/AmneziaWG/forms/instance.xml

install -d /usr/local/opnsense/mvc/app/views/OPNsense/AmneziaWG
install -m 0644 "$PLUGIN_DIR/mvc/app/views/OPNsense/AmneziaWG/general.volt" \
                /usr/local/opnsense/mvc/app/views/OPNsense/AmneziaWG/

install -m 0644 "$PLUGIN_DIR/etc/inc/plugins.inc.d/amneziawg.inc" \
                /usr/local/etc/inc/plugins.inc.d/

# SEC-6: install newsyslog config for log rotation (max 1MB, 5 archives, gzip)
install -d /etc/newsyslog.conf.d
install -m 0644 "$PLUGIN_DIR/etc/newsyslog.conf.d/amneziawg.conf" \
                /etc/newsyslog.conf.d/

install -d -m 0700 /usr/local/etc/amnezia

# MED-2: install rc.syshook for autostart on boot
install -d /usr/local/etc/rc.syshook.d/start
install -m 0755 "$PLUGIN_DIR/etc/rc.syshook.d/start/50-amneziawg" \
                /usr/local/etc/rc.syshook.d/start/

echo "[OK]  Plugin files installed."

# ─────────────────────────────────────────────────────────────────────────────
# PORT CHECK (LOW-5)
# Warn if any configured listen port is already in use by another service.
# Multi-instance: iterates all instances (new path) + legacy flat node.
# ─────────────────────────────────────────────────────────────────────────────
if [ -x /usr/local/bin/php ]; then
    _LISTEN_PORTS=$(/usr/local/bin/php -r '
        set_include_path("/usr/local/etc/inc" . PATH_SEPARATOR . get_include_path());
        @include_once("config.inc");
        try {
            $cfg = OPNsense\Core\Config::getInstance()->object();
            $ports = [];
            $container = $cfg->OPNsense->amneziawg->instances ?? null;
            if (isset($container) && isset($container->instance)) {
                foreach ($container->instance as $inst) {
                    $p = (string)($inst->listen_port ?? "");
                    if ($p !== "") $ports[] = $p;
                }
            }
            $legacy = (string)($cfg->OPNsense->amneziawg->instance->listen_port ?? "");
            if ($legacy !== "") $ports[] = $legacy;
            echo implode(" ", array_unique($ports));
        } catch (Exception $e) { echo ""; }
    ' 2>/dev/null || echo "")
    for _LISTEN_PORT in $_LISTEN_PORTS; do
        if [ "$_LISTEN_PORT" -gt 0 ] 2>/dev/null; then
            if sockstat -l -P udp 2>/dev/null | grep -q ":${_LISTEN_PORT} " 2>/dev/null; then
                echo ""
                warn "UDP port ${_LISTEN_PORT} is already in use!"
                warn "AmneziaWG may fail to start. Check: sockstat -l -P udp | grep ${_LISTEN_PORT}"
            fi
        fi
    done
fi

echo ""
echo "==> Step 4: Restarting configd..."
service configd restart

echo ""
echo "==> Step 5: Clearing cache..."
rm -f /var/lib/php/tmp/opnsense_menu_cache.xml
rm -f /var/lib/php/tmp/PHP_errors.log

echo ""
echo "============================================================"
echo "  os-amneziawg v${PLUGIN_VERSION} installed!"
echo "============================================================"
echo ""
echo "  Check version:  configctl amneziawg version"
echo ""
echo "  Quick start:"
echo "  1. Refresh browser (Ctrl+F5) → VPN → AmneziaWG"
echo "  2. Tunnels tab → add a tunnel (+) or use 'Import .conf'"
echo "  3. General tab → check 'Enable AmneziaWG'"
echo "  4. Click Apply"
echo ""
echo "  Selective routing (route only specific IPs via VPN):"
echo "  5. Interfaces → Assignments → add awg0, enable it"
echo "  6. System → Gateways → Add"
echo "     Interface: AWG, Gateway IP: <tunnel peer IP>"
echo "     Far Gateway: on, Disable monitoring: on"
echo "  7. Firewall → Aliases → Add (list IPs/networks for VPN)"
echo "  8. Firewall → Rules → LAN → Add"
echo "     Destination: <your alias>, Gateway: AWG_GW"
echo ""
echo "  To uninstall: sh install.sh uninstall"
echo ""
