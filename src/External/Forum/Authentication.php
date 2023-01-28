<?php

namespace KeepersTeam\Webtlo\External\Forum;

use DOMDocument;
use GuzzleHttp\Cookie\CookieJar;
use KeepersTeam\Webtlo\Config\ApiCredentials;
use Psr\Log\LoggerInterface;

trait Authentication
{
    private static string $authCookieName = 'bb_session';

    private static function parseFormToken(string $page): ?string
    {
        libxml_use_internal_errors(use_errors: true);
        $result = null;
        $html = new DOMDocument();
        $html->loadHtml(source: $page);
        $dom = simplexml_import_dom($html);
        $nodes = $dom->xpath(expression: "/html/head/script[1]");
        if (count($nodes) === 1) {
            $matches = [];
            preg_match("|.*form_token[^']*'([^,]*)',.*|si", $nodes[0], $matches);
            if (count($matches) === 2) {
                $result = $matches[1];
            }
        }
        unset($nodes);
        unset($dom);
        unset($html);

        return $result;
    }

    protected static function parseApiCredentials(string $page): ?ApiCredentials
    {
        libxml_use_internal_errors(use_errors: true);
        $result = null;
        $html = new DOMDocument();
        $html->loadHtml(source: $page);
        $dom = simplexml_import_dom($html);
        $nodes = $dom->xpath(expression: "//table[contains(@class, 'user_details')]/tr[9]/td/b/text()");
        if (count($nodes) === 3) {
            $result = new ApiCredentials(
                userId: (string)$nodes[2],
                btKey: (string)$nodes[0],
                apiKey: (string)$nodes[1],
            );
        }
        unset($nodes);
        unset($dom);
        unset($html);

        return $result;
    }

    protected static function parseUserId(CookieJar $cookieJar, LoggerInterface $logger): ?int
    {
        $userCookie = $cookieJar->getCookieByName(self::$authCookieName);
        if (null === $userCookie) {
            $logger->error('No user cookie found');
            return null;
        }

        $rawID = $userCookie->getValue();
        if (null === $rawID) {
            $logger->error('Empty user cookie');
            return null;
        }
        $matches = [];
        preg_match("|[^-]*-([0-9]*)-.*|", $rawID, $matches);
        if (count($matches) !== 2 || false === filter_var($matches[1], FILTER_SANITIZE_NUMBER_INT)) {
            $logger->error('Malformed cookie', $userCookie->toArray());
            return null;
        }
        return (int)$matches[1];
    }
}
