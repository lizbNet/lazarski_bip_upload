<?php

declare(strict_types=1);

namespace PrimeServices\LazarskiBipUpload\Conversion;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class SymfonyProcessRunner implements ProcessRunnerInterface
{
    public function run(array $command, ?int $timeoutSeconds): ProcessResult
    {
        $process = new Process($command);
        $process->setTimeout($timeoutSeconds);

        try {
            $process->run();

            return new ProcessResult(
                $process->getExitCode() ?? -1,
                $process->getOutput(),
                $process->getErrorOutput(),
                false
            );
        } catch (ProcessTimedOutException $exception) {
            return new ProcessResult(-1, '', $exception->getMessage(), true);
        } catch (\Throwable $exception) {
            // Covers a missing/non-executable binary (e.g. ProcessStartFailedException),
            // which Symfony surfaces as a thrown exception rather than a plain exit code.
            return new ProcessResult(-1, '', $exception->getMessage(), false);
        }
    }
}
