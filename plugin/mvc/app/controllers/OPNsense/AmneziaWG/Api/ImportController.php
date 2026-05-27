<?php

namespace OPNsense\AmneziaWG\Api;

use OPNsense\Base\ApiControllerBase;

class ImportController extends ApiControllerBase
{
    public function parseAction()
    {
        // SEC-3 fix: only accept POST requests to prevent CSRF via GET
        if (!$this->request->isPost()) {
            return ['status' => 'error', 'message' => 'Method not allowed'];
        }

        $rawConfig = $this->request->getPost('config', 'string', '');
        if ($rawConfig === '') {
            // Also accept JSON body POSTs
            $body = @json_decode(file_get_contents('php://input'), true);
            if (!is_array($body)) {
                $body = [];
            }
            $rawConfig = $body['config'] ?? '';
        }

        if (empty(trim($rawConfig))) {
            return ['status' => 'error', 'message' => 'No configuration provided'];
        }

        $data           = $this->parseConf($rawConfig);
        $data['status'] = 'ok';
        return $data;
    }

    private function parseConf(string $raw): array
    {
        $data = [
            'private_key' => '', 'address' => '', 'dns' => '', 'mtu' => '',
            'jc' => '', 'jmin' => '', 'jmax' => '',
            's1' => '', 's2' => '', 's3' => '', 's4' => '',
            'h1' => '', 'h2' => '', 'h3' => '', 'h4' => '',
            'i1' => '', 'i2' => '', 'i3' => '', 'i4' => '', 'i5' => '',
            'peer_public_key' => '', 'peer_preshared_key' => '',
            'peer_endpoint' => '', 'peer_allowed_ips' => '',
            'peer_persistent_keepalive' => '',
        ];

        $section = '';
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if ($line === '[Interface]') {
                $section = 'interface';
                continue;
            }
            if ($line === '[Peer]') {
                $section = 'peer';
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $k = strtolower($key);

            if ($section === 'interface') {
                $map = [
                    'privatekey' => 'private_key', 'address' => 'address',
                    'dns' => 'dns', 'mtu' => 'mtu',
                    'jc' => 'jc', 'jmin' => 'jmin', 'jmax' => 'jmax',
                    's1' => 's1', 's2' => 's2', 's3' => 's3', 's4' => 's4',
                    'h1' => 'h1', 'h2' => 'h2', 'h3' => 'h3', 'h4' => 'h4',
                    'i1' => 'i1', 'i2' => 'i2', 'i3' => 'i3', 'i4' => 'i4', 'i5' => 'i5',
                ];
                if (isset($map[$k])) {
                    // I1-I5 CPS tags contain angle brackets (e.g. <b 0xd1><r 50>)
                    // which get double-encoded by Phalcon's 'string' POST filter —
                    // decode twice to restore raw tag syntax.
                    $data[$map[$k]] = in_array($map[$k], ['i1','i2','i3','i4','i5'], true)
                        ? html_entity_decode(html_entity_decode($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                        : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
            } elseif ($section === 'peer') {
                $map = [
                    'publickey'           => 'peer_public_key',
                    'presharedkey'        => 'peer_preshared_key',
                    'endpoint'            => 'peer_endpoint',
                    'allowedips'          => 'peer_allowed_ips',
                    'persistentkeepalive' => 'peer_persistent_keepalive',
                ];
                if (isset($map[$k])) {
                    $data[$map[$k]] = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
            }
        }
        return $data;
    }
}
