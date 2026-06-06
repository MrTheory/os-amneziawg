#!/usr/local/bin/php
<?php

// AmneziaWG connection test — checks connectivity through the tunnel
// Called via: configctl amneziawg testconnect [awgN]

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
 * Seconds since the newest peer handshake on $iface, or null when no
 * handshake ever completed.
 */
function awg_handshake_age(string $iface): ?int
{
    $out = [];
    exec('/usr/local/bin/awg show ' . escapeshellarg($iface) . ' latest-handshakes 2>/dev/null', $out);
    $newest = 0;
    foreach ($out as $line) {
        $parts = preg_split('/\s+/', trim($line));
        $ts = (int)($parts[1] ?? 0);
        if ($ts > $newest) {
            $newest = $ts;
        }
    }
    return $newest === 0 ? null : max(0, time() - $newest);
}

/**
 * Interface the kernel routing table resolves $ip through ('' if none).
 */
function awg_route_via(string $ip): string
{
    $out = [];
    exec('/sbin/route -n get ' . escapeshellarg($ip) . ' 2>/dev/null', $out);
    foreach ($out as $line) {
        if (preg_match('/^\s*interface:\s*(\S+)/', $line, $m)) {
            return $m[1];
        }
    }
    return '';
}

define('TEST_HOST', 'cp.cloudflare.com');
define('HANDSHAKE_STALE_SEC', 180);

// error_code => hint: single source of truth for the GUI hint texts (English
// by policy — UI labels/README are Russian, script messages are English).
$hints = [
    'interface_down' => 'The tunnel interface is not up. Start the tunnel on the Tunnels tab and check the Log tab if it fails to come up.',
    'handshake_stale' => 'No recent handshake with the VPN server. Verify the endpoint host:port, the keys and the obfuscation parameters (Jc/Jmin/Jmax/S1-S4/H1-H4/I1-I5 must match the server), and that the server is up.',
    'dns_failure' => 'Cannot resolve the test host (' . TEST_HOST . '). Check DNS settings on the firewall (System > Settings > General or Unbound).',
    'no_route' => 'The tunnel is up and the handshake is fresh, but traffic to the test host is not routed through ' . '%IFACE%' . '. This is expected with Table=off until selective routing is configured: assign the awg interface, add a gateway and a firewall rule using it (README, sections 4-7).',
    'remote_unresponsive' => 'Routing and handshake look fine, but no HTTP response came back through the tunnel. The VPN server may not forward traffic (check NAT/forwarding on the server) or the test endpoint is filtered.',
];

$iface = awg_get_interface_name($argv[1] ?? null);

// Check interface exists
$out = [];
exec('/sbin/ifconfig ' . escapeshellarg($iface) . ' 2>/dev/null', $out, $rc);
// Configd treats non-zero exit as fatal and discards stdout, so the API/UI
// would see only "Execute error". Always exit 0 — the status field in the
// JSON payload carries success/failure semantics for the caller.
if ($rc !== 0) {
    echo json_encode([
        'status' => 'error',
        'error_code' => 'interface_down',
        'message' => 'Interface ' . $iface . ' does not exist',
        'hint' => $hints['interface_down'],
    ]) . "\n";
    exit(0);
}

// Test HTTP connectivity through the tunnel interface
// Using Cloudflare's connectivity check endpoint (returns 204)
$cmd = '/usr/local/bin/curl'
    . ' --interface ' . escapeshellarg($iface)
    . ' -s -o /dev/null'
    . ' -w "%{http_code}"'
    . ' --connect-timeout 5'
    . ' --max-time 10'
    . ' http://' . TEST_HOST . '/generate_204'
    . ' 2>/dev/null';

$httpCode = trim(shell_exec($cmd) ?? '');

if ($httpCode === '204' || $httpCode === '200') {
    echo json_encode([
        'status' => 'ok',
        'http_code' => (int)$httpCode,
        'message' => 'Connection through ' . $iface . ' is working',
    ]) . "\n";
    exit(0);
}

// Test failed — run active diagnostics to tell the user WHY (BACKLOG #2).
// Checks are ordered from the tunnel outwards: handshake → DNS → route.
$errorCode = 'remote_unresponsive';
$hsAge = awg_handshake_age($iface);
$testIp = gethostbyname(TEST_HOST);
if ($hsAge === null || $hsAge > HANDSHAKE_STALE_SEC) {
    $errorCode = 'handshake_stale';
} elseif ($testIp === TEST_HOST) {
    // gethostbyname() returns the input unchanged on resolution failure
    $errorCode = 'dns_failure';
} elseif (awg_route_via($testIp) !== $iface) {
    $errorCode = 'no_route';
}

// curl prints '000' when no HTTP response was received at all
$shortMessage = ($httpCode !== '' && $httpCode !== '000')
    ? 'Unexpected HTTP code ' . $httpCode . ' (expected 204)'
    : 'Connection failed — no response through ' . $iface;
if ($errorCode === 'handshake_stale') {
    $shortMessage = $hsAge === null
        ? 'No handshake with the server yet'
        : 'Last handshake was ' . $hsAge . 's ago (stale)';
}

echo json_encode([
    'status' => 'error',
    'error_code' => $errorCode,
    'http_code' => (int)$httpCode,
    'message' => $shortMessage,
    'hint' => str_replace('%IFACE%', $iface, $hints[$errorCode]),
]) . "\n";
