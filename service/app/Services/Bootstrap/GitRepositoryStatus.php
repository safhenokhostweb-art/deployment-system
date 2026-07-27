<?php

namespace App\Services\Bootstrap;

final class GitRepositoryStatus
{
    /**
     * @param array<int, string> $changedFiles
     */
    public function __construct(
        public readonly bool $exists,
        public readonly bool $isClean,
        public readonly ?string $remoteUrl,
        public readonly ?string $currentBranch,
        public readonly array $changedFiles,
    ) {
    }
}
