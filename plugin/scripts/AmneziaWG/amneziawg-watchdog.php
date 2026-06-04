#!/usr/local/bin/php
<?php

// AmneziaWG watchdog — auto-restarts tunnel if it goes down
// Called via cron every minute: configctl amneziawg watchdog

require_once('/usr/local/etc/inc/config.inc');

define('AWG_PID_FILE', '/var/run/amneziawg.pid');
define('AWG_STOPPED_FLAG', '/var/run/amneziawg_stopped.flag');

function wdg_log(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    file_put_contents('/var/log/amneziawg.log', "[$ts] WATCHDOG: $msg\n", FILE_APPEND | LOCK_EX);
}

// 1. Check if watchdog is enabled in config
$config = OPNsense\Core\Config::getInstance()->object();
$watchdogEnabled = (string)($config->OPNsense->amneziawg->general->watchdog ?? '0');
if ($watchdogEnabled !== '1') {
    // Watchdog disabled — exit silently
    echo "OK\n";
    exit(0);
}

// 2. Check stopped flag — if service was intentionally stopped, don't restart
if (file_exists(AWG_STOPPED_FLAG)) {
    echo "OK\n";
    exit(0);
}

// 2.5 Check kernel module is available (prevents restart loop on kmod mismatch)
exec('/sbin/kldstat -q -m if_amn 2>/dev/null', $_, $kmodRc);
if ($kmodRc !== 0) {
    wdg_log('if_amn kernel module not loaded — cannot restart tunnel');
    echo "OK\n";
    exit(0);
}

// 3. Check if service is enabled at all
$serviceEnabled = (string)($config->OPNsense->amneziawg->general->enabled ?? '0');
if ($serviceEnabled !== '1') {
    echo "OK\n";
    exit(0);
}

// 4. Check that every enabled tunnel interface exists (multi-instance).
// Interfaces stopped per-row from the grid carry a per-instance flag
// (/var/run/amneziawg_stopped_awgN.flag) and are not watched.
$expected = [];
$container = $config->OPNsense->amneziawg->instances ?? null;
if (isset($container) && isset($container->instance)) {
    foreach ($container->instance as $inst) {
        if ((string)($inst->enabled ?? '0') !== '1') {
            continue;
        }
        $ifnum = !empty((string)($inst->interface_number ?? '')) ? (int)(string)$inst->interface_number : 0;
        $iface = 'awg' . $ifnum;
        if (file_exists('/var/run/amneziawg_stopped_' . $iface . '.flag')) {
            continue;
        }
        $expected[] = $iface;
    }
}
if (empty($expected)) {
    // No enabled instances — nothing to watch
    echo "OK\n";
    exit(0);
}

$ifOut = [];
exec('/sbin/ifconfig -l', $ifOut);
$existing = explode(' ', trim($ifOut[0] ?? ''));
$missing = array_values(array_diff($expected, $existing));
$ifaceExists = empty($missing);

// 5. Check PID file
$pidAlive = false;
if (file_exists(AWG_PID_FILE)) {
    $pid = (int)trim(file_get_contents(AWG_PID_FILE));
    if ($pid > 0) {
        if (function_exists('posix_kill')) {
            $pidAlive = posix_kill($pid, 0);
        } else {
            exec('kill -0 ' . (int)$pid . ' 2>/dev/null', $_, $rc);
            $pidAlive = ($rc === 0);
        }
    }
}

// 6. Recovery. Granular: bring up only the fallen tunnels so live ones
// keep their sessions (start_instance also repairs the sentinel PID).
// Full restart only when the sentinel died with all tunnels intact.
if (!$ifaceExists) {
    wdg_log('Tunnel(s) down: ' . implode(',', $missing) . ' — starting individually...');
    $backend = new OPNsense\Core\Backend();
    $results = [];
    foreach ($missing as $iface) {
        $output = trim((string)$backend->configdRun('amneziawg start_instance ' . $iface));
        wdg_log('start_instance ' . $iface . ' result: ' . substr($output, 0, 200));
        $results[] = $iface . ': ' . $output;
    }
    echo implode("\n", $results) . "\n";
} elseif (!$pidAlive) {
    // Tunnels are fine, only the sentinel PID died — repair it without
    // bouncing tunnels (and without resetting per-row stop flags).
    wdg_log('Sentinel PID not alive, repairing...');
    $backend = new OPNsense\Core\Backend();
    $output = trim((string)$backend->configdRun('amneziawg sentinel_repair'));
    wdg_log('Sentinel repair result: ' . substr($output, 0, 200));
    echo $output . "\n";
} else {
    echo "OK\n";
}
