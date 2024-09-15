<?php

declare(strict_types=1);

namespace KeepersTeam\Webtlo\Module;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use KeepersTeam\Webtlo\TIniFileEx;
use KeepersTeam\Webtlo\WebTLO;

final class ProbeChecker
{
    private Client $client;

    private const CHECK_URL = [
        'forum'  => 'https://%s/forum/info.php?show=copyright_holders',
        'api'    => 'https://%s/v1/get_client_ip',
        'report' => 'https://%s/krs/api/v1/info/statuses',
    ];

    /**
     * @param string[][]                  $urls
     * @param (null|array{string, int})[] $proxies
     */
    public function __construct(
        private readonly array $urls,
        private readonly array $proxies,
    ) {
        $this->client = new Client([
            'timeout' => 5,
            'verify'  => false,
            'headers' => [
                'User-Agent' => 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/55.0.2883.87 Safari/537.36',
            ],
        ]);
    }

    public function printProbe(): string
    {
        $allUrls   = array_merge(...array_values($this->urls));
        $urlLength = (int)max(array_map('strlen', $allUrls));

        $proxyNames  = array_map(fn($proxy) => $this->getNullSafeProxy($proxy), $this->proxies);
        $proxyLength = (int)max(array_map('strlen', $proxyNames));

        $output = str_pad('Domain', $urlLength);
        foreach ($proxyNames as $proxy) {
            $output .= " | " . str_pad($proxy, $proxyLength);
        }
        $output .= "\r\n";


        foreach ($this->urls as $type => $urls) {
            foreach ($urls as $url) {
                $output .= str_pad($url, $urlLength);
                foreach ($this->proxies as $proxy) {
                    $uri   = $this->getUrl((string)$type, $url);
                    $code  = $this->getUrlHttpCode($uri, $proxy);
                    $emoji = (($code < 300 && $code > 0) || $code === 401) ? "✅" : "❌";

                    $output .= " | " . str_pad($emoji . " " . $code, $proxyLength);
                }

                $output .= "\r\n";
            }
        }

        $output .= "\r\n" . json_encode($this->getAbout(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $output;
    }

    /**
     * @return array<string, mixed>
     */
    private function getAbout(): array
    {
        $config = $this->getConfig();
        $webtlo = WebTLO::getVersion();

        $parsed = [
            'forum_url' => $config['torrent-tracker']['forum_url'] == 'custom'
                ? $config['torrent-tracker']['forum_url_custom']
                : $config['torrent-tracker']['forum_url'],
            'forum_ssl' => $config['torrent-tracker']['forum_ssl'],
            'api_url'   => $config['torrent-tracker']['api_url'] == 'custom'
                ? $config['torrent-tracker']['api_url_custom']
                : $config['torrent-tracker']['api_url'],
            'api_ssl'   => $config['torrent-tracker']['api_ssl'],
        ];

        if ($config['proxy']['activate_forum'] == 1 || $config['proxy']['activate_api'] == 1) {
            $parsed['proxy']['url']  = $config['proxy']['hostname'] . ":" . $config['proxy']['port'];
            $parsed['proxy']['type'] = $config['proxy']['type'];
        }
        $parsed['proxy']['activate_forum'] = $config['proxy']['activate_forum'];
        $parsed['proxy']['activate_api']   = $config['proxy']['activate_api'];

        return [
            'version' => $webtlo->version,
            'about'   => $webtlo->getAbout(),
            'config'  => $parsed,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getConfig(): array
    {
        $config_path = (new TIniFileEx())::getFile();
        if (!file_exists($config_path)) {
            return [];
        }

        return (array)parse_ini_file($config_path, true);
    }

    /**
     * @param null|array{string, int} $proxy
     */
    private function getNullSafeProxy(?array $proxy): string
    {
        return $proxy ? '✅ ' . $proxy[0] : "❎ no proxy";
    }

    /**
     * @param null|array{string, int} $proxy
     */
    private function getUrlHttpCode(string $url, ?array $proxy): int
    {
        try {
            $options = [];
            if (null !== $proxy) {
                $options['proxy'] = [
                    'https' => sprintf('socks5://%s:%d', $proxy[0], $proxy[1]),
                ];
            }

            $response = $this->client->get($url, $options);

            return $response->getStatusCode();
        } catch (RequestException|GuzzleException $e) {
            return $e->getCode();
        }
    }

    private function getUrl(string $type, string $url): string
    {
        if (empty(self::CHECK_URL[$type])) {
            return '';
        }

        return sprintf(self::CHECK_URL[$type], $url);
    }
}
