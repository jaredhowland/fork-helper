<?php

namespace duncan3dc\Forker;

/**
 * Class to make multi-threaded processes easier.
 */
class Fork
{
    /**
     * How much shared memory to allocate for exception messages.
     */
    const SHARED_MEMORY_LIMIT = 1000;

    /**
     * @var array $threads The threads created.
     */
    private $threads = [];

    /**
     * @var int $memoryKey The key to use for the shared memory.
     */
    private $memoryKey;


    /**
     * Create a container to run multiple threads.
     */
    public function __construct()
    {
        $this->memoryKey = round(microtime(true) * 1000);
    }


    /**
     * Run some code in a thread.
     *
     * @param callable $func The function to execute
     * @param array|mixed $args The arguments (or a single argument) to pass to the function
     *
     * @return int The pid of the thread created to execute this code
     */
    public function call(callable $func, $args = null)
    {
        $pid = pcntl_fork();

        if ($pid == -1) {
            throw new Exception("Failed to fork");
        }

        # If this is the child process, then run the requested function
        if (!$pid) {
            try {
                if ($args === null) {
                    $func();
                } else {
                    call_user_func_array($func, $args);
                }
            } catch (\Throwable $e) {
                $memory = shmop_open($this->memoryKey, "c", 0644, static::SHARED_MEMORY_LIMIT);
                $errors = shmop_read($memory, 0, static::SHARED_MEMORY_LIMIT);
                $errors = trim($errors);
                if ($errors) {
                    $errors .= "\n";
                }
                $errors .= "Exception: " . $e->getMessage() . " (" . $e->getFile() . ":" . $e->getLine() . ")";
                shmop_write($memory, $errors, 0);
                shmop_close($memory);
                exit(1);
            }

            # Then we must exit or else we will end up the child process running the parent processes code
            die();
        }

        $this->threads[$pid] = $pid;

        return $pid;
    }


    /**
     * Wait for the processes started via call().
     *
     * @param int $pid The pid to wait for, if none is passed then all threads created by this object will be waited for
     *
     * @return void
     */
    public function wait($pid = null)
    {
        if ($pid) {
            $threads = [$pid];
        } else {
            $threads = $this->threads;
        }

        $error = 0;
        $status = 0;
        foreach ($threads as $pid) {
            pcntl_waitpid($pid, $status);
            if ($status > 0) {
                $error = $status;
            }
            unset($this->threads[$pid]);
        }

        # If no errors occured then we're done
        if ($error === 0) {
            return;
        }

        $memory = shmop_open($this->memoryKey, "a", 0, 0);
        $errors = shmop_read($memory, 0, static::SHARED_MEMORY_LIMIT);
        shmop_delete($memory);
        shmop_close($memory);

        $message = "An error occurred within a thread, the return code was: {$error}";
        if ($errors = trim($errors)) {
            $message .= "\n{$errors}";
        }
        throw new Exception($message, $error);
    }


    /**
     * Get forks' PIDs.
     *
     * @return int[]
     */
    public function getPIDs()
    {
        return array_values($this->threads);
    }


    /**
     * If no call to wait() is made, then we wait for the threads on destruct.
     */
    public function __destruct()
    {
        $this->wait();
    }
}
