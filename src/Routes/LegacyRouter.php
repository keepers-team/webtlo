<?php

namespace KeepersTeam\Webtlo\Routes;

use KeepersTeam\Webtlo\Settings;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class LegacyRouter
{
    private ContainerInterface $container;
    private static string $legacyAppDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'legacy';

    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }

    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        // Implicitly used inside template
        $webtlo = $this->container->get('webtlo_version');
        $cfg = Settings::populate($this->container->get('ini'), $this->container->get('db'));

        ob_start();
        require self::$legacyAppDir . DIRECTORY_SEPARATOR . 'index.php';
        $output = ob_get_clean();

        $response->getBody()->write($output);
        return $response;
    }
}
