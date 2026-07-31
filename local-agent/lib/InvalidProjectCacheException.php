<?php

declare(strict_types=1);

namespace LocalAgent;

/**
 * Thrown by ProjectConfigCache::load() when a cache file exists but is
 * corrupt/unreadable/structurally invalid — distinct from returning null,
 * which means "no cache yet" (not an error). Lets agent.php log an
 * explicit "invalid cache" event instead of conflating the two states.
 */
final class InvalidProjectCacheException extends \RuntimeException
{
}
