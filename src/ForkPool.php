<?php declare(strict_types=1);

namespace Blockchain;
use function count;
use function key;
use function pcntl_fork;
use function pcntl_wait;

/**
 * Class ForkPool
 * @package Blockchain
 *
 * todo: this needs to be implemented once things seem stable
 */
class ForkPool
{
    protected array $jobs = [];
    protected array $pid = [];
    protected int $maxWorkers = 10;

    /**
     * @param $job
     */
    public function addWork($job): void
    {
        $this->jobs[] = $job;
    }

    /**
     * @return mixed|null
     */
    public function getWork(): mixed
    {
        $work = null;
        if (count($this->jobs) > 0) {
            $key = key($this->jobs);
            $work = $this->jobs[$key];
            unset($this->jobs[$key]);
        }
        return $work;
    }

    /**
     * @param $work
     */
    protected function job($work): void
    {
        // your work here...
    }

    /**
     * @param mixed $work
     * @return int|bool
     */
    private function doJob(mixed $work): int|bool
    {
        $pid = pcntl_fork();

        if ($pid === -1) {
            // error forking...
            return false;
        }

        if ($pid === 0) {
            // child
            $this->job($work);
            exit(0);
        }

        // add pid to list
        return $pid;
    }

    /**
     * @return void
     */
    public function run(): void
    {
        // loop until all work is distributed...
        while (true) {

            if (count($this->pid) < $this->maxWorkers) {
                // fetch work
                $work = $this->getWork();

                if ($work === null) {
                    break;
                }

                // start the job...
                $worker = $this->doJob($work);

                if ($worker !== false) {
                    $this->pid[$worker] = true;
                } else {
                    // error forking
                    echo 'Failed to fork...', PHP_EOL;
                    exit(1);
                }
            } else {
                // wait until a process completes...
                $child_pid = pcntl_wait($status);

                if ($child_pid !== -1) {
                    unset($this->pid[$child_pid]);
                }
            }
        }

        // parent
        while (true) {
            // wait for each process to exit
            $child_pid = pcntl_wait($status);

            if ($child_pid !== -1) {
                unset($this->pid[$child_pid]);
            }

            // all jobs done?
            if (count($this->pid) <= 0) {
                break;
            }
        }
    }
}

/*
class WorkerPool extends ThreadPool
{
    protected function job($work)
    {
        // your work here...
        echo 'hello.. ', $work, PHP_EOL;
    }
}

$tp = new WorkerPool();
for ($i = 0; $i < 100; ++$i) {
    $tp->addWork($i);
}

$start = microtime(true);
$tp->run();
$totalTime = microtime(true) - $start;

echo 'Parallel: ', round($totalTime, 5), PHP_EOL;
*/
