<?php declare(strict_types=1);

return
[
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds'
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'development',
        'production' => [
            'adapter' => 'sqlite',
            'name' => 'xero',
            'suffix' => '.db',
        ],
        'development' => [
            'adapter' => 'sqlite',
            'name' => 'xero-dev',
            'suffix' => '.db',
        ],
        'testing' => [
            'adapter' => 'sqlite',
            'name' => 'xero-test',
            'suffix' => '.db',
        ]
    ],
    'version_order' => 'creation'
];
