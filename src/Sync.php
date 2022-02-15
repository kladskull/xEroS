<?php declare(strict_types=1);

namespace Xeros;

require __DIR__ . '/../bootstrap.php';

/**
 * TODO: INCORPORATE THIS INTO FORKPOOL / MAIN APP
 */


$queue = new Queue();
$peer = new Peer();
$block = new Block();
$http = new Http();
$store = new DataStore();

$db = DB::getMDB();

$ticks = 0;
$failCount = 0;
$localheightOld = 0;

// create state object, so we don't re-ping peers
$pings = [];

echo "\n" . Config::getProductName() . " BlockSync v" . Config::getVersion() . "\n";
echo Config::getProductCopyright() . "\n\n";

$peerRequestTimer = random_int(3600, 3600 * 2);
$peerResolveTimer = random_int(60, 120);
$peerBroadcastTimer = random_int(3600, 3600 * 2);
$peerRefreshTimer = random_int(3600, 3600 * 24);
$stuck = false;
$stuckCount = 0;

while (1) {

    /**************************************************************************************************************
     * Collect new peers from other nodes once an hour
     **************************************************************************************************************/
    if ($ticks === 0 || $ticks % $peerRequestTimer === 0) {
        foreach ($peer->getAll(Config::getMaxRebroadcastPeers()) as $p) {
            Log::console('Asking ' . $p['address'] . ' for a peer list');
            $peerResponse = Api::decodeResponse($http->get($p['address'] . 'peer.php'));
            if ($peerResponse['response'] === true) {

                // update the ping time of the node we spoke to
                $peer->updatePingTime($p['address']);

                // iterate over all new peers
                foreach ($peerResponse['data'] as $newPeer) {

                    // only ping if we don't know them...
                    if ($peer->getByHostAddress($newPeer['address']) === null) {
                        $pingResponse = Api::decodeResponse($peer->ping($newPeer['address']));
                        if ($pingResponse['response'] === true) {
                            if ($pingResponse['data'] === 'pong') {
                                $peer->add($newPeer['address']);
                                Log::console(' + New peer found: ' . $p['address']);
                            }
                        }
                    }
                }
            } else {
                // peer returned unexpected data
                $peer->incrementFails($p['address']);
            }
        }
        // change the next run time
        $peerRequestTimer = random_int(3600, 3600 * 6);
    }

    /**************************************************************************************************************
     * Test new peers from our peer queue
     **************************************************************************************************************/
    if ($ticks === 0 || $ticks % $peerResolveTimer === 0) {
        $queueItems = [];
        foreach ($queue->getItems('peer_request') as $queueItem) {
            $queueItems[$queueItem['data']] = $queueItem['data'];
            $queue->delete((int)$queueItem['id']);
        }

        foreach ($queueItems as $p) {
            Log::console('Testing connectivity for a new peer request from ' . $p);
            $peerResponse = Api::decodeResponse($http->get($p . '/peer.php'));
            if (!empty($peerResponse)) {
                if ($peerResponse['response'] === true) {
                    $peer->add($p);
                    $peer->updatePingTime($p);
                }
            }
        }

        $peerResolveTimer = random_int(3600, 3600 * 24);
    }

    /**************************************************************************************************************
     * Do a random refresh of peers
     **************************************************************************************************************/
    if ($ticks % $peerRefreshTimer === 0) {
        $peerAddresses = [];
        Log::console('Refreshing oldest peers');
        foreach ($peer->getAll(100, true) as $p) {
            $peerAddresses[] = $p['address'];
        }
        $peer->refresh($peerAddresses);
        $peerRefreshTimer = random_int(3600, 3600 * 24);
    }

    /**************************************************************************************************************
     * Broadcast us as a peer, only if we are synchronized
     **************************************************************************************************************/
    if ($ticks === 0 || $ticks % $peerBroadcastTimer === 0) {
        if ($store->getKey('state', '') !== DataStore::Syncing) {
            foreach ($peer->getAll(Config::getMaxRebroadcastPeers()) as $p) {
                $peerRequest = json_encode(['data' => 'peer_request']);
                $result = $http->post($p['address'] . '/peer.php', $peerRequest);
            }
        }
        $peerBroadcastTimer = random_int(3600, 3600 * 24);
    }

    /**************************************************************************************************************
     * Get the current local height
     **************************************************************************************************************/
    $localHeight = $block->getCurrentHeight();
    if ($localheightOld !== $localHeight) {
        Log::console('Local height is ' . $block->getCurrentHeight());
        $localheightOld = $localHeight;
    }

    /**************************************************************************************************************
     * Get the network height
     **************************************************************************************************************/
    $blockCount = 0;
    $initPeer = '';
    foreach ($peer->getAll(Config::getMaxRebroadcastPeers()) as $p) {
        $blk = $block->getRe($p['address'],);
        if ((int)$blk['height'] > $blockCount) {
            $blockCount = (int)$blk['height'];
            $initPeer = $p['address'];
        }
    }

    /**************************************************************************************************************
     * Force a synchronization if we just started
     **************************************************************************************************************/
    if ($localHeight === 1 && $localHeight !== $blockCount) {
        $store->add('state', Node::Syncing);
    }

    // determine the state, and what we need to do
    $diff = 0;
    if ($localHeight < $blockCount) {
        $diff = $blockCount - $localHeight;
        Log::console('Remote block id #' . $blockCount . ' found which is greater than our local height of ' . $localHeight);
        $store->add('state', State::Syncing);
    } else if ($localHeight > $blockCount) {
        // we somehow got ahead, we need to re-sync...
        if ($localHeight - $blockCount > 1) {
            Log::console('Our node currently has the highest count, need to send the blocks out');

            /**
             * We've somehow managed to mine more than everyone else, check if we're inaccessible to
             * all nodes. If so, we're going to clear our work, and re-sync. If not, need to just wait
             * for someone to see we have more blocks and re-sync with us.
             */
            /*if (!Node::isAccessible()) {
                Log::console('We are inaccessible, and need to re-sync');

                $store->add('state', State::Syncing);

                // give the miner some time to stop
                Log::console('Pausing the miner...');
                sleep(5);
                for ($i = max(2, $blockCount - 1); $i <= $localHeight + 50; $i++) {
                    $delBlock = $block->getByHeight($i);
                    if (!empty($delBlock)) {
                        $block->delete($delBlock['block_id']);
                        Log::console('Purging block id ' . $delBlock['block_id']);
                    }
                }
            }*/

            Log::console('We are inaccessible, and need to re-sync');

            // find the last common block, we want to get all their blocks
            $commonBlockHeight = $blockCount;
            for ($i = $blockCount; $blockCount > 0; $blockCount--) {
                $remoteBlock = $block->getRemoteBlockByHeight($initPeer, $blockCount);
                $localBlock = $block->getFullBlockByHeight($blockCount);

                // if it's the same, break out
                if ($remoteBlock['hash'] === $localBlock['hash']) {
                    $commonBlockHeight = $i;
                    break;
                }
            }

            // start with the block after the common block
            for ($i = $commonBlockHeight + 1; $i <= $localHeight; $i++) {

                // get their block, test and store it
                $remoteBlock = $block->getRemoteBlockByHeight($initPeer, $i);
                $validate = $block->validateFullBlock($remoteBlock);
                if ($validate['validated']) {
                    $block->add($remoteBlock);
                }

                // send them our version of the height
                $localBlock = $block->getFullBlockByHeight($i);
                $packet = json_encode($localBlock);

                for ($retry = 0; $retry < 5; $retry++) {
                    $peerResponse = Api::decodeResponse($http->get($initPeer . 'peer.php'));
                    if ($peerResponse['response'] === true) {
                        $result = $http->post($initPeer . 'block.php', $packet);
                        $result = Api::decodeResponse($result['body']);
                        if ($result['response'] === true) {

                        }
                    }
                }

                // now send them ours

                // we need to go TOP down in our chain and mark orphans (we will use the longest chain)

                // we need to tell the other to do a TOP down as well

                // give the miner some time to stop
                Log::console('Pausing the miner...');
                sleep(5);
                for ($i = max(2, $blockCount); $i <= $localHeight; $i++) {
                    // blast the block to all of our peers
                    foreach ($peer->getAll(Config::getMaxRebroadcastPeers()) as $p) {
                        Log::console('Sending block with id `' . $propBlock['block_id'] . '` carrying ' . $propBlock['transaction_count'] . ' transactions to: ' . $p['address']);
                        $result = $http->post($p['address'] . 'block.php', json_encode($propBlock));
                        $x = 1;
                    }
                }
            }
            if ($store->getKey('state') === State::Syncing) {
                Log::console('Synchronization completed');
            }
            $store->add('state', State::Mine);
            sleep(1);
        }

        /**************************************************************************************************************
         * If we are in the sync state- we need to synchronize now
         **************************************************************************************************************/
        $lastSync = (int)($store->getKey('last_sync', 1)) + 1;
        for ($i = $lastSync; $i <= $blockCount; $i++) {

            // fetch the remote block
            $id = 0;
            $trys = 0;
            while ($trys < 5) {
                $blk = $block->getRemoteBlockByHeight($initPeer, $i);
                $verified = $block->verifyRemoteBlock($blk, $peer, $block);
                if ($verified) {
                    // add the block
                    $id = $block->add($blk);

                    // mark other same height records as orphans
                    //$block->identifyOrphans($i, $blk['block_id']);

                    // complete the block (for restarts)
                    $store->add('last_sync', (string)$lastSync);
                    break;
                } else {
                    // retry?
                    $trys++;
                    Log::console('The last retrieved block was not valid, will retry');
                    sleep(2);
                }
            }

            // todo: look into node bans..
            if ($id <= 0) {
                echo "Block never saved, duplicate, or self sent...\n";
                Log::console('The last retrieved block was not accepted, block will be ignored');
                sleep(1);
            }
        }
        $lastSync = $store->add('last_sync', (string)$blockCount);

        // do we have blocks to propagate to the network?
        $queueItems = $queue->getItems('propagate_block');
        foreach ($queueItems as $item) {

            $propBlock = $block->returnFullBlock($item['data']);
            if (!empty($propBlock)) {
                // blast the block to all of our peers
                foreach ($peer->getAll(Config::getMaxRebroadcastPeers()) as $p) {
                    Log::console('Sending block with id `' . $propBlock['block_id'] . '` carrying ' . $propBlock['transaction_count'] . ' transactions to: ' . $p['address']);
                    $result = $http->post($p['address'] . 'block.php', json_encode($propBlock));
                }
            }

            // delete the queued item
            Log::console('Removing queue item #' . $item['id']);
            $queue->delete((int)$item['id']);
        }

        /**************************************************************************************************************
         * Clean-up the queue at the start, and every ~20 minutes
         **************************************************************************************************************/
        if ($ticks === 0 || $ticks % (20 * 60) === 0) {
            $queue->prune();
        }

        /**************************************************************************************************************
         * Rest
         **************************************************************************************************************/
        usleep(250000);

        // increment ticks
        if ($ticks++ > 9999999999) {
            $ticks = 0;
        }
    }
}
