<?php

namespace Spatie\Async\Runtime;

use Closure;
use Spatie\Async\Pool;
use Spatie\Async\Process\Runnable;
use function Opis\Closure\serialize;
use Opis\Closure\SerializableClosure;
use function Opis\Closure\unserialize;
use Symfony\Component\Process\Process;
use Spatie\Async\Process\ParallelProcess;
use Spatie\Async\Process\SynchronousProcess;

class ParentRuntime
{
    /** @var bool */
    protected static $isInitialised = false;

    /** @var string */
    protected static $autoloader;

    /** @var string */
    protected static $childProcessScript;

    protected static $currentId = 0;

    protected static $myPid = null;

    public static function init(string $autoloader = null)
    {
        if (! $autoloader) {
            $existingAutoloaderFiles = array_filter([
                __DIR__.'/../../../../autoload.php',
                __DIR__.'/../../../autoload.php',
                __DIR__.'/../../vendor/autoload.php',
                __DIR__.'/../../../vendor/autoload.php',
            ], function (string $path) {
                return file_exists($path);
            });

            $autoloader = reset($existingAutoloaderFiles);
        }

        self::$autoloader = $autoloader;
        self::$childProcessScript = __DIR__.'/ChildRuntime.php';

        self::$isInitialised = true;
    }

    /**
     * @param \Spatie\Async\Task|callable $task
     * @param int|null $outputLength
     *
     * @return \Spatie\Async\Process\Runnable
     */
    public static function createProcess($task, ?int $outputLength = null): Runnable
    {
        if (! self::$isInitialised) {
            self::init();
        }

        if (! Pool::isSupported()) {
            return SynchronousProcess::create($task, self::getId());
        }

        $file = tempnam("/tmp", "async"); // create a temporary file with our input
        chmod($file, 0744); // make sure it can be read by the subprocess
        file_put_contents($file, self::encodeTask($task)); // write our input to this temporary file

        $process = new Process([
            'php',
            self::$childProcessScript,
            self::$autoloader,
            $file,
            $outputLength,
        ]);

        return ParallelProcess::create($process, self::getId());
    }

    /**
     * @param \Spatie\Async\Task|callable $task
     *
     * @return string
     */
    public static function encodeTask($task): string
    {
        if ($task instanceof Closure) {
            $task = new SerializableClosure($task);
        }

        return base64_encode(serialize($task));
    }

    public static function decodeTask(string $task)
    {
        return unserialize(base64_decode($task));
    }

    protected static function getId(): string
    {
        if (self::$myPid === null) {
            self::$myPid = getmypid();
        }

        self::$currentId += 1;

        return (string) self::$currentId.(string) self::$myPid;
    }
}
