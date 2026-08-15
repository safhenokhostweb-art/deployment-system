<?php

declare(strict_types=1);

namespace LocalAgent;

/** A deliberately small, declarative local build contract. No command text is accepted. */
final class BuildProfile
{
    /** @param array<string, string> $environment @param array<int, string> $requiredPaths */
    private function __construct(
        public readonly string $stack,
        public readonly bool $composer,
        public readonly bool $npmInstall,
        public readonly bool $npmBuild,
        public readonly bool $nextStandalone,
        public readonly bool $prismaGenerate,
        public readonly ?string $nextAppPath,
        public readonly array $environment,
        public readonly array $requiredPaths,
    ) {}

    /** @param array<string, mixed>|null $raw */
    public static function fromConfig(?array $raw): ?self
    {
        if ($raw === null) return null; // legacy auto-detection remains exact.
        $allowed = ['stack', 'composer', 'npm_install', 'npm_build', 'next_standalone', 'prisma_generate', 'next_app_path', 'environment', 'artifact_required_paths'];
        if (array_diff(array_keys($raw), $allowed) !== []) throw new LocalBuildFailedException('invalid_build_profile', 'Build profile contains an unsupported key.');
        $stack = $raw['stack'] ?? null;
        if (!is_string($stack) || !in_array($stack, ['laravel', 'node', 'next_standalone'], true)) throw new LocalBuildFailedException('invalid_build_profile', 'Build profile stack must be laravel, node, or next_standalone.');
        foreach (['composer', 'npm_install', 'npm_build', 'next_standalone', 'prisma_generate'] as $key) if (!is_bool($raw[$key] ?? null)) throw new LocalBuildFailedException('invalid_build_profile', "Build profile {$key} must be boolean.");
        if (($raw['npm_build'] ?? false) && !($raw['npm_install'] ?? false)) throw new LocalBuildFailedException('invalid_build_profile', 'npm_build requires npm_install:true.');
        if (($raw['next_standalone'] ?? false) !== ($stack === 'next_standalone')) throw new LocalBuildFailedException('invalid_build_profile', 'next_standalone must match stack next_standalone.');
        $nextAppPath = $raw['next_app_path'] ?? null;
        if ($raw['next_standalone'] && (!is_string($nextAppPath) || !self::safeRelative($nextAppPath))) throw new LocalBuildFailedException('invalid_build_profile', 'next_standalone requires a safe relative next_app_path.');
        if (!$raw['next_standalone'] && $nextAppPath !== null) throw new LocalBuildFailedException('invalid_build_profile', 'next_app_path is only valid for next_standalone.');
        $env = $raw['environment'] ?? [];
        if (!is_array($env)) throw new LocalBuildFailedException('invalid_build_profile', 'Build profile environment must be a map.');
        foreach ($env as $name => $value) if (!is_string($name) || !preg_match('/^(?:NEXT_PUBLIC_|VITE_|NODE_ENV$)/', $name) || preg_match('/(?:SECRET|TOKEN|PASSWORD|KEY)/i', $name) || !is_string($value) || strlen($value) > 2000) throw new LocalBuildFailedException('invalid_build_profile', 'Build profile environment only permits public NEXT_PUBLIC_ / VITE_ values or NODE_ENV.');
        $paths = $raw['artifact_required_paths'] ?? [];
        if (!is_array($paths) || !array_is_list($paths)) throw new LocalBuildFailedException('invalid_build_profile', 'artifact_required_paths must be a list.');
        foreach ($paths as $path) if (!is_string($path) || !self::safeRelative($path)) throw new LocalBuildFailedException('invalid_build_profile', 'artifact_required_paths contains an unsafe path.');
        return new self($stack, $raw['composer'], $raw['npm_install'], $raw['npm_build'], $raw['next_standalone'], $raw['prisma_generate'], $nextAppPath, $env, array_values(array_unique($paths)));
    }
    private static function safeRelative(string $path): bool { return $path !== '' && !str_starts_with($path, '/') && preg_match('/^[A-Za-z]:[\\\\\/]/', $path) !== 1 && !in_array('..', preg_split('#[\\\\/]#', $path) ?: [], true) && !str_contains($path, "\0"); }
}
