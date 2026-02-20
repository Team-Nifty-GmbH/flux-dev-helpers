<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Remote Servers Configuration
    |--------------------------------------------------------------------------
    |
    | Configure the remote servers that can be used for database and storage
    | synchronization. The key is the server name (typically the domain).
    |
    | Simple format (SSH host = key, default port):
    |   'staging.example.com' => 'staging_user',
    |
    | Extended format (custom host, port, identity file, proxy, directory):
    |   'kunde.example.com' => [
    |       'user' => 'forge',
    |       'host' => '192.168.1.50',              // optional, default = key
    |       'port' => 2203,                         // optional, default = 22
    |       'identity_file' => '~/.ssh/kunde_key',  // optional
    |       'proxy_jump' => 'bastion.example.com',  // optional
    |       'directory' => '/var/www/app',           // optional, default = ~/{key}
    |   ],
    |
    */
    'remote_servers' => [
        // 'staging.example.com' => 'staging_user',
    ],
];
