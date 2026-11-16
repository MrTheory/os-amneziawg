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
            // AWG 3.x
            'header_protection_key' => '', 'content_padding_addition' => '',
            'random_trailers' => '', 'disable_cookies' => '',
            'rekey_after_time' => '', 'rekey_timeout' => '', 'reject_after_time' => '',
            'keepalive_timeout' => '', 'max_handshake_attempts' => '',
            'peer_public_key' => '', 'peer_preshared_key' => '',
            'peer_endpoint' => '', 'peer_allowed_ips' => '',
            'peer_persistent_keepalive' => '',
        ];

        // A .conf saved by some text editors starts with a UTF-8 BOM: without
        // stripping it the first line never matches '[Interface]' and the
        // whole section is lost
        if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
            $raw = substr($raw, 3);
        }

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
                    // AWG 3.x
                    'headerprotectionkey'    => 'header_protection_key',
                    'contentpaddingaddition' => 'content_padding_addition',
                    'randomtrailers'         => 'random_trailers',
                    'disablecookies'         => 'disable_cookies',
                    'rekeyaftertime'         => 'rekey_after_time',
                    'rekeytimeout'           => 'rekey_timeout',
                    'rejectaftertime'        => 'reject_after_time',
                    'keepalivetimeout'       => 'keepalive_timeout',
                    'maxhandshakeattempts'   => 'max_handshake_attempts',
                ];
                if (isset($map[$k]) && in_array($map[$k], ['random_trailers', 'disable_cookies'], true)) {
                    // Booleans: awg accepts on/off or a number; normalise to the
                    // model's 1/0 so the dialog checkbox can be set from it
                    $v = strtolower($value);
                    $data[$map[$k]] = ($v === 'on' || $v === 'true' || (ctype_digit($v) && (int)$v !== 0)) ? '1' : '0';
                } elseif (isset($map[$k])) {
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
