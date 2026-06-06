<?php

function amneziawg_services()
{
    $services = [];
    $services[] = [
        'description' => 'AmneziaWG',
        'configd'     => [
            'restart' => ['amneziawg restart'],
            'start'   => ['amneziawg start'],
            'stop'    => ['amneziawg stop'],
        ],
        'name'    => 'amneziawg',
        'pidfile' => '/var/run/amneziawg.pid',
    ];
    return $services;
}

function amneziawg_devices()
{
    // Register awgN as volatile plugin devices. Without this, assigned
    // awg interfaces (which do not exist at boot before the service starts)
    // trigger is_interface_mismatch() in console.inc and OPNsense silently
    // resets the whole interface assignment to two-NIC defaults
    // ("Console assignment of interfaces"), wiping LAN/WAN/optN mapping.
    $names = [];

    foreach ((new OPNsense\AmneziaWG\Instance())->instance->iterateItems() as $node) {
        if (!empty((string)$node->enabled)) {
            $device = 'awg' . (string)$node->interface_number;
            $names[$device] = [
                'descr' => sprintf('%s (AmneziaWG - %s)', $device, (string)$node->name),
                'ifdescr' => (string)$node->name,
                'name' => $device,
            ];
        }
    }

    return [[
        'configurable' => false,
        'pattern' => '^awg\d+',
        'type' => 'amneziawg',
        'volatile' => true,
        'names' => $names,
    ]];
}

function amneziawg_xmlrpc_sync()
{
    return [];
}

function amneziawg_cron()
{
    // Unconditional every-minute job: the watchdog script itself exits
    // immediately when the watchdog flag is off, so no crontab regeneration
    // is needed when the flag is toggled in the GUI.
    $jobs = [];
    $jobs[]['autocron'] = ['/usr/local/sbin/configctl -d amneziawg watchdog', '*'];
    return $jobs;
}
