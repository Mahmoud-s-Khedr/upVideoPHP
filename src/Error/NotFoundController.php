<?php

declare(strict_types=1);

namespace VideoSystem\Error;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class NotFoundController
{
    public function html(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $twig = new Environment(
            new FilesystemLoader(__DIR__ . '/../../templates/errors'),
            [
                'cache'       => false,
                'auto_reload' => true,
                'autoescape'  => 'html',
            ]
        );

        $html = $twig->render('404.twig', [
            'path' => $request->getUri()->getPath(),
        ]);

        $response->getBody()->write($html);
        return $response
            ->withStatus(404)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function json(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $response->getBody()->write(json_encode([
            'error'   => 'NOT_FOUND',
            'message' => 'Route not found.',
        ], JSON_THROW_ON_ERROR));

        return $response
            ->withStatus(404)
            ->withHeader('Content-Type', 'application/json');
    }

    public function favicon(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $icoPath = __DIR__ . '/../../public/favicon.ico';
        $svgPath = __DIR__ . '/../../public/favicon.svg';

        if (is_file($icoPath)) {
            $icon = file_get_contents($icoPath);
            if ($icon !== false) {
                $response->getBody()->write($icon);
                return $response
                    ->withHeader('Content-Type', 'image/x-icon')
                    ->withHeader('Cache-Control', 'public, max-age=86400');
            }
        }

        if (is_file($svgPath)) {
            $icon = file_get_contents($svgPath);
            if ($icon !== false) {
                $response->getBody()->write($icon);
                return $response
                    ->withHeader('Content-Type', 'image/svg+xml')
                    ->withHeader('Cache-Control', 'public, max-age=86400');
            }
        }

        return $response->withStatus(204);
    }
}
