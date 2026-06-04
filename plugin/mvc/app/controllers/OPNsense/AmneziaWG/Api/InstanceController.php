<?php

namespace OPNsense\AmneziaWG\Api;

use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;

class InstanceController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass = '\OPNsense\AmneziaWG\Instance';
    protected static $internalModelName  = 'instance';

    // SEC-1: private keys are stored in protected files, not in config.xml.
    // The sentinel '::file::' in config.xml signals that the real key is on disk.
    // Multi-instance: one file per instance, keyed by the ArrayField uuid.
    const PRIVKEY_DIR      = '/usr/local/etc/amnezia';
    const PRIVKEY_SENTINEL = '::file::';

    /**
     * Search/list instances for the bootgrid.
     */
    public function searchItemAction()
    {
        return $this->searchBase(
            'instance',
            ['enabled', 'name', 'description', 'interface_number', 'peer_endpoint']
        );
    }

    /**
     * Retrieve one instance (or defaults for the add dialog).
     * Masks private_key with a bullet placeholder and double-decodes I1-I5.
     */
    public function getItemAction($uuid = null)
    {
        $result = $this->getBase('instance', 'instance', $uuid);
        if (isset($result['instance'])) {
            $stored    = (string)($result['instance']['private_key'] ?? '');
            $keyOnDisk = $uuid !== null && file_exists($this->keyFilePath((string)$uuid));
            // Show bullet placeholder when a key exists (sentinel or legacy plaintext)
            $result['instance']['private_key'] = ($stored !== '' || $keyOnDisk)
                ? str_repeat(chr(0xE2) . chr(0x80) . chr(0xA2), 44)
                : '';
            // I1-I5 CPS tags contain angle brackets that get double-encoded
            // by the model layer — decode twice to restore raw tag syntax.
            foreach (['i1', 'i2', 'i3', 'i4', 'i5'] as $iField) {
                if (!empty($result['instance'][$iField])) {
                    $val = (string)$result['instance'][$iField];
                    $val = html_entity_decode($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                    $result['instance'][$iField] = html_entity_decode($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                }
            }
            // Suggest the lowest free interface number for new instances
            if ($uuid === null) {
                $result['instance']['interface_number'] = (string)$this->nextFreeInterfaceNumber();
            }
        }
        return $result;
    }

    /**
     * Add a new instance. The uuid is only known after addBase() creates the
     * node, so a submitted real private key is stashed and written afterwards.
     */
    public function addItemAction()
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed'];
        }
        $body = $this->request->getPost('instance', null, []);

        $keyPrep = $this->prepareSubmittedKey($body, null);
        if (isset($keyPrep['validations'])) {
            return ['result' => 'failed', 'validations' => $keyPrep['validations']];
        }

        $validations = array_merge(
            $this->validateHFields($body, 'instance'),
            $this->validateInterfaceNumber($body, null)
        );
        if (!empty($validations)) {
            return ['result' => 'failed', 'validations' => $validations];
        }

        $_POST['instance']['private_key'] = self::PRIVKEY_SENTINEL;
        $result = $this->addBase('instance', 'instance');

        if (($result['result'] ?? '') !== 'failed' && $keyPrep['key'] !== null) {
            $uuid = (string)($result['uuid'] ?? '');
            if ($uuid !== '') {
                try {
                    $this->writePrivateKey($uuid, $keyPrep['key']);
                } catch (\RuntimeException $e) {
                    return ['result' => 'failed', 'validations' => [
                        'instance.private_key' => $e->getMessage()
                    ]];
                }
            }
        }
        return $result;
    }

    /**
     * Update an existing instance.
     */
    public function setItemAction($uuid)
    {
        if (!$this->request->isPost()) {
            return ['result' => 'failed'];
        }
        $body = $this->request->getPost('instance', null, []);

        $keyPrep = $this->prepareSubmittedKey($body, (string)$uuid);
        if (isset($keyPrep['validations'])) {
            return ['result' => 'failed', 'validations' => $keyPrep['validations']];
        }

        $validations = array_merge(
            $this->validateHFields($body, 'instance'),
            $this->validateInterfaceNumber($body, (string)$uuid)
        );
        if (!empty($validations)) {
            return ['result' => 'failed', 'validations' => $validations];
        }

        if ($keyPrep['key'] !== null) {
            try {
                $this->writePrivateKey((string)$uuid, $keyPrep['key']);
            } catch (\RuntimeException $e) {
                return ['result' => 'failed', 'validations' => [
                    'instance.private_key' => $e->getMessage()
                ]];
            }
        }
        // Always store the sentinel in config.xml, never the raw key
        $_POST['instance']['private_key'] = self::PRIVKEY_SENTINEL;

        return $this->setBase('instance', 'instance', $uuid);
    }

    /**
     * Delete an instance and its protected key file.
     */
    public function delItemAction($uuid)
    {
        $result = $this->delBase('instance', $uuid);
        if (($result['result'] ?? '') === 'deleted') {
            // SEC-1: remove the orphaned key file together with the instance
            @unlink($this->keyFilePath((string)$uuid));
        }
        return $result;
    }

    /**
     * Toggle enabled state from the grid.
     */
    public function toggleItemAction($uuid, $enabled = null)
    {
        return $this->toggleBase('instance', $uuid, $enabled);
    }

    /**
     * Generate a new keypair via configd.
     * Stateless by design for the dialog flow: both keys are returned to the
     * browser so the dialog can populate its fields; persistence happens on
     * dialog Save through add/setItemAction. This re-scopes SEC-2 (the private
     * key transits to the client once, same as when a user pastes their own
     * key into the form) — it is never logged or stored outside the dialog.
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

        return [
            'status'      => 'ok',
            'public_key'  => $decoded['public_key'],
            'private_key' => $decoded['private_key'],
        ];
    }

    /**
     * Classify the submitted private_key value.
     * Returns ['key' => string|null] where null means "keep existing key",
     * or ['validations' => [...]] on error.
     */
    private function prepareSubmittedKey(array $body, ?string $uuid): array
    {
        $submitted = trim($body['private_key'] ?? '');
        // Bullet placeholder (UTF-8 bullet 0xE2 0x80 0xA2) = do not change key
        $isBullet = strpos($submitted, "\xE2\x80\xA2") !== false;

        if ($isBullet) {
            return ['key' => null];
        }
        if ($submitted === '') {
            // Blank is acceptable only when a key file already exists for this instance
            if ($uuid === null || !file_exists($this->keyFilePath($uuid))) {
                return ['validations' => [
                    'instance.private_key' => 'A private key is required. Use Generate Keypair or paste your key.'
                ]];
            }
            return ['key' => null];
        }
        // IMP-3: validate that it's a proper WireGuard Base64 key
        if (!preg_match('/^[A-Za-z0-9+\/]{43}=$/', $submitted)) {
            return ['validations' => [
                'instance.private_key' => 'Private key must be a valid Base64 WireGuard key (44 characters ending with =)'
            ]];
        }
        return ['key' => $submitted];
    }

    /**
     * Validate H1-H4: format, value range and mutual non-overlap within
     * this instance (awg driver requirement). Returns validation map.
     */
    private function validateHFields(array $body, string $prefix): array
    {
        $hRanges = []; // array of [low, high] for overlap check
        $validationErrors = [];
        foreach (['h1', 'h2', 'h3', 'h4'] as $hf) {
            $val = trim($body[$hf] ?? '');
            if ($val === '') {
                continue;
            }
            if (!preg_match('/^\d{1,10}(-\d{1,10})?$/', $val)) {
                $validationErrors["{$prefix}.{$hf}"] = strtoupper($hf) . ' must be a number or range (e.g. 12345 or 12345-67890)';
                continue;
            }
            $parts = explode('-', $val, 2);
            $low  = (float)$parts[0];
            $high = isset($parts[1]) ? (float)$parts[1] : $low;
            if ($low < 5 || $high < 5 || $low > 4294967295 || $high > 4294967295) {
                $validationErrors["{$prefix}.{$hf}"] = strtoupper($hf) . ' values must be in range 5-4294967295 (values 1-4 are reserved)';
            } elseif ($high < $low) {
                $validationErrors["{$prefix}.{$hf}"] = strtoupper($hf) . ' range start must not exceed range end';
            } else {
                foreach ($hRanges as $prev => [$pLow, $pHigh]) {
                    if ($low <= $pHigh && $pLow <= $high) {
                        $validationErrors["{$prefix}.{$hf}"] = strtoupper($hf) . ' range must not overlap with ' . strtoupper($prev);
                        break;
                    }
                }
                if (!isset($validationErrors["{$prefix}.{$hf}"])) {
                    $hRanges[$hf] = [$low, $high];
                }
            }
        }
        return $validationErrors;
    }

    /**
     * interface_number must be unique across instances (each maps to awg<N>).
     * $selfUuid excludes the instance being edited from the check.
     */
    private function validateInterfaceNumber(array $body, ?string $selfUuid): array
    {
        $submitted = trim($body['interface_number'] ?? '');
        if ($submitted === '') {
            return [];
        }
        foreach ($this->getModel()->instance->iterateItems() as $nodeUuid => $node) {
            if ($selfUuid !== null && (string)$nodeUuid === $selfUuid) {
                continue;
            }
            if ((string)$node->interface_number === $submitted) {
                return ['instance.interface_number' =>
                    'Interface number ' . $submitted . ' is already used by tunnel "' . (string)$node->name . '"'];
            }
        }
        return [];
    }

    /**
     * Lowest free interface number 0-99 for new instances.
     */
    private function nextFreeInterfaceNumber(): int
    {
        $used = [];
        foreach ($this->getModel()->instance->iterateItems() as $node) {
            $used[(int)(string)$node->interface_number] = true;
        }
        for ($n = 0; $n <= 99; $n++) {
            if (!isset($used[$n])) {
                return $n;
            }
        }
        return 99;
    }

    /**
     * Protected key file path for an instance uuid.
     */
    private function keyFilePath(string $uuid): string
    {
        // uuid comes from the ArrayField/route — keep a strict charset anyway
        $safe = preg_replace('/[^a-fA-F0-9\-]/', '', $uuid);
        return self::PRIVKEY_DIR . '/' . $safe . '.key';
    }

    /**
     * Write the private key to the protected per-instance file with mode 0600.
     * @throws \RuntimeException if write fails
     */
    private function writePrivateKey(string $uuid, string $key): void
    {
        if (!is_dir(self::PRIVKEY_DIR)) {
            if (!mkdir(self::PRIVKEY_DIR, 0700, true)) {
                throw new \RuntimeException('Failed to create directory: ' . self::PRIVKEY_DIR);
            }
        }
        $path = $this->keyFilePath($uuid);
        // HIGH-4: check return value of file_put_contents
        if (file_put_contents($path, trim($key) . "\n") === false) {
            throw new \RuntimeException('Failed to write private key file: ' . $path);
        }
        chmod($path, 0600);
    }
}
