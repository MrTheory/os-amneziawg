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
