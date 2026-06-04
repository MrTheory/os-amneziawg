#!/usr/local/bin/php
<?php

// AmneziaWG interface statistics — returns JSON with tunnel diagnostics
// Called via: configctl amneziawg ifstats [awgN]

define('AWG_BIN', '/usr/local/bin/awg');
define('AWG_CONF_DIR', '/usr/local/etc/amnezia');

require_once('/usr/local/etc/inc/config.inc');

/**
 * Resolve target interface: optional argv token (validated) or the first
 * enabled instance from config.xml (multi-instance default).
 */
function awg_get_interface_name(?string $requested): string
{
    // Anti-injection: only accept awg<N> tokens from configd parameters
    if ($requested !== null && preg_match('/^awg\d{1,2}$/', $requested)) {
        return $requested;
    }
    $config = OPNsense\Core\Config::getInstance()->object();
    $container = $config->OPNsense->amneziawg->instances ?? null;
    if (isset($container) && isset($container->instance)) {
        foreach ($container->instance as $inst) {
            if ((string)($inst->enabled ?? '0') !== '1') {
                continue;
            }
            $ifnum = !empty((string)($inst->interface_number ?? '')) ? (int)(string)$inst->interface_number : 0;
            return 'awg' . $ifnum;
        }
    }
    return 'awg0';
}

/**
 * Check if interface exists and is running
 */
function awg_iface_status(string $iface): array
{
    $result = ['exists' => false, 'running' => false, 'ip' => '', 'mtu' => ''];
    $out = [];
    exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null', $out, $rc);
    if ($rc !== 0) {
        return $result;
    }
    $result['exists'] = true;
    $text = implode("\n", $out);

    // Check flags for RUNNING
    if (preg_match('/flags=\S+<([^>]+)>/', $text, $m)) {
        $result['running'] = strpos($m[1], 'RUNNING') !== false;
    }

    // Extract inet address
    if (preg_match('/inet (\S+)/', $text, $m)) {
        $result['ip'] = $m[1];
    }

    // Extract MTU
    if (preg_match('/mtu (\d+)/', $text, $m)) {
        $result['mtu'] = $m[1];
    }

    return $result;
}

/**
 * Parse `awg show <iface>` output
 */
function awg_show(string $iface): array
{
    $result = [
        'public_key' => '',
        'listen_port' => '',
        'peer_public_key' => '',
        'peer_endpoint' => '',
        'peer_allowed_ips' => '',
        'latest_handshake' => '',
        'transfer_rx' => '',
        'transfer_tx' => '',
        'persistent_keepalive' => '',
    ];

    $out = [];
    exec(AWG_BIN . ' show ' . escapeshellarg($iface) . ' 2>/dev/null', $out, $rc);
    if ($rc !== 0) {
        return $result;
    }

    foreach ($out as $line) {
        $line = trim($line);
        if (preg_match('/^public key:\s*(.+)$/i', $line, $m)) {
            $result['public_key'] = trim($m[1]);
        } elseif (preg_match('/^listening port:\s*(.+)$/i', $line, $m)) {
            $result['listen_port'] = trim($m[1]);
        } elseif (preg_match('/^peer:\s*(.+)$/i', $line, $m)) {
            $result['peer_public_key'] = trim($m[1]);
        } elseif (preg_match('/^endpoint:\s*(.+)$/i', $line, $m)) {
            $result['peer_endpoint'] = trim($m[1]);
        } elseif (preg_match('/^allowed ips:\s*(.+)$/i', $line, $m)) {
            $result['peer_allowed_ips'] = trim($m[1]);
        } elseif (preg_match('/^latest handshake:\s*(.+)$/i', $line, $m)) {
            $result['latest_handshake'] = trim($m[1]);
        } elseif (preg_match('/^transfer:\s*(.+)\s+received,\s*(.+)\s+sent$/i', $line, $m)) {
            $result['transfer_rx'] = trim($m[1]);
            $result['transfer_tx'] = trim($m[2]);
        } elseif (preg_match('/^persistent keepalive:\s*(.+)$/i', $line, $m)) {
            $result['persistent_keepalive'] = trim($m[1]);
        }
    }

    return $result;
}

/**
 * Get traffic counters from netstat.
 * FreeBSD 14 `netstat -ibn` Link row format:
 *   Name Mtu Network Address Ipkts Ierrs Idrop Ibytes Opkts Oerrs Obytes Coll
 *    0    1     2       3      4     5     6     7      8     9     10     11
 */
function awg_netstat(string $iface): array
{
    $result = ['packets_in' => 0, 'packets_out' => 0, 'bytes_in' => 0, 'bytes_out' => 0];
    $out = [];
    exec('/usr/bin/netstat -ibn 2>/dev/null', $out);
    foreach ($out as $line) {
        $cols = preg_split('/\s+/', trim($line));
        if (isset($cols[0]) && $cols[0] === $iface && isset($cols[2]) && strpos($cols[2], 'Link#') !== false) {
            $result['packets_in']  = (int)($cols[4] ?? 0);
            $result['bytes_in']    = (int)($cols[7] ?? 0);
            $result['packets_out'] = (int)($cols[8] ?? 0);
            $result['bytes_out']   = (int)($cols[10] ?? 0);
            break;
        }
    }
    return $result;
}

/**
 * Get tunnel uptime from the per-interface .conf mtime.
 * service-control writes <iface>.conf right before `awg-quick up`, so its
 * mtime = tunnel start time. (The shared service PID file can't serve as a
 * per-tunnel marker in the multi-instance layout.)
 */
function awg_uptime(string $iface): ?string
{
    $marker = AWG_CONF_DIR . '/' . $iface . '.conf';
    if (!file_exists($marker)) {
        return null;
    }
    $startTime = filemtime($marker);
    if ($startTime === false) {
        return null;
    }
    $seconds = time() - $startTime;
    if ($seconds < 0) {
        return null;
    }
    // Format as human-readable: Xd Xh Xm Xs
    $d = (int)($seconds / 86400);
    $h = (int)(($seconds % 86400) / 3600);
    $m = (int)(($seconds % 3600) / 60);
    $s = $seconds % 60;
    if ($d > 0) return "{$d}d {$h}h {$m}m";
    if ($h > 0) return "{$h}h {$m}m {$s}s";
    if ($m > 0) return "{$m}m {$s}s";
    return "{$s}s";
}

/**
 * Format bytes to human-readable
 */
function awg_format_bytes(int $bytes): string
{
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KiB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MiB';
    return round($bytes / 1073741824, 2) . ' GiB';
}

// ── Main ──
$iface = awg_get_interface_name($argv[1] ?? null);
$ifStatus = awg_iface_status($iface);
$awgData = awg_show($iface);
$netstat = awg_netstat($iface);
$uptime = awg_uptime($iface);

echo json_encode([
    'interface'          => $iface,
    'status'             => $ifStatus['running'] ? 'running' : ($ifStatus['exists'] ? 'down' : 'not_found'),
    'ip'                 => $ifStatus['ip'],
    'mtu'                => $ifStatus['mtu'],
    'public_key'         => $awgData['public_key'],
    'listen_port'        => $awgData['listen_port'],
    'peer_public_key'    => $awgData['peer_public_key'],
    'peer_endpoint'      => $awgData['peer_endpoint'],
    'peer_allowed_ips'   => $awgData['peer_allowed_ips'],
    'latest_handshake'   => $awgData['latest_handshake'],
    'transfer_rx'        => $awgData['transfer_rx'],
    'transfer_tx'        => $awgData['transfer_tx'],
    'packets_in'         => $netstat['packets_in'],
    'packets_out'        => $netstat['packets_out'],
    'bytes_in'           => $netstat['bytes_in'],
    'bytes_out'          => $netstat['bytes_out'],
    'bytes_in_hr'        => awg_format_bytes($netstat['bytes_in']),
    'bytes_out_hr'       => awg_format_bytes($netstat['bytes_out']),
    'uptime'             => $uptime,
]) . "\n";
