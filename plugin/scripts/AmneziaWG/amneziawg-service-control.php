#!/usr/local/bin/php
<?php

// IMP-9: use absolute path instead of relying on PHP include_path
require_once('/usr/local/etc/inc/config.inc');

define('AWG_CONF_DIR', '/usr/local/etc/amnezia');
define('AWG_BIN',      '/usr/local/bin/awg');
define('AWG_QUICK',    '/usr/local/bin/awg-quick');
define('AWG_PID_FILE', '/var/run/amneziawg.pid');

// IMP-8: check that required binaries exist before any operation
function awg_check_binaries(): bool
{
    foreach ([AWG_BIN, AWG_QUICK] as $bin) {
        if (!file_exists($bin) || !is_executable($bin)) {
            awg_log('ERROR: required binary not found or not executable: ' . $bin);
            return false;
        }
    }
    return true;
}

// Check that if_amn kernel module is loaded; attempt kldload if not
function awg_check_kmod(): bool
{
    exec('/sbin/kldstat -q -m if_amn 2>/dev/null', $out, $rc);
    if ($rc !== 0) {
        awg_log('WARNING: if_amn kernel module not loaded, attempting kldload...');
        exec('/sbin/kldload if_amn 2>&1', $loadOut, $loadRc);
        if ($loadRc !== 0) {
            awg_log('ERROR: failed to load if_amn kernel module: ' . implode(' ', $loadOut));
            return false;
        }
        awg_log('if_amn kernel module loaded successfully');
    }
    return true;
}

define('AWG_PRIVKEY_SENTINEL', '::file::');
define('AWG_VERSION_FILE', '/usr/local/opnsense/mvc/app/models/OPNsense/AmneziaWG/version.txt');
define('AWG_STOPPED_FLAG', '/var/run/amneziawg_stopped.flag');

// Anti-injection guard for interface tokens passed via configd parameters
function awg_valid_iface(string $tok): bool
{
    return preg_match('/^awg\d{1,2}$/', $tok) === 1;
}

// Per-instance stopped flag: set by stop_instance, cleared by start_instance.
// Watchdog skips flagged interfaces so a per-row Stop in the grid sticks.
// $iface must pass awg_valid_iface() before calling.
function awg_instance_stopped_flag(string $iface): string
{
    return '/var/run/amneziawg_stopped_' . $iface . '.flag';
}

// Service-level actions (start/restart/reconfigure/stop) reset per-row stops:
// they either bring every enabled tunnel up or take the whole service down.
function awg_clear_instance_stopped_flags(): void
{
    foreach (glob('/var/run/amneziawg_stopped_awg*.flag') ?: [] as $flag) {
        @unlink($flag);
    }
}

function awg_get_instances(): array
{
    $config = OPNsense\Core\Config::getInstance()->object();
    $container = $config->OPNsense->amneziawg->instances ?? null;
    if (!isset($container) || !isset($container->instance)) {
        return [];
    }

    $result = [];
    foreach ($container->instance as $inst) {
        if ((string)($inst->enabled ?? '0') !== '1') {
            continue;
        }
        // R5: raw SimpleXML access — uuid is a node attribute set by ArrayField
        $uuid  = (string)($inst->attributes()['uuid'] ?? '');
        $ifnum = !empty((string)($inst->interface_number ?? '')) ? (int)(string)$inst->interface_number : 0;

        // SEC-1: read private key from protected per-uuid file if sentinel is stored
        $privKeyRaw = (string)($inst->private_key ?? '');
        if ($privKeyRaw === AWG_PRIVKEY_SENTINEL) {
            $keyFile = AWG_CONF_DIR . '/' . $uuid . '.key';
            if ($uuid === '' || !file_exists($keyFile)) {
                awg_log('ERROR: private key file not found: ' . $keyFile . ' — skipping awg' . $ifnum);
                continue;
            }
            $privKeyRaw = trim(file_get_contents($keyFile));
        }

        $result[] = [
            'uuid'                      => $uuid,
            'interface'                 => 'awg' . $ifnum,
            'private_key'               => $privKeyRaw,
            'address'                   => (string)($inst->address                   ?? ''),
            'listen_port'               => (string)($inst->listen_port               ?? ''),
            'dns'                       => (string)($inst->dns                       ?? ''),
            'mtu'                       => (string)($inst->mtu                       ?? ''),
            'jc'                        => (string)($inst->jc                        ?? ''),
            'jmin'                      => (string)($inst->jmin                      ?? ''),
            'jmax'                      => (string)($inst->jmax                      ?? ''),
            's1'                        => (string)($inst->s1                        ?? ''),
            's2'                        => (string)($inst->s2                        ?? ''),
            's3'                        => (string)($inst->s3                        ?? ''),
            's4'                        => (string)($inst->s4                        ?? ''),
            'h1'                        => (string)($inst->h1                        ?? ''),
            'h2'                        => (string)($inst->h2                        ?? ''),
            'h3'                        => (string)($inst->h3                        ?? ''),
            'h4'                        => (string)($inst->h4                        ?? ''),
            // I1-I5 CPS tags contain angle brackets — double-decode HTML entities from config.xml
            'i1'                        => html_entity_decode(html_entity_decode((string)($inst->i1 ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'i2'                        => html_entity_decode(html_entity_decode((string)($inst->i2 ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'i3'                        => html_entity_decode(html_entity_decode((string)($inst->i3 ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'i4'                        => html_entity_decode(html_entity_decode((string)($inst->i4 ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'i5'                        => html_entity_decode(html_entity_decode((string)($inst->i5 ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            'peer_public_key'           => (string)($inst->peer_public_key           ?? ''),
            'peer_preshared_key'        => (string)($inst->peer_preshared_key        ?? ''),
            'peer_endpoint'             => (string)($inst->peer_endpoint             ?? ''),
            'peer_allowed_ips'          => (string)($inst->peer_allowed_ips          ?? ''),
            'peer_persistent_keepalive' => (string)($inst->peer_persistent_keepalive ?? ''),
        ];
    }
    return $result;
}

function awg_sanitize(string $value): string
{
    return str_replace(["\n", "\r"], '', $value);
}

function awg_write_conf(array $inst): string
{
    $lines = ['[Interface]'];
    $lines[] = 'PrivateKey = ' . awg_sanitize($inst['private_key']);
    $lines[] = 'Address = '    . awg_sanitize($inst['address']);
    $lines[] = 'Table = off';

    if (!empty($inst['listen_port'])) {
        $lines[] = 'ListenPort = ' . awg_sanitize($inst['listen_port']);
    }
    if (!empty($inst['dns'])) {
        $lines[] = 'DNS = ' . awg_sanitize($inst['dns']);
    }
    if (!empty($inst['mtu'])) {
        $lines[] = 'MTU = ' . awg_sanitize($inst['mtu']);
    }
    // Obfuscation parameters
    $obf = ['jc'=>'Jc','jmin'=>'Jmin','jmax'=>'Jmax','s1'=>'S1','s2'=>'S2','s3'=>'S3','s4'=>'S4',
            'h1'=>'H1','h2'=>'H2','h3'=>'H3','h4'=>'H4',
            'i1'=>'I1','i2'=>'I2','i3'=>'I3','i4'=>'I4','i5'=>'I5'];
    // Validate H1-H4: must be >= 5 and ranges must not overlap
    // Supports single values (e.g. "12345") and ranges (e.g. "12345-67890")
    $hRanges = [];
    foreach (['h1','h2','h3','h4'] as $hk) {
        $raw = trim($inst[$hk] ?? '');
        if ($raw === '') {
            continue;
        }
        if (!preg_match('/^\d{1,10}(-\d{1,10})?$/', $raw)) {
            awg_log("WARNING: {$hk}={$raw} has invalid format. Skipping {$hk}.");
            $inst[$hk] = '';
            continue;
        }
        $parts = explode('-', $raw, 2);
        $low  = (float)$parts[0];
        $high = isset($parts[1]) ? (float)$parts[1] : $low;
        if ($low < 5 || $high < 5 || $low > 4294967295 || $high > 4294967295 || $high < $low) {
            awg_log("WARNING: {$hk}={$raw} is invalid (values must be 5-4294967295, start <= end). Skipping {$hk}.");
            $inst[$hk] = '';
            continue;
        }
        // Check overlap with previously validated H ranges
        $overlap = false;
        foreach ($hRanges as $prev => [$pLow, $pHigh]) {
            if ($low <= $pHigh && $pLow <= $high) {
                awg_log("WARNING: {$hk}={$raw} overlaps with {$prev}. Skipping {$hk}.");
                $inst[$hk] = '';
                $overlap = true;
                break;
            }
        }
        if (!$overlap) {
            $hRanges[$hk] = [$low, $high];
        }
    }
    foreach ($obf as $k => $label) {
        if (!empty($inst[$k])) {
            $lines[] = "$label = " . awg_sanitize($inst[$k]);
        }
    }

    $lines[] = '';
    $lines[] = '[Peer]';
    $lines[] = 'PublicKey = ' . awg_sanitize($inst['peer_public_key']);
    if (!empty($inst['peer_preshared_key'])) {
        $lines[] = 'PresharedKey = ' . awg_sanitize($inst['peer_preshared_key']);
    }
    $lines[] = 'Endpoint = '    . awg_sanitize($inst['peer_endpoint']);
    $lines[] = 'AllowedIPs = '  . awg_sanitize($inst['peer_allowed_ips']);
    if (!empty($inst['peer_persistent_keepalive'])) {
        $lines[] = 'PersistentKeepalive = ' . awg_sanitize($inst['peer_persistent_keepalive']);
    }

    $conf = implode("\n", $lines) . "\n";
    $path = AWG_CONF_DIR . '/' . $inst['interface'] . '.conf';

    if (!is_dir(AWG_CONF_DIR)) {
        if (!mkdir(AWG_CONF_DIR, 0700, true)) {
            awg_log('ERROR: failed to create config directory: ' . AWG_CONF_DIR);
            return '';
        }
    }
    // HIGH-4: check return value of file_put_contents
    if (file_put_contents($path, $conf) === false) {
        awg_log('ERROR: failed to write config file: ' . $path);
        return '';
    }
    chmod($path, 0600);
    return $path;
}

function awg_log(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    @file_put_contents('/var/log/amneziawg.log', "[$ts] $msg\n", FILE_APPEND | LOCK_EX);
}

/**
 * Run a command with a timeout. Returns [output_string, return_code].
 * If the command exceeds $timeout seconds, it is killed and rc=124.
 */
function awg_exec_timeout(string $cmd, int $timeout = 30): array
{
    awg_log('EXEC: ' . $cmd);
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        awg_log('EXEC ERROR: proc_open failed for: ' . $cmd);
        return ['proc_open failed', 1];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start  = time();
    $killed = false;

    while (true) {
        $status = proc_get_status($proc);
        if (!$status['running']) {
            // Process finished — read remaining output
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            break;
        }
        if ((time() - $start) >= $timeout) {
            // Timeout — kill the process tree
            awg_log('EXEC TIMEOUT: ' . $timeout . 's exceeded, killing pid=' . $status['pid']);
            // Kill process group
            @exec('kill -9 -' . $status['pid'] . ' 2>/dev/null');
            @proc_terminate($proc, 9);
            $killed = true;
            break;
        }
        // Read available output
        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        usleep(100000); // 100ms
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $rc = $killed ? 124 : ($status['exitcode'] ?? proc_close($proc));
    if ($killed) {
        proc_close($proc);
    }

    $output = trim($stdout . ($stderr ? "\n" . $stderr : ''));
    awg_log('EXEC DONE: rc=' . $rc . ' | ' . substr($output, 0, 200));
    return [$output, $rc];
}

// Multi-instance: sentinel management moved to awg_start_all()/awg_stop_all()
// (one service-level sentinel; per-tunnel start/stop here would thrash it).
function awg_up(array $inst): bool
{
    $path = awg_write_conf($inst);
    if ($path === '') {
        awg_log('ERROR: failed to write config for ' . $inst['interface'] . ', skipping up');
        return false;
    }
    [$output, $rc] = awg_exec_timeout(AWG_QUICK . ' up ' . escapeshellarg($path) . ' 2>&1', 30);
    awg_log('up ' . $inst['interface'] . ' rc=' . $rc . ' | ' . $output);
    if ($rc === 0) {
        // awg-quick recreates the interface, wiping OPNsense state tied to it
        // (gateway monitor host-routes, dpinger). Fire rc.newwanip so the system
        // reapplies routes and restarts dpinger for assigned interfaces — same
        // as the core os-wireguard plugin does. Detached: best-effort, must not
        // block multi-tunnel startup; a no-op for unassigned interfaces.
        exec('/usr/local/sbin/configctl -d interface newip '
            . escapeshellarg($inst['interface']) . ' >/dev/null 2>&1');
    }
    return $rc === 0;
}

/**
 * Start a sentinel process via daemon(8) so OPNsense Dashboard can track
 * service status through the PID file. AmneziaWG is a kernel module with
 * no long-running daemon, so we spawn a lightweight "sleep infinity" process.
 * daemon(8) writes the child PID to AWG_PID_FILE automatically.
 */
function awg_start_sentinel(): void
{
    // Kill any existing sentinel first
    awg_stop_sentinel();
    // FreeBSD sleep does not support "infinity" — use large value (~31 years)
    // Redirect to /dev/null: PHP exec() blocks until ALL pipe writers close,
    // and daemon's child (sleep) inherits the pipe, causing exec() to hang forever.
    exec('/usr/sbin/daemon -p ' . escapeshellarg(AWG_PID_FILE) . ' /bin/sleep 999999999 >/dev/null 2>&1', $out, $rc);
    if ($rc !== 0) {
        awg_log('WARNING: failed to start sentinel daemon rc=' . $rc . ': ' . implode(' ', $out));
    } else {
        // Wait briefly for daemon(8) to write PID file
        usleep(200000); // 200ms
        if (file_exists(AWG_PID_FILE)) {
            awg_log('Sentinel started, pid=' . trim(file_get_contents(AWG_PID_FILE)));
        } else {
            awg_log('WARNING: sentinel started but PID file not created');
        }
    }
}

/**
 * Stop sentinel process and remove PID file.
 */
function awg_stop_sentinel(): void
{
    if (file_exists(AWG_PID_FILE)) {
        $pid = (int)trim(@file_get_contents(AWG_PID_FILE));
        if ($pid > 0 && awg_pid_alive($pid)) {
            exec('kill ' . $pid . ' 2>/dev/null');
            usleep(100000); // 100ms
            if (awg_pid_alive($pid)) {
                exec('kill -9 ' . $pid . ' 2>/dev/null');
            }
        }
        @unlink(AWG_PID_FILE);
    }
}

function awg_down(array $inst): void
{
    $path = AWG_CONF_DIR . '/' . $inst['interface'] . '.conf';
    if (file_exists($path)) {
        [$output, $rc] = awg_exec_timeout(AWG_QUICK . ' down ' . escapeshellarg($path) . ' 2>&1', 30);
        awg_log('down ' . $inst['interface'] . ' rc=' . $rc . ' | ' . $output);
    }
}

// Count of currently existing awg* interfaces (kernel view)
function awg_count_up(): int
{
    $ifOut = [];
    exec('/sbin/ifconfig -l', $ifOut);
    $count = 0;
    foreach (explode(' ', trim($ifOut[0] ?? '')) as $iface) {
        if (preg_match('/^awg\d+$/', $iface)) {
            $count++;
        }
    }
    return $count;
}

// BUG-3: awg_is_up() was declared but never used — removed.

$action = $argv[1] ?? 'status';
awg_log('ACTION: ' . $action . ' (pid=' . getmypid() . ')');

// Catch fatal errors and log them
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        awg_log('PHP FATAL: ' . $err['message'] . ' in ' . $err['file'] . ':' . $err['line']);
    }
});

// Helper: check if a PID is alive (works even without posix extension)
function awg_pid_alive(int $pid): bool
{
    if ($pid <= 0) {
        return false;
    }
    if (function_exists('posix_kill')) {
        return posix_kill($pid, 0);
    }
    // Fallback: use kill -0 via shell
    exec('kill -0 ' . (int)$pid . ' 2>/dev/null', $out, $rc);
    return $rc === 0;
}

// IMP-10: flock() protection against concurrent reconfigure/start/stop/restart
// If lock is held longer than this, force-acquire (awg-quick hung)
define('AWG_LOCK_TIMEOUT', 120);

$lockActions = ['start', 'stop', 'restart', 'reconfigure', 'start_instance', 'stop_instance', 'sentinel_repair'];
$lockFp = null;
$lockFile = '/var/run/amneziawg.lock';
if (in_array($action, $lockActions, true)) {
    awg_log('LOCK: acquiring for ' . $action);

    // Check lock age before attempting flock — if too old, kill holder and remove
    if (file_exists($lockFile)) {
        $lockAge = time() - filemtime($lockFile);
        if ($lockAge > AWG_LOCK_TIMEOUT) {
            $stalePid = (int)trim(@file_get_contents($lockFile));
            awg_log('LOCK: file is ' . $lockAge . 's old (limit=' . AWG_LOCK_TIMEOUT . 's), holder pid=' . $stalePid);
            if ($stalePid > 0 && awg_pid_alive($stalePid)) {
                awg_log('LOCK: killing hung process pid=' . $stalePid);
                exec('kill -9 ' . (int)$stalePid . ' 2>/dev/null');
                usleep(200000); // 200ms for process to die
            }
            @unlink($lockFile);
        }
    }

    $lockFp = fopen($lockFile, 'c');
    if ($lockFp === false) {
        awg_log('ERROR: cannot open lock file');
        echo "ERROR: cannot open lock file\n";
        exit(1);
    }
    chmod($lockFile, 0600);

    if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
        $stalePid = (int)trim(file_get_contents($lockFile));
        awg_log('LOCK: held by pid=' . $stalePid . ', checking alive...');

        if (awg_pid_alive($stalePid)) {
            // Check lock file age — if old enough, force-kill even if alive
            $lockAge = time() - filemtime($lockFile);
            if ($lockAge > AWG_LOCK_TIMEOUT) {
                awg_log('LOCK: holder pid=' . $stalePid . ' alive but lock is ' . $lockAge . 's old, force-killing');
                exec('kill -9 ' . (int)$stalePid . ' 2>/dev/null');
                usleep(200000);
            } else {
                awg_log('SKIP: another instance (pid=' . $stalePid . ') running for ' . $lockAge . 's (action=' . $action . ')');
                echo "OK\n";
                fclose($lockFp);
                exit(0);
            }
        }

        awg_log('LOCK: stale from dead pid=' . $stalePid . ', forcing re-acquire');
        fclose($lockFp);
        @unlink($lockFile);
        $lockFp = fopen($lockFile, 'c');
        if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
            awg_log('ERROR: cannot re-acquire lock after stale cleanup');
            echo "ERROR: cannot acquire lock\n";
            exit(1);
        }
        chmod($lockFile, 0600);
    }

    // Touch mtime + write PID so timeout detection works
    ftruncate($lockFp, 0);
    fwrite($lockFp, (string)getmypid());
    fflush($lockFp);
    touch($lockFile);
    awg_log('LOCK: acquired for ' . $action);
}

// Helper: bring down all awg interfaces
function awg_stop_all(): void
{
    awg_log('awg_stop_all: starting');
    $ifOut = [];
    exec('/sbin/ifconfig -l', $ifOut);
    $existing = explode(' ', trim($ifOut[0] ?? ''));
    awg_log('awg_stop_all: interfaces=' . implode(',', $existing));
    foreach ($existing as $iface) {
        if (preg_match('/^awg\d+$/', $iface)) {
            $conf = AWG_CONF_DIR . '/' . $iface . '.conf';
            [$output, $rc] = awg_exec_timeout(AWG_QUICK . ' down ' . escapeshellarg($conf) . ' 2>&1', 30);
            awg_log('down ' . $iface . ' rc=' . $rc . ' | ' . $output);
        }
    }
    // SEC-1: runtime confs embed the private key. They are derived artifacts
    // (regenerated by awg_start_all from config.xml + <uuid>.key), so sweep
    // them after teardown — otherwise a deleted instance leaves an orphaned
    // awgN.conf with its key on disk forever.
    foreach (glob(AWG_CONF_DIR . '/awg*.conf') ?: [] as $conf) {
        @unlink($conf);
        awg_log('awg_stop_all: removed runtime conf ' . basename($conf));
    }
    awg_stop_sentinel();
    awg_log('awg_stop_all: done');
}

// Helper: bring up configured instances
function awg_start_all(): bool
{
    $instances = awg_get_instances();
    if (empty($instances)) {
        awg_log('WARNING: no enabled instances to start');
        awg_stop_sentinel();
        return false;
    }
    $upCount = 0;
    foreach ($instances as $inst) {
        if (awg_up($inst)) {
            $upCount++;
        }
    }
    // Service-level sentinel: running = at least one tunnel up
    if ($upCount > 0) {
        awg_start_sentinel();
    } else {
        awg_stop_sentinel();
    }
    return $upCount > 0;
}

switch ($action) {
    case 'start':
        if (!awg_check_binaries()) {
            echo "ERROR: awg/awg-quick binaries not found. Install amnezia-tools package.\n";
            break;
        }
        if (!awg_check_kmod()) {
            echo "ERROR: if_amn kernel module not available. Install/reinstall amnezia-kmod.\n";
            break;
        }
        // Remove stopped flags so watchdog can monitor
        if (file_exists(AWG_STOPPED_FLAG)) {
            unlink(AWG_STOPPED_FLAG);
        }
        awg_clear_instance_stopped_flags();
        awg_start_all();
        echo "OK\n";
        break;

    case 'stop':
        // Set stopped flag so watchdog doesn't auto-restart
        if (file_put_contents(AWG_STOPPED_FLAG, (string)getmypid()) === false) {
            awg_log('WARNING: failed to write stopped flag');
        }
        // Service-level flag covers everything — drop stale per-instance flags
        awg_clear_instance_stopped_flags();
        awg_stop_all();
        echo "OK\n";
        break;

    case 'restart':
        if (!awg_check_binaries()) {
            echo "ERROR: awg/awg-quick binaries not found. Install amnezia-tools package.\n";
            break;
        }
        if (!awg_check_kmod()) {
            echo "ERROR: if_amn kernel module not available. Install/reinstall amnezia-kmod.\n";
            break;
        }
        // Remove stopped flags so watchdog can monitor
        if (file_exists(AWG_STOPPED_FLAG)) {
            unlink(AWG_STOPPED_FLAG);
        }
        awg_clear_instance_stopped_flags();
        awg_stop_all();
        awg_start_all();
        echo "OK\n";
        break;

    case 'reconfigure':
        if (!awg_check_binaries()) {
            echo "ERROR: awg/awg-quick binaries not found. Install amnezia-tools package.\n";
            break;
        }
        if (!awg_check_kmod()) {
            echo "ERROR: if_amn kernel module not available. Install/reinstall amnezia-kmod.\n";
            break;
        }
        // Apply = make runtime match config: clear manual-stop state too,
        // otherwise tunnels come up while watchdog still considers them stopped
        if (file_exists(AWG_STOPPED_FLAG)) {
            unlink(AWG_STOPPED_FLAG);
        }
        awg_clear_instance_stopped_flags();
        awg_stop_all();
        awg_start_all();
        echo "OK\n";
        break;

    case 'start_instance':
    case 'stop_instance':
        $ifaceArg = $argv[2] ?? '';
        if (!awg_valid_iface($ifaceArg)) {
            awg_log('ERROR: invalid interface token: ' . substr($ifaceArg, 0, 32));
            echo "ERROR: invalid interface\n";
            break;
        }
        if ($action === 'start_instance') {
            if (!awg_check_binaries() || !awg_check_kmod()) {
                echo "ERROR: binaries or kernel module not available\n";
                break;
            }
            $target = null;
            foreach (awg_get_instances() as $inst) {
                if ($inst['interface'] === $ifaceArg) {
                    $target = $inst;
                    break;
                }
            }
            if ($target === null) {
                echo "ERROR: no enabled instance for " . $ifaceArg . "\n";
                break;
            }
            // Manual per-row start lifts the per-instance stop
            @unlink(awg_instance_stopped_flag($ifaceArg));
            awg_up($target);
        } else {
            // Flag first so watchdog doesn't race a restart mid-teardown
            if (file_put_contents(awg_instance_stopped_flag($ifaceArg), (string)getmypid()) === false) {
                awg_log('WARNING: failed to write per-instance stopped flag for ' . $ifaceArg);
            }
            awg_down(['interface' => $ifaceArg]);
        }
        // Service-level sentinel follows the number of live tunnels
        if (awg_count_up() > 0) {
            awg_start_sentinel();
        } else {
            awg_stop_sentinel();
        }
        echo "OK\n";
        break;

    case 'sentinel_repair':
        // Re-sync the sentinel PID with the actual tunnel state without
        // touching any tunnel (used by watchdog when only the PID died).
        if (awg_count_up() > 0) {
            awg_start_sentinel();
        } else {
            awg_stop_sentinel();
        }
        echo "OK\n";
        break;

    case 'status':
        $tunnels = [];
        exec('/sbin/ifconfig -l', $out5);
        $existing = explode(' ', trim($out5[0] ?? ''));
        foreach ($existing as $iface) {
            if (preg_match('/^awg\d+$/', $iface)) {
                $awgShow = [];
                exec(AWG_BIN . ' show ' . escapeshellarg($iface) . ' 2>/dev/null', $awgShow);
                // Newest peer handshake epoch (0 = never) — feeds the grid
                // runtime-status column (BACKLOG #3)
                $hsOut = [];
                exec(AWG_BIN . ' show ' . escapeshellarg($iface) . ' latest-handshakes 2>/dev/null', $hsOut);
                $newest = 0;
                foreach ($hsOut as $line) {
                    $parts = preg_split('/\s+/', trim($line));
                    $ts = (int)($parts[1] ?? 0);
                    if ($ts > $newest) {
                        $newest = $ts;
                    }
                }
                $tunnels[] = [
                    'interface'        => $iface,
                    'up'               => true,
                    'latest_handshake' => $newest,
                    'details'          => implode("\n", $awgShow),
                ];
            }
        }
        $running = !empty($tunnels);
        echo json_encode([
            'status'  => $running ? 'ok' : 'stopped',
            'tunnels' => $tunnels,
        ]) . "\n";
        break;

    case 'version':
        $ver = file_exists(AWG_VERSION_FILE) ? trim(file_get_contents(AWG_VERSION_FILE)) : 'unknown';
        echo json_encode(['version' => $ver]) . "\n";
        break;

    case 'gen_keypair':
        // HIGH-1/CRIT-3: validate shell_exec results — awg may not be installed or may fail
        $privkeyRaw = shell_exec(AWG_BIN . ' genkey 2>/dev/null');
        if ($privkeyRaw === null || trim($privkeyRaw) === '') {
            awg_log('ERROR: awg genkey returned empty result');
            echo json_encode(['status' => 'error', 'message' => 'Failed to generate private key — is amnezia-tools installed?']) . "\n";
            break;
        }
        $privkey = trim($privkeyRaw);
        if (!preg_match('/^[A-Za-z0-9+\/]{43}=$/', $privkey)) {
            awg_log('ERROR: awg genkey returned invalid Base64: ' . substr($privkey, 0, 10) . '...');
            echo json_encode(['status' => 'error', 'message' => 'Generated private key has invalid format']) . "\n";
            break;
        }
        $pubkeyRaw = shell_exec('echo ' . escapeshellarg($privkey) . ' | ' . AWG_BIN . ' pubkey 2>/dev/null');
        if ($pubkeyRaw === null || trim($pubkeyRaw) === '') {
            awg_log('ERROR: awg pubkey returned empty result');
            echo json_encode(['status' => 'error', 'message' => 'Failed to derive public key']) . "\n";
            break;
        }
        $pubkey = trim($pubkeyRaw);
        if (!preg_match('/^[A-Za-z0-9+\/]{43}=$/', $pubkey)) {
            awg_log('ERROR: awg pubkey returned invalid Base64: ' . substr($pubkey, 0, 10) . '...');
            echo json_encode(['status' => 'error', 'message' => 'Derived public key has invalid format']) . "\n";
            break;
        }
        echo json_encode(['status' => 'ok', 'private_key' => $privkey, 'public_key' => $pubkey]) . "\n";
        break;

    case 'validate':
        if (!awg_check_binaries()) {
            echo "ERROR: awg/awg-quick binaries not found.\n";
            break;
        }
        if (!awg_check_kmod()) {
            echo "ERROR: if_amn kernel module not available. Install/reinstall amnezia-kmod.\n";
            break;
        }
        $instances = awg_get_instances();
        if (empty($instances)) {
            echo "ERROR: no enabled instances to validate\n";
            break;
        }
        $allOk = true;
        foreach ($instances as $inst) {
            $confPath = awg_write_conf($inst);
            if ($confPath === '') {
                awg_log('VALIDATE: failed to generate config for ' . $inst['interface']);
                echo "ERROR: failed to generate config\n";
                $allOk = false;
                continue;
            }
            // awg-quick strip requires filename to be <iface>.conf
            // Use the real config path (awg_write_conf already wrote it)
            [$output, $rc] = awg_exec_timeout(AWG_QUICK . ' strip ' . escapeshellarg($confPath) . ' 2>&1', 10);
            if ($rc !== 0) {
                awg_log('VALIDATE: config invalid for ' . $inst['interface'] . ': ' . $output);
                echo "ERROR: config validation failed: " . $output . "\n";
                $allOk = false;
            } else {
                awg_log('VALIDATE: config OK for ' . $inst['interface']);
            }
        }
        if ($allOk) {
            echo "OK\n";
        }
        break;

    default:
        echo "Unknown action: $action\n";
        exit(1);
}

// IMP-10: release the exclusive lock
if ($lockFp !== null) {
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
}
