<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\External\Shared;

use GuzzleHttp\Psr7\Header;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

trait Validation
{
    protected static string $jsonMime    = 'application/json';
    protected static string $torrentMime = 'application/x-bittorrent';
    protected static string $webMime     = 'text/html';

    /**
     * Check response for correctness
     *
     * @param LoggerInterface   $logger       Logger
     * @param ResponseInterface $response     Received response
     * @param string            $expectedMime Expected MIME
     * @return bool
     */
    protected static function isValidMime(
        LoggerInterface   $logger,
        ResponseInterface $response,
        string            $expectedMime
    ): bool {
        $type = $response->getHeader('content-type');
        if (empty($type)) {
            $logger->warning('No content-type found');

            return false;
        }

        $parsed = Header::parse($type);
        if (!isset($parsed[0][0])) {
            $logger->warning('Broken content-type header');

            return false;
        }

        $receivedMime = $parsed[0][0];
        if ($receivedMime !== $expectedMime) {
            $logger->warning('Unknown mime', ['expected' => $expectedMime, 'received' => $receivedMime]);

            return false;
        }

        return true;
    }
}
