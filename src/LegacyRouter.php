<?php

namespace KeepersTeam\Webtlo;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class LegacyRouter
{
    public function home(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        ob_start();
        require __DIR__ . DIRECTORY_SEPARATOR . 'legacy' . DIRECTORY_SEPARATOR . '_index.php';
        $output = ob_get_clean();

        $response->getBody()->write($output);
        return $response;
    }

}
