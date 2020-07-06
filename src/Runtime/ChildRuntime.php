<?php

use Spatie\Async\Runtime\ParentRuntime;

try {
    $autoloader = $argv[1] ?? null;
    $serializedClosureFile = $argv[2] ?? null;
    $outputLength = $argv[3] ? intval($argv[3]) : -1;

    if (! $autoloader) {
        throw new InvalidArgumentException('No autoloader provided in child process.');
    }

    if (! file_exists($autoloader)) {
        throw new InvalidArgumentException("Could not find autoloader in child process: {$autoloader}");
    }

    if (!$serializedClosureFile) {
        throw new \InvalidArgumentException("No file was passed to load the closure from.");
    }
    if (!file_exists($serializedClosureFile)) {
        throw new \InvalidArgumentException("The serialized closure does not exist.");
    }

    $serializedClosure = file_get_contents($serializedClosureFile);
    if (!unlink($serializedClosureFile)) {
        throw new \RuntimeException("Unable to delete the serialized closure file.");
    }
    if (!$serializedClosure) {
        throw new InvalidArgumentException("The serialized closure file contents were empty or could not be read.");
    }

    require_once $autoloader;

    $task = ParentRuntime::decodeTask($serializedClosure);

    $output = call_user_func($task);

    $serializedOutput = base64_encode(serialize($output));

    if ($outputLength >= 0 && strlen($serializedOutput) > $outputLength) {
        throw \Spatie\Async\Output\ParallelError::outputTooLarge($outputLength);
    }

    fwrite(STDOUT, $serializedOutput);

    exit(0);
} catch (Throwable $exception) {
    ob_end_clean();

    fwrite(STDERR, base64_encode(serialize($exception)));

    exit(1);
}
