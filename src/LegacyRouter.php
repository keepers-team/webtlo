<?php

namespace KeepersTeam\Webtlo;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class LegacyRouter
{
    private ContainerInterface $container;

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
        require __DIR__ . DIRECTORY_SEPARATOR . 'legacy' . DIRECTORY_SEPARATOR . 'index.php';
        $output = ob_get_clean();

        $response->getBody()->write($output);
        return $response;
    }
}
