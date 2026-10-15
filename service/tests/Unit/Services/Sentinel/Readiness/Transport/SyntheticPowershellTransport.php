<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sentinel\Readiness\Transport;

use App\Services\Sentinel\Readiness\Transport\PowershellAllowedScripts;
use App\Services\Sentinel\Readiness\Transport\PowershellExecutionResult;
use App\Services\Sentinel\Readiness\Transport\PowershellObservationTransport;

/**
 * A synthetic PowershellObservationTransport that returns canned
 * PowershellExecutionResult objects instead of launching any process.
 *
 * It touches nothing on the host. It exists so the
 * WindowsHostReadinessObserver can be exercised against controlled
 * transport outputs: clean runs, nonzero exits, timeouts, launch
 * failures and oversized stdout.
 *
 * It also records every execute() call so a test can assert that only
 * an allowlisted script token was requested and that caller values
 * travelled through the environment channel, never the command line.
 */
final class SyntheticPowershellTransport extends PowershellObservationTransport
{
    public array $calls = [];

    /**
     * FIFO queue of results: the first queued result is consumed by
     * the next execute() call, then the one after it, and so on.
     *
     * @var array<int, PowershellExecutionResult>
     */
    public array $queued = [];

    public ?PowershellExecutionResult $repeat = null;

    public bool $refuseUnallowlistedTokens = true;

    public function __construct()
    {
        parent::__construct('C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe');
    }

    /**
     * Queue a result for the next execute() call. Calls are consumed
     * in FIFO order, so a test can sequence the several probes a
     * checker makes by calling this repeatedly.
     */
    public function nextResult(PowershellExecutionResult $result): self
    {
        $this->queued[] = $result;

        return $this;
    }

    /**
     * Set the result returned for every execute() call that has no
     * queued result. Used when a test does not care about ordering.
     */
    public function repeatResult(PowershellExecutionResult $result): self
    {
        $this->repeat = $result;

        return $this;
    }

    public function execute(string $scriptToken, array $values = []): PowershellExecutionResult
    {
        $this->calls[] = [
            'token' => $scriptToken,
            'values' => $values,
            'allowlisted' => isset(PowershellAllowedScripts::all()[$scriptToken]),
        ];

        if ($this->refuseUnallowlistedTokens && ! isset(PowershellAllowedScripts::all()[$scriptToken])) {
            throw new \InvalidArgumentException(
                'SyntheticPowershellTransport refuses an unallowlisted script token.',
            );
        }

        if ($this->queued !== []) {
            return array_shift($this->queued);
        }

        if ($this->repeat !== null) {
            return $this->repeat;
        }

        return PowershellExecutionResult::completed(0, '', '', 0.0, null);
    }

    /**
     * The number of times execute() was invoked.
     */
    public function callCount(): int
    {
        return count($this->calls);
    }

    /**
     * Every script token that was requested and reached an execution,
     * in order. A refused token is excluded because no script text was
     * ever resolved for it.
     *
     * @return array<int, string>
     */
    public function completedTokens(): array
    {
        return array_values(array_map(
            static fn (array $call) => $call['token'],
            array_values(array_filter(
                $this->calls,
                static fn (array $call) => $call['allowlisted'] === true,
            )),
        ));
    }

    /**
     * Every script token that was requested, in order.
     *
     * @return array<int, string>
     */
    public function tokens(): array
    {
        return array_map(static fn (array $call) => $call['token'], $this->calls);
    }
}
