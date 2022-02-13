<?php declare(strict_types=1);

// set path constants
const WEB_ROOT_PATH = __DIR__ . 'public/';
const SRC_PATH = __DIR__ . '/./src/';
const CLASS_PATH = SRC_PATH . '/Classes/';
const FUNCTION_PATH = SRC_PATH . '/Functions/';
const ENUM_PATH = CLASS_PATH . '/Enum/';

// scale
bcscale(0);

// setup .env
if (!file_exists(__DIR__.'/.env')) {
    echo 'rename .env_sample to .env, and change the values accordingly';
    exit(0);
}
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();


// if there is no state, we're likely needing to sync from the network
$store = new KeyValStore();
$state = $store->getKey('state', '');
if (empty($state)) {
    $store->add('state', State::Syncing);
}

// bootstrap from Genesis?
$block = new Block();
if ($block->getCurrentHeight() === 0) {
    $block->add($block->genesis());
}
