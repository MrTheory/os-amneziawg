<?php

namespace OPNsense\AmneziaWG\Api;

use OPNsense\Base\ApiMutableServiceControllerBase;
use OPNsense\Core\Backend;

class ServiceController extends ApiMutableServiceControllerBase
{
    protected static $internalServiceClass    = '\OPNsense\AmneziaWG\General';
    protected static $internalServiceTemplate = 'OPNsense/AmneziaWG';
    protected static $internalServiceEnabled  = 'enabled';
    protected static $internalServiceName     = 'amneziawg';

    private function runAction(string $command): array
    {
        $backend = new Backend();
        $output  = trim((string)$backend->configdRun($command));
        $failed  = empty($output)
                || stripos($output, 'ERROR') !== false
                || stripos($output, 'failed') !== false;
        return [
            'result'  => $failed ? 'failed' : 'ok',
            'message' => $output ?: 'No response from configd',
        ];
    }

    public function reconfigureAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed'];
        }
        $general = new \OPNsense\AmneziaWG\General();
        if ((string)$general->enabled !== '1') {
            return [
                'result' => 'ok',
                'status' => 'disabled',
                'output' => 'Service is disabled in General settings',
            ];
        }
        $res = $this->runAction('amneziawg reconfigure');
        $success = ($res['result'] === 'ok')
            && strpos($res['message'], 'OK') !== false;
        return [
            'result' => $success ? 'ok' : 'failed',
            'status' => $success ? 'ok' : 'failed',
            'output' => $res['message'],
        ];
    }

    /**
     * POST /api/amneziawg/service/start
     */
    public function startAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        return $this->runAction('amneziawg start');
    }

    /**
     * POST /api/amneziawg/service/stop
     */
    public function stopAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        return $this->runAction('amneziawg stop');
    }

    /**
     * POST /api/amneziawg/service/restart
     */
    public function restartAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        return $this->runAction('amneziawg restart');
    }

    // statusAction() is inherited from ApiMutableServiceControllerBase.

    /**
     * Resolve an instance uuid to its awgN interface name.
     * Returns '' when the uuid is malformed or unknown.
     */
    private function instanceInterface(string $uuid): string
    {
        if (!preg_match('/^[a-fA-F0-9]{8}(-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}$/', $uuid)) {
            return '';
        }
        $model = new \OPNsense\AmneziaWG\Instance();
        $node  = $model->getNodeByReference('instance.' . $uuid);
        if ($node === null) {
            return '';
        }
        $ifnum = trim((string)$node->interface_number);
        return 'awg' . ($ifnum === '' ? '0' : (string)(int)$ifnum);
    }

    /**
     * POST /api/amneziawg/service/start_instance/<uuid>
     * Brings up a single tunnel (per-row grid action).
     */
    public function startInstanceAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $iface = $this->instanceInterface((string)$uuid);
        if ($iface === '') {
            return ['result' => 'failed', 'message' => 'Unknown tunnel instance'];
        }
        return $this->runAction('amneziawg start_instance ' . $iface);
    }

    /**
     * POST /api/amneziawg/service/stop_instance/<uuid>
     * Brings down a single tunnel (per-row grid action).
     */
    public function stopInstanceAction($uuid = '')
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $iface = $this->instanceInterface((string)$uuid);
        if ($iface === '') {
            return ['result' => 'failed', 'message' => 'Unknown tunnel instance'];
        }
        return $this->runAction('amneziawg stop_instance ' . $iface);
    }

    /**
     * GET /api/amneziawg/service/version
     */
    public function versionAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('amneziawg version');
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['version' => 'unknown'];
    }

    /**
     * GET /api/amneziawg/service/tunnel_status
     */
    public function tunnelStatusAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('amneziawg status');
        $decoded = json_decode($result, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $decoded;
        }
        return ['status' => 'error', 'message' => $result];
    }

    /**
     * Sanitize an optional interface token from the request (anti-injection).
     * Returns 'awgN' or empty string.
     */
    private function requestedInterface(): string
    {
        $iface = (string)$this->request->get('interface', null, '');
        if ($iface === '') {
            $iface = (string)$this->request->getPost('interface', null, '');
        }
        return preg_match('/^awg\d{1,2}$/', $iface) ? $iface : '';
    }

    /**
     * GET /api/amneziawg/service/diagnostics[?interface=awgN]
     * Returns interface stats as JSON. Without a parameter the first
     * enabled instance is reported (multi-instance default).
     */
    public function diagnosticsAction()
    {
        $iface   = $this->requestedInterface();
        $backend = new Backend();
        $output  = trim((string)$backend->configdRun(trim('amneziawg ifstats ' . $iface)));
        if (empty($output)) {
            return ['error' => 'No response from configd'];
        }
        $data = json_decode($output, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['error' => 'Invalid JSON from ifstats'];
        }
        return $data;
    }

    /**
     * POST /api/amneziawg/service/testconnect [interface=awgN]
     * Tests connectivity through the tunnel
     */
    public function testconnectAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $iface   = $this->requestedInterface();
        $backend = new Backend();
        $output  = trim((string)$backend->configdRun(trim('amneziawg testconnect ' . $iface)));
        if (empty($output)) {
            return ['status' => 'error', 'message' => 'No response from configd'];
        }
        $data = json_decode($output, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['status' => 'error', 'message' => $output];
        }
        return $data;
    }

    /**
     * POST /api/amneziawg/service/log
     * Returns last 150 lines of amneziawg.log (POST-only: log may contain IPs)
     */
    public function logAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $backend = new Backend();
        $output  = (string)$backend->configdRun('amneziawg log');
        return ['log' => $output];
    }

    /**
     * POST /api/amneziawg/service/validate
     * Validates config without applying (dry-run)
     */
    public function validateAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed', 'message' => 'POST required'];
        }
        $res = $this->runAction('amneziawg validate');
        return $res;
    }

}
