<?php

namespace App\Services\GitHub;

/**
 * Thrown for any GitHub App API failure — not-configured, HTTP error, or
 * malformed response. Controllers catch this and translate it to a
 * friendly, Persian, non-leaking message; the raw GitHub error detail
 * (never a credential — only status codes and GitHub's own message field)
 * is kept on the exception for logging.
 */
class GitHubApiException extends \RuntimeException
{
}
