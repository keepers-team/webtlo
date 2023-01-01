<?php

namespace KeepersTeam\Webtlo\Routes;

use KeepersTeam\Webtlo\DB;
use KeepersTeam\Webtlo\Settings;
use Psr\Log\LoggerInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class LegacyRouter
{
    private ContainerInterface $container;
    private LoggerInterface $logger;
    private DB $db;

    private static string $legacyIndex = (
        __DIR__
        . DIRECTORY_SEPARATOR . '..'
        . DIRECTORY_SEPARATOR . 'legacy'
        . DIRECTORY_SEPARATOR . 'index.php'
    );

    private static string $legacyActionsPath = (
        __DIR__
        . DIRECTORY_SEPARATOR . '..'
        . DIRECTORY_SEPARATOR . 'legacy'
        . DIRECTORY_SEPARATOR . 'php'
        . DIRECTORY_SEPARATOR . 'actions'
        . DIRECTORY_SEPARATOR
    );

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
        $this->logger = $container->get('logger');
        $this->db = $container->get('db');
    }

    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Implicitly used inside template
        $webtlo = $this->container->get('webtlo_version');
        $cfg = Settings::populate($this->container->get('ini'), $this->db);

        ob_start();
        require self::$legacyIndex;
        $output = ob_get_clean();

        $response->getBody()->write($output);
        return $response;
    }
}
