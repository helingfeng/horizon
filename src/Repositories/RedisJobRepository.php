<?php

namespace Laravel\Horizon\Repositories;

use Cake\Chronos\Chronos;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\JobPayload;

class RedisJobRepository implements JobRepository
{
    /**
     * The Redis connection instance.
     *
     * @var \Illuminate\Contracts\Redis\Factory
     */
    public $redis;

    /**
     * The keys stored on the job hashes.
     *
     * @var array
     */
    public $keys = [
        'id', 'connection', 'queue', 'name', 'status', 'payload',
        'exception', 'failed_at', 'completed_at', 'retried_by', 'reserved_at',
    ];

    /**
     * The number of minutes until recently failed jobs should be purged.
     *
     * @var int
     */
    public $recentFailedJobExpires;

    /**
     * The number of minutes until recent jobs should be purged.
     *
     * @var int
     */
    public $recentJobExpires;

    /**
     * The number of minutes until completed jobs should be purged.
     *
     * @var int
     */
    public $recentCompletedExpires;

    /**
     * The number of minutes until failed jobs should be purged.
     *
     * @var int
     */
    public $failedJobExpires;

    /**
     * The number of minutes until monitored jobs should be purged.
     *
     * @var int
     */
    public $monitoredJobExpires;

    /**
     * Create a new repository instance.
     *
     * @param  \Illuminate\Contracts\Redis\Factory  $redis
     * @return void
     */
    public function __construct(RedisFactory $redis)
    {
        $this->redis = $redis;
        $this->recentJobExpires = config('horizon.trim.recent', 60);
        $this->recentCompletedExpires = config('horizon.trim.completed', 60);
        $this->failedJobExpires = config('horizon.trim.failed', 10080);
        $this->recentFailedJobExpires = config('horizon.trim.recent_failed', $this->failedJobExpires);
        $this->monitoredJobExpires = config('horizon.trim.monitored', 10080);
    }

    /**
     * Get the next job ID that should be assigned.
     *
     * @return string
     */
    public function nextJobId()
    {
        return (string) $this->connection()->incr('job_id');
    }

    /**
     * Get the total count of recent jobs.
     *
     * @return int
     */
    public function totalRecent()
    {
        return $this->connection()->zcard('recent_jobs');
    }

    /**
     * Get the total count of failed jobs.
     *
     * @return int
     */
    public function totalFailed()
    {
        return $this->connection()->zcard('failed_jobs');
    }

    /**
     * Get a chunk of recent jobs.
     *
     * @param  string|null  $afterIndex
     * @return \Illuminate\Support\Collection
     */
    public function getRecent($afterIndex = null)
    {
        return $this->getJobsByType('recent_jobs', $afterIndex);
    }

    /**
     * Get a chunk of failed jobs.
     *
     * @param  string|null  $afterIndex
     * @return \Illuminate\Support\Collection
     */
    public function getFailed($afterIndex = null)
    {
        return $this->getJobsByType('failed_jobs', $afterIndex);
    }

    /**
     * Get the count of recent jobs.
     *
     * @return int
     */
    public function countRecent()
    {
        return $this->countJobsByType('recent_jobs');
    }

    /**
     * Get the count of failed jobs.
     *
     * @return int
     */
    public function countFailed()
    {
        return $this->countJobsByType('failed_jobs');
    }

    /**
     * Get the count of the recently failed jobs.
     *
     * @return int
     */
    public function countRecentlyFailed()
    {
        return $this->countJobsByType('recent_failed_jobs');
    }

    /**
     * Get a chunk of jobs from the given type set.
     *
     * @param  string  $type
     * @param  string  $afterIndex
     * @return \Illuminate\Support\Collection
     */
    protected function getJobsByType($type, $afterIndex)
    {
        $afterIndex = $afterIndex === null ? -1 : $afterIndex;

        return $this->getJobs($this->connection()->zrange(
            $type, $afterIndex + 1, $afterIndex + 50
        ), $afterIndex + 1);
    }

    /**
     * Get the number of jobs in a given type set.
     *
     * @param  string  $type
     * @return int
     */
    protected function countJobsByType($type)
    {
        $minutes = $this->minutesForType($type);

        return $this->connection()->zcount(
            $type, '-inf', Chronos::now()->subMinutes($minutes)->getTimestamp() * -1
        );
    }

    /**
     * Get the number of minutes to count for a given type set.
     *
     * @param  string  $type
     * @return int
     */
    protected function minutesForType($type)
    {
        switch ($type) {
            case 'failed_jobs':
                return $this->failedJobExpires;
            case 'recent_failed_jobs':
                return $this->recentFailedJobExpires;
            default:
                return $this->recentJobExpires;
        }
    }

    /**
     * Retrieve the jobs with the given IDs.
     *
     * @param  array  $ids
     * @param  mixed  $indexFrom
     * @return \Illuminate\Support\Collection
     */
    public function getJobs(array $ids, $indexFrom = 0)
    {
        $jobs = [];
        foreach ($ids as $id) {
            $jobs[] = $this->connection()->hmget($id, $this->keys);
        }

        return $this->indexJobs(collect($jobs)->filter(function ($job) {
            $job = is_array($job) ? array_values($job) : null;

            return is_array($job) && $job[0] !== null && $job[0] !== false;
        })->values(), $indexFrom);
    }

    /**
     * Index the given jobs from the given index.
     *
     * @param  \Illuminate\Support\Collection  $jobs
     * @param  int  $indexFrom
     * @return \Illuminate\Support\Collection
     */
    protected function indexJobs($jobs, $indexFrom)
    {
        return $jobs->map(function ($job) use (&$indexFrom) {
            $job = (object) array_combine($this->keys, $job);

            $job->index = $indexFrom;

            $indexFrom++;

            return $job;
        });
    }

    /**
     * Insert the job into storage.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @param  \Laravel\Horizon\JobPayload  $payload
     * @return void
     */
    public function pushed($connection, $queue, JobPayload $payload)
    {
        $this->storeJobReference($this->connection(), 'recent_jobs', $payload);

        $time = str_replace(',', '.', microtime(true));

        $this->connection()->hmset($payload->id(), [
            'id' => $payload->id(),
            'connection' => $connection,
            'queue' => $queue,
            'name' => $payload->decoded['displayName'],
            'status' => 'pending',
            'payload' => $payload->value,
            'created_at' => $time,
            'updated_at' => $time,
        ]);

        $this->connection()->expireat(
            $payload->id(), Chronos::now()->addMinutes($this->recentJobExpires)->getTimestamp()
        );
    }

    /**
     * Mark the job as reserved.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @param  \Laravel\Horizon\JobPayload  $payload
     * @return void
     */
    public function reserved($connection, $queue, JobPayload $payload)
    {
        $time = str_replace(',', '.', microtime(true));

        $this->connection()->hmset(
            $payload->id(), [
                'status' => 'reserved',
                'payload' => $payload->value,
                'updated_at' => $time,
                'reserved_at' => $time,
            ]
        );
    }

    /**
     * Mark the job as released / pending.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @param  \Laravel\Horizon\JobPayload  $payload
     * @return void
     */
    public function released($connection, $queue, JobPayload $payload)
    {
        $this->connection()->hmset(
            $payload->id(), [
                'status' => 'pending',
                'payload' => $payload->value,
                'updated_at' => str_replace(',', '.', microtime(true)),
            ]
        );
    }

    /**
     * Mark the job as completed and monitored.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @param  \Laravel\Horizon\JobPayload  $payload
     * @return void
     */
    public function remember($connection, $queue, JobPayload $payload)
    {
        $this->storeJobReference($this->connection(), 'monitored_jobs', $payload);

        $this->connection()->hmset(
            $payload->id(), [
                'id' => $payload->id(),
                'connection' => $connection,
                'queue' => $queue,
                'name' => $payload->decoded['displayName'],
                'status' => 'completed',
                'payload' => $payload->value,
                'completed_at' => str_replace(',', '.', microtime(true)),
            ]
        );

        $this->connection()->expireat(
            $payload->id(), Chronos::now()->addMinutes($this->monitoredJobExpires)->getTimestamp()
        );
    }

    /**
     * Mark the given jobs as released / pending.
     *
     * @param  string  $connection
     * @param  string  $queue
     * @param  \Illuminate\Support\Collection  $payloads
     * @return void
     */
    public function migrated($connection, $queue, Collection $payloads)
    {
        foreach ($payloads as $payload) {
            $this->connection()->hmset(
                $payload->id(), [
                    'status' => 'pending',
                    'payload' => $payload->value,
                    'updated_at' => str_replace(',', '.', microtime(true)),
                ]
            );
        }
    }

    /**
     * Handle the storage of a completed job.
     *
     * @param  \Laravel\Horizon\JobPayload  $payload
     * @param  bool  $failed
     * @return void
     */
    public function completed(JobPayload $payload, $failed = false)
    {
        if ($payload->isRetry()) {
            $this->updateRetryInformationOnParent($payload, $failed);
        }

        $this->markJobAsCompleted($this->connection(), $payload->id(), $failed);
    }

    /**
     * Mark a given job as completed and set it to expire.
     *
     * @param  \Redis  $pipe
     * @param  string  $id
     * @param  bool  $failed
     * @return void
     */
    protected function markJobAsCompleted($pipe, $id, $failed)
    {
        $failed
            ? $pipe->hmset($id, ['status' => 'failed'])
            : $pipe->hmset($id, ['status' => 'completed', 'completed_at' => str_replace(',', '.', microtime(true))]);

        $pipe->expireat($id, Chronos::now()->addMinutes($this->recentCompletedExpires)->getTimestamp());
    }

    /**
     * Update the retry status of a job's parent.
     *
     * @param  \Laravel\Horizon\JobPayload  $payload
     * @param  bool  $failed
     * @return void
     */
    protected function updateRetryInformationOnParent(JobPayload $payload, $failed)
    {
        if ($retries = $this->connection()->hget($payload->retryOf(), 'retried_by')) {
            $retries = $this->updateRetryStatus(
                $payload, json_decode($retries, true), $failed
            );

            $this->connection()->hset(
                $payload->retryOf(), 'retried_by', json_encode($retries)
            );
        }
    }

    /**
     * Update the retry status of a job in a retry array.
     *
     * @param  \Laravel\Horizon\JobPayload  $payload
     * @param  array  $retries
     * @param  bool  $failed
     * @return array
     */
    protected function updateRetryStatus(JobPayload $payload, $retries, $failed)
    {
        return collect($retries)->map(function ($retry) use ($payload, $failed) {
            return $retry['id'] === $payload->id()
                    ? Arr::set($retry, 'status', $failed ? 'failed' : 'completed')
                    : $retry;
        })->all();
    }

    /**
     * Delete the given monitored jobs by IDs.
     *
     * @param  array  $ids
     * @return void
     */
    public function deleteMonitored(array $ids)
    {
        foreach ($ids as $id) {
            $this->connection()->expireat($id, Chronos::now()->addDays(7)->getTimestamp());
        }
    }

    /**
     * Trim the recent job list.
     *
     * @return void
     */
    public function trimRecentJobs()
    {
        $score = Chronos::now()->subMinutes($this->recentJobExpires)->getTimestamp() * -1;

        $this->connection()->zremrangebyscore('recent_jobs', $score, '+inf');

        if ($this->recentJobExpires !== $this->recentFailedJobExpires) {
            $score = Chronos::now()->subMinutes($this->recentFailedJobExpires)->getTimestamp() * -1;
        }

        $this->connection()->zremrangebyscore('recent_failed_jobs', $score, '+inf');
    }

    /**
     * Trim the failed job list.
     *
     * @return void
     */
    public function trimFailedJobs()
    {
        $this->connection()->zremrangebyscore(
            'failed_jobs', Chronos::now()->subMinutes($this->failedJobExpires)->getTimestamp() * -1, '+inf'
        );
    }

    /**
     * Trim the monitored job list.
     *
     * @return void
     */
    public function trimMonitoredJobs()
    {
        $this->connection()->zremrangebyscore(
            'monitored_jobs', Chronos::now()->subMinutes($this->monitoredJobExpires)->getTimestamp() * -1, '+inf'
        );
    }

    /**
     * Find a failed job by ID.
     *
     * @param  string  $id
     * @return \stdClass|null
     */
    public function findFailed($id)
    {
        $attributes = $this->connection()->hmget(
            $id, $this->keys
        );

        $job = is_array($attributes) && $attributes[0] !== null ? (object) array_combine($this->keys, $attributes) : null;

        if ($job && $job->status !== 'failed') {
            return;
        }

        return $job;
    }

    /**
     * Mark the job as failed.
     *
     * @param  string  $exception
     * @param  string  $connection
     * @param  string  $queue
     * @param  \Laravel\Horizon\JobPayload  $payload
     * @return void
     */
    public function failed($exception, $connection, $queue, JobPayload $payload)
    {
        $this->storeJobReference($this->connection(), 'failed_jobs', $payload);
        $this->storeJobReference($this->connection(), 'recent_failed_jobs', $payload);

        $this->connection()->hmset(
            $payload->id(), [
                'id' => $payload->id(),
                'connection' => $connection,
                'queue' => $queue,
                'name' => $payload->decoded['displayName'],
                'status' => 'failed',
                'payload' => $payload->value,
                'exception' => (string) $exception,
                'failed_at' => str_replace(',', '.', microtime(true)),
            ]
        );

        $this->connection()->expireat(
            $payload->id(), Chronos::now()->addMinutes($this->failedJobExpires)->getTimestamp()
        );
    }

    /**
     * Store the look-up references for a job.
     *
     * @param  mixed  $pipe
     * @param  string  $key
     * @param  \Laravel\Horizon\JobPayload  $payload
     * @return void
     */
    protected function storeJobReference($pipe, $key, JobPayload $payload)
    {
        $pipe->zadd($key, str_replace(',', '.', microtime(true) * -1), $payload->id());
    }

    /**
     * Store the retry job ID on the original job record.
     *
     * @param  string  $id
     * @param  string  $retryId
     * @return void
     */
    public function storeRetryReference($id, $retryId)
    {
        $retries = json_decode($this->connection()->hget($id, 'retried_by') ?: '[]');

        $retries[] = [
            'id' => $retryId,
            'status' => 'pending',
            'retried_at' => Chronos::now()->getTimestamp(),
        ];

        $this->connection()->hmset($id, ['retried_by' => json_encode($retries)]);
    }

    /**
     * Delete a failed job by ID.
     *
     * @param  string  $id
     * @return int
     */
    public function deleteFailed($id)
    {
        $this->connection()->zrem('failed_jobs', $id);

        $this->connection()->del($id);
    }

    /**
     * Get the Redis connection instance.
     *
     * @return \Illuminate\Redis\Connections\Connection
     */
    protected function connection()
    {
        return $this->redis->connection('horizon');
    }
}
