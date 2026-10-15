<?php

namespace App\Services\Sentinel;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use RuntimeException;

final class CurlPinnedHealthProbeTransport implements PinnedHealthProbeTransport
{
    public const CONNECT_TIMEOUT_SECONDS = 2;
    public const TOTAL_TIMEOUT_SECONDS = 5;

    public function get(string $url, string $host, int $port, string $address): int
    {
        if (! extension_loaded('curl') || ! defined('CURLOPT_RESOLVE')) {
            throw new RuntimeException('Pinned health-probe transport is unavailable.');
        }

        $stack = HandlerStack::create(new CurlHandler());
        $client = new Client([
            'handler' => $stack,
            ...self::requestOptions($host, $port, $address),
        ]);

        $response = $client->get($url, ['stream' => true]);
        $body = $response->getBody();
        $status = $response->getStatusCode();
        $body->close();

        return $status;
    }

    /** @return array<string, mixed> */
    public static function requestOptions(string $host, int $port, string $address): array
    {
        $pinnedAddress = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '['.$address.']'
            : $address;

        return [
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            'timeout' => self::TOTAL_TIMEOUT_SECONDS,
            'allow_redirects' => false,
            'http_errors' => false,
            'verify' => true,
            'proxy' => '',
            'curl' => [
                CURLOPT_RESOLVE => ["{$host}:{$port}:{$pinnedAddress}"],
                CURLOPT_PROXY => '',
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_NOPROXY => '*',
            ],
        ];
    }
}
