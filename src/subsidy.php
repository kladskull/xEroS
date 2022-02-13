<?php

function getReductions(int $nHeight): int
{
    if ($nHeight <= 0) {
        $nHeight = 1;
    }
    $strHeight = (string)$nHeight;

    $targetModulus = (3600 / 600) * 24 * 365;
    $reductions = bcdiv($strHeight, (string)$targetModulus, 0);

    if (bccomp($reductions, "0") === 1) {
        $reductions = (int)$reductions;
    }

    return $reductions;
}

function getRewardValue(int $nHeight): string
{
    if ($nHeight <= 0) {
        $nHeight = 1;
    }
    $strHeight = (string)$nHeight;

    $targetModulus = (3600 / 600) * 24 * 365;
    $reductions = bcdiv($strHeight, (string)$targetModulus, 0);

    $nSubsidy = '10000000000'; // 100 coins

    if (bccomp($reductions, "0") === 1) {
        $reductions = (int)$reductions;
        for ($i = 0; $i < $reductions; $i++) {
            $reduction = bcmul($nSubsidy, "0.04");
            $nSubsidy = bcsub($nSubsidy, $reduction, 0);
            if (bccomp($nSubsidy,'100000000') <= 0) {
                break;
            }
        }
    }

    return $nSubsidy;
}

$height = "1";
$inc = 600;
$time = time();
$startTime = $time;
$counter = 0;
$supply = "0";
$date = "";
$reward = "0";
while (1) {
    $oldDate = $date;
    $date = date("Y-m", $time);
    $oldReward = $reward;
    $reward = getRewardValue($height);
    $reductions = getReductions($height);
    $supply = bcadd($supply, $reward, 0);

    if (bccomp($reward, "100000000", 0) <= 0) {
        exit(0);
    }
    //$date !== $oldDate ||
    if ($date !== $oldDate ||$reward !== $oldReward || bccomp($height, "1") <= 0) {
        $counter = 0;
        echo date("\"Y-m-d\"", $time), ",", bcdiv($reward, '100000000', 8), ",", bcdiv($supply, '100000000', 8), ',', $reductions, "\n";
    }
    $time += $inc;
    $height = bcadd($height, "1");

    $counter++;
}
echo number_format($argv[1] - $supply, 8), "\n";
