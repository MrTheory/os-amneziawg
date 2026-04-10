<?php

namespace OPNsense\AmneziaWG\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;

class InstanceController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\AmneziaWG\Instance';
    protected static $internalModelName  = 'instance';

    // SEC-1: private key is stored in a protected file, not in config.xml.
    // The sentinel '::file::' in config.xml signals that the real key is on disk.
    // This prevents the key from appearing in config backups or xmlrpc sync.
    const PRIVKEY_FILE     = '/usr/local/etc/amnezia/private.key';
    const PRIVKEY_SENTINEL = '::file::';

    /**
     * Override getAction to mask the private_key field in API responses.
     * The UI receives a bullet placeholder so the field renders as non-empty,
     * but the actual key value is never transmitted to the browser.
     */
    public function getAction()
    {
        $result = parent::getAction();
        if (isset($result['instance']['private_key'])) {
            $stored = (string)$result['instance']['private_key'];
            // Show bullet placeholder when a key exists (sentinel or legacy plaintext)
            $result['instance']['private_key'] = $stored !== ''
                ? str_repeat(chr(0xE2) . chr(0x80) . chr(0xA2), 44)
                : '';
        }
        // I1-I5 CPS tags contain angle brackets that may get HTML-encoded
        // by the model layer — decode so the form displays raw tag syntax.
        foreach (['i1','i2','i3','i4','i5'] as $iField) {
            if (!empty($result['instance'][$iField])) {
                $result['instance'][$iField] = html_entity_decode(
                    (string)$result['instance'][$iField], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'
                );
            }
        }
        return $result;
    }

    /**
     * Override setAction to intercept private_key before it reaches the model.
     * If the submitted value is the bullet placeholder, keep existing key unchanged.
     * Otherwise write the new real key to the protected file and store the sentinel.
     */
    public function setAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed'];
        }

        $body      = $this->request->getPost('instance', null, []);
        $submitted = trim($body['private_key'] ?? '');

        // Bullet placeholder (UTF-8 bullet 0xE2 0x80 0xA2) or empty = do not change key
        $isBullet  = strpos($submitted, "\xE2\x80\xA2") !== false;
        $isBlank   = $submitted === '';

        if (!$isBullet && !$isBlank) {
            // Validate that it's a proper WireGuard Base64 key (IMP-3)
            if (!preg_match('/^[A-Za-z0-9+\/]{43}=$/', $submitted)) {
                return [
                    'result' => 'failed',
                    'validations' => [
                        'instance.private_key' => 'Private key must be a valid Base64 WireGuard key (44 characters ending with =)'
                    ]
                ];
            }
            // Real key submitted — persist to protected file
            try {
                $this->writePrivateKey($submitted);
            } catch (\RuntimeException $e) {
                return ['result' => 'failed', 'validations' => [
                    'instance.private_key' => $e->getMessage()
                ]];
            }
        } elseif ($isBlank) {
            // Blank submitted and no key file exists yet — reject
            if (!file_exists(self::PRIVKEY_FILE)) {
                return [
                    'result' => 'failed',
                    'validations' => [
                        'instance.private_key' => 'A private key is required. Use Generate Keypair or paste your key.'
                    ]
                ];
            }
        }
        // Always store the sentinel in config.xml, never the raw key
        $_POST['instance']['private_key'] = self::PRIVKEY_SENTINEL;

        // Validate H1-H4: values must not intersect each other (awg driver requirement)
        // Supports single values (e.g. "12345") and ranges (e.g. "12345-67890")
        $hFields = ['h1', 'h2', 'h3', 'h4'];
        $hRanges = []; // array of [low, high] for overlap check
        $validationErrors = [];
        foreach ($hFields as $hf) {
            $val = trim($body[$hf] ?? '');
            if ($val !== '') {
                if (!preg_match('/^\d{1,10}(-\d{1,10})?$/', $val)) {
                    $validationErrors["instance.{$hf}"] = strtoupper($hf) . ' must be a number or range (e.g. 12345 or 12345-67890)';
                    continue;
                }
                $parts = explode('-', $val, 2);
                $low  = (float)$parts[0];
                $high = isset($parts[1]) ? (float)$parts[1] : $low;
                if ($low < 5 || $high < 5 || $low > 4294967295 || $high > 4294967295) {
                    $validationErrors["instance.{$hf}"] = strtoupper($hf) . ' values must be in range 5-4294967295 (values 1-4 are reserved)';
                } elseif ($high < $low) {
                    $validationErrors["instance.{$hf}"] = strtoupper($hf) . ' range start must not exceed range end';
                } else {
                    // Check overlap with previously validated H ranges
                    foreach ($hRanges as $prev => [$pLow, $pHigh]) {
                        if ($low <= $pHigh && $pLow <= $high) {
                            $validationErrors["instance.{$hf}"] = strtoupper($hf) . ' range must not overlap with ' . strtoupper($prev);
                            break;
                        }
                    }
                    if (!isset($validationErrors["instance.{$hf}"])) {
                        $hRanges[$hf] = [$low, $high];
                    }
                }
            }
        }
        if (!empty($validationErrors)) {
            return ['result' => 'failed', 'validations' => $validationErrors];
        }

        return parent::setAction();
    }

    /**
     * Generate a new keypair via configd.
     * SEC-2: private_key is written to the protected file and NOT returned
     * in the API response. Only public_key is sent to the browser.
     */
    public function genKeyPairAction()
    {
        $backend = new Backend();
        $result  = $backend->configdRun('amneziawg gen_keypair');
        $decoded = json_decode($result, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['private_key']) || !isset($decoded['public_key'])) {
            $errMsg = $decoded['message'] ?? 'Failed to generate key pair';
            return ['status' => 'error', 'message' => $errMsg];
        }

        // HIGH-1: validate key format before trusting shell output
        if (!preg_match('/^[A-Za-z0-9+\/]{43}=$/', $decoded['private_key'])
            || !preg_match('/^[A-Za-z0-9+\/]{43}=$/', $decoded['public_key'])) {
            return ['status' => 'error', 'message' => 'Generated keys have invalid Base64 format'];
        }

        // SEC-2: store private key in file only, never expose it in the response
        try {
            $this->writePrivateKey($decoded['private_key']);
        } catch (\RuntimeException $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }

        // Persist sentinel into config.xml immediately so the model stays consistent
        $mdl = $this->getModel();
        $mdl->private_key = self::PRIVKEY_SENTINEL;
        $mdl->serializeToConfig();
        \OPNsense\Core\Config::getInstance()->save();

        return [
            'status'     => 'ok',
            'public_key' => $decoded['public_key'],
        ];
    }

    /**
     * Write the private key to the protected file with mode 0600.
     * @throws \RuntimeException if write fails
     */
    private function writePrivateKey(string $key): void
    {
        $dir = dirname(self::PRIVKEY_FILE);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0700, true)) {
                throw new \RuntimeException('Failed to create directory: ' . $dir);
            }
        }
        // HIGH-4: check return value of file_put_contents
        if (file_put_contents(self::PRIVKEY_FILE, trim($key) . "\n") === false) {
            throw new \RuntimeException('Failed to write private key file: ' . self::PRIVKEY_FILE);
        }
        chmod(self::PRIVKEY_FILE, 0600);
    }
}
