<?php namespace Davelip\Queue;

use DateTime;
use Carbon\Carbon;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Queue\Queue;
use Illuminate\Queue\QueueInterface;
use Davelip\Queue\Jobs\DatabaseJob;
use Davelip\Queue\Models\Job;

class DatabaseQueue extends Queue implements QueueInterface
{
    /**
    * The database connection instance.
    *
     * @var \Illuminate\Database\Connection
     */
    protected $database;

    /**
     * The database table that holds the jobs.
     *
     * @var string
     */
    protected $table;

    /**
     * The name of the default queue.
     *
     * @var string
     */
    protected $default;

    /**
     * The expiration time of a job.
     *
     * @var int|null
     */
    protected $expire = 60;

    /**
     * Create a new database queue instance.
     *
     * @param  \Illuminate\Database\Connection  $database
     * @param  string  $table
     * @param  string  $default
     * @param  int  $expire
     * @return void
     */
    public function __construct(Connection $database, $table, $default = 'default', $expire = 60)
    {
        $this->table = $table;
        $this->expire = $expire;
        $this->default = $default;
        $this->database = $database;
    }

    /**
     * Push a new job onto the queue.
     *
     * @param  string $job   job
     * @param  mixed  $data  payload of job
     * @param  string $queue queue name
     * @return mixed
     */
    public function push($job, $data = '', $queue = null)
    {
        $id = $this->storeJob($job, $data, $queue);

        return 0;
    }

    /**
     * Store the job in the database
     *
     * @param  string  $job         job
     * @param  mixed   $data        payload of job
     * @param  string  $queue       queue name
     * @param  integer $timestamp=0 timestamp
     * @return integer The id of the job
     */
    public function storeJob($job, $data, $queue, $timestamp = 0)
    {
        $payload = $this->createPayload($job, $data);

        $job = new Job();
        $job->queue = ($queue ? $queue : $this->default);
        $job->status = Job::STATUS_OPEN;
        $job->timestamp = date('Y-m-d H:i:s', ($timestamp != 0 ? $timestamp : time()));
        $job->payload = $payload;
        $job->save();

        return $job->id;
    }

    /**
     * Push a new job onto the queue after a delay.
     *
     * @param  \DateTime|int $delay
     * @param  string        $job
     * @param  mixed         $data
     * @param  string        $queue
     * @return mixed
     */
    public function later($delay, $job, $data = '', $queue = null)
    {
        $timestamp = time() + $this->getSeconds($delay);
        $id = $this->storeJob($job, $data, $queue, $timestamp);

        return 0;
    }

    /**
     * Pop the next job off of the queue.
     *
     * @param  string                          $queue queue name
     * @return \Illuminate\Queue\Jobs\Job|null
     */
    public function pop($queue = null)
    {
        $queue = $queue ? $queue : $this->default;

        $job = Job::where('timestamp', '<', date('Y-m-d H:i:s', time()))
            ->where('queue', '=', $queue)
            ->where(function (Builder $query) {
                $query->where('status', '=', Job::STATUS_OPEN);
                $query->orWhere('status', '=', Job::STATUS_WAITING);
            })
            ->orderBy('id')
            ->first()
            ;

        if (! is_null($job)) {
            return new DatabaseJob($this->container, $job, $queue);
        }
    }

    /**
     * Push a raw payload onto the queue.
     *
     * @param  string $payload
     * @param  string $queue
     * @param  array  $options
     * @return mixed
     */
    public function pushRaw($payload, $queue = null, array $options = array())
    {
        return $this->pushToDatabase(0, $queue, $payload);
    }

    /**
     * Push a raw payload to the database with a given delay.
     *
     * @param  \DateTime|int  $delay
     * @param  string|null  $queue
     * @param  string  $payload
     * @param  int  $attempts
     * @return mixed
     */
    protected function pushToDatabase($delay, $queue, $payload, $attempts = 0)
    {
        $availableAt = $delay instanceof DateTime ? $delay : Carbon::now()->addSeconds($delay);

        return $this->database->table($this->table)->insertGetId([
            'queue' => $this->getQueue($queue),
            'payload' => $payload,
            'retries' => $attempts,
            'timestamp' => $availableAt->getTimestamp(),
            'created_at' => $this->getTime(),
        ]);
    }

    /**
     * Get the queue or return the default.
     *
     * @param  string|null  $queue
     * @return string
     */
    protected function getQueue($queue)
    {
        return $queue ?: $this->default;
    }
}
