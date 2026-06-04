<?php

namespace OPNsense\AmneziaWG\Migrations;

use OPNsense\Base\BaseModelMigration;
use OPNsense\Core\Config;

/**
 * Migration 1.0.0 -> 2.0.0: flat single-instance model to ArrayField collection.
 *
 * Legacy layout:  //OPNsense/amneziawg/instance           (flat node, no uuid)
 * New layout:     //OPNsense/amneziawg/instances/instance (ArrayField, uuid-keyed)
 *
 * Also renames the single private key file to the per-instance scheme:
 *   /usr/local/etc/amnezia/private.key -> /usr/local/etc/amnezia/<uuid>.key
 */
class M2_0_0 extends BaseModelMigration
{
    const LEGACY_PRIVKEY_FILE = '/usr/local/etc/amnezia/private.key';
    const AMNEZIA_DIR         = '/usr/local/etc/amnezia';

    /**
     * Repeatedly html_entity_decode until the value is stable — unwinds any
     * number of legacy encoding layers. Legit CPS tag syntax (<b 0xHEX>,
     * <r N>, <t>, <c>, ...) contains no ampersands, so a clean value passes
     * through unchanged.
     */
    private static function decodeEntitiesFully(string $value): string
    {
        do {
            $prev  = $value;
            $value = html_entity_decode($prev, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        } while ($value !== $prev);
        return $value;
    }

    public function run($model)
    {
        $cfgObj = Config::getInstance()->object();
        $legacy = $cfgObj->OPNsense->amneziawg->instance ?? null;

        // Idempotency: nothing to migrate when no legacy node or it was never configured
        if ($legacy === null || empty((string)($legacy->peer_public_key ?? ''))) {
            parent::run($model);
            return;
        }

        // Copy every legacy field. i1-i5 are normalized to their raw form
        // (BACKLOG #5): legacy versions accumulated multiple layers of HTML
        // encoding on the CPS tags (`&amp;lt;b ...` instead of `<b ...`), while
        // the 3.x save path stores raw values. The decode chains downstream
        // (getItemAction, awg_get_instances) are no-ops for clean values, so
        // normalizing here is safe and keeps config.xml debuggable.
        $fields = [
            'enabled', 'name', 'description', 'interface_number',
            'private_key', 'listen_port', 'address', 'dns', 'mtu',
            'jc', 'jmin', 'jmax', 's1', 's2', 's3', 's4',
            'h1', 'h2', 'h3', 'h4', 'i1', 'i2', 'i3', 'i4', 'i5',
            'peer_public_key', 'peer_preshared_key', 'peer_endpoint',
            'peer_allowed_ips', 'peer_persistent_keepalive',
        ];
        $iFields = ['i1', 'i2', 'i3', 'i4', 'i5'];
        $nodes = [];
        foreach ($fields as $field) {
            if (isset($legacy->$field)) {
                $value = (string)$legacy->$field;
                if (in_array($field, $iFields, true)) {
                    $value = self::decodeEntitiesFully($value);
                }
                $nodes[$field] = $value;
            }
        }
        if (empty($nodes['name'])) {
            $nodes['name'] = 'amneziawg';
        }

        $node = $model->instance->Add();
        $node->setNodes($nodes);
        $uuid = $node->getAttributes()['uuid'] ?? '';

        // SEC-1: move the protected key file to the per-instance name so the
        // sentinel '::file::' stored in the migrated node keeps resolving.
        if ($uuid !== '' && file_exists(self::LEGACY_PRIVKEY_FILE)) {
            $target = self::AMNEZIA_DIR . '/' . $uuid . '.key';
            if (!file_exists($target)) {
                if (!@rename(self::LEGACY_PRIVKEY_FILE, $target)) {
                    // R2: leave the legacy file in place on failure; service-control
                    // will log "private key file not found" for this instance and
                    // skip it instead of breaking the whole migration.
                    syslog(LOG_ERR, 'AmneziaWG M2_0_0: failed to rename private.key to ' . $target);
                } else {
                    @chmod($target, 0600);
                }
            }
        }

        // Remove the legacy flat node. It lives outside the model mount
        // (//OPNsense/amneziawg/instances), so the model save will not touch it.
        // The framework persists the migrated model after run() returns; dropping
        // the legacy node here on the shared Config object lands in the same save.
        unset($cfgObj->OPNsense->amneziawg->instance);

        parent::run($model);
    }
}
