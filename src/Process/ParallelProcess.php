<?php

namespace Spatie\Async\Process;

use Throwable;
use Spatie\Async\Output\ParallelError;
use Symfony\Component\Process\Process;
use Spatie\Async\Output\SerializableException;

class ParallelProcess implements Runnable
{
    protected $process;
    protected $id;
    protected $pid;

    protected $output;
    protected $errorOutput;

    protected $startTime;

    use ProcessCallbacks;

    public function __construct(Process $process, int $id)
    {
        $this->process = $process;
        $this->id = $id;
    }

    public static function create(Process $process, int $id): self
    {
        return new self($process, $id);
    }

    public function start(): self
    {
        $this->startTime = microtime(true);

        $this->process->start();
        //echo "\nCommand: " . $this->process->getCommandLine() . "\n";

        $this->pid = $this->process->getPid();

        return $this;
    }

    public function stop(): self
    {
        $this->process->stop(10, SIGKILL);

        return $this;
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function isSuccessful(): bool
    {
        return $this->process->isSuccessful();
    }

    public function isTerminated(): bool
    {
        return $this->process->isTerminated();
    }

    /**
     * Flush the process's stdout output buffer (it is then kept by Symfony's copy of stdout).
     * Due to stdout output buffering, it is possible for a program to hang once it fills the buffer for stdout. At this point, the program wouldn't do anything else. By forcing a check on the program's output, we can ensure that we actually read through the output buffer.
     */
    public function seekOutput()
    {
        try {
            $this->process->getOutput();
        } catch (\Throwable $ex) {} // There's one particular exception that can occur her,e related to the symfony process trying to invoke a callback function that was made null. I believe this happens in a rare race condition where the SIGCHILD call is fired while getOutput() is being run. getOutput() then runs twice with slightly desynchronized state, causing the exception.
    }

    public function getOutput()
    {
        if (! $this->output) {
            $processOutput = $this->process->getOutput();

            $this->output = @unserialize(base64_decode($processOutput));

            if (! $this->output) {
                $this->errorOutput = $processOutput;
            }
        }

        return $this->output;
    }

    public function getErrorOutput()
    {
        if (! $this->errorOutput) {
            $processOutput = $this->process->getErrorOutput();

            //echo "Deserialized Error: ";
            //echo base64_decode($processOutput);
            //echo "\n";

            $this->errorOutput = @unserialize(base64_decode($processOutput));

            if (! $this->errorOutput) {
                $this->errorOutput = $processOutput;
            }
        }

        return $this->errorOutput;
    }

    public function getProcess(): Process
    {
        return $this->process;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPid(): ?int
    {
        return $this->pid;
    }

    public function getCurrentExecutionTime(): float
    {
        return microtime(true) - $this->startTime;
    }

    protected function resolveErrorOutput(): Throwable
    {
        $exception = $this->getErrorOutput();

        if (! $exception instanceof Throwable) {
            $exception = ParallelError::fromException((string) $exception);
        }

        return $exception;
    }
}
