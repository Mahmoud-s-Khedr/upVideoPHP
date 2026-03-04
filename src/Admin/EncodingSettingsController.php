<?php

declare(strict_types=1);

namespace VideoSystem\Admin;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use VideoSystem\Database\Connection;
use VideoSystem\Encoding\RenditionLadder;

/**
 * Admin UI for the encoding rendition ladder.
 *
 * GET  /admin/encoding-settings  — Display the per-tier form
 * POST /admin/encoding-settings  — Save updated tier parameters
 */
final class EncodingSettingsController
{
    public function form(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $rows = Connection::fetchAll(
            'SELECT label, position, width, height, crf, vbitrate, abitrate
               FROM rendition_ladder
              ORDER BY position ASC, id ASC'
        );

        $twig = TwigFactory::create();
        $html = $twig->render('encoding-settings.twig', [
            'tiers' => $rows,
        ]);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    public function save(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];

        if (!TwigFactory::validateCsrf($body['_csrf'] ?? '')) {
            TwigFactory::flash('error', 'Invalid CSRF token.');
            return $this->redirect($response, '/admin/encoding-settings');
        }

        $tiers = isset($body['tiers']) && is_array($body['tiers']) ? $body['tiers'] : [];

        if (empty($tiers)) {
            TwigFactory::flash('error', 'No tier data submitted.');
            return $this->redirect($response, '/admin/encoding-settings');
        }

        $errors = [];

        foreach ($tiers as $label => $params) {
            $label = (string) $label;

            $width    = isset($params['width'])    ? (int) $params['width']    : 0;
            $height   = isset($params['height'])   ? (int) $params['height']   : 0;
            $crf      = isset($params['crf'])      ? (int) $params['crf']      : 0;
            $vbitrate = isset($params['vbitrate']) ? trim((string) $params['vbitrate']) : '';
            $abitrate = isset($params['abitrate']) ? trim((string) $params['abitrate']) : '';

            if ($width < 1 || $width > 7680) {
                $errors[] = "{$label}: width must be between 1 and 7680.";
                continue;
            }
            if ($height < 1 || $height > 4320) {
                $errors[] = "{$label}: height must be between 1 and 4320.";
                continue;
            }
            if ($crf < 0 || $crf > 63) {
                $errors[] = "{$label}: CRF must be between 0 and 63.";
                continue;
            }
            if ($vbitrate === '' || !preg_match('/^\d+k$/i', $vbitrate)) {
                $errors[] = "{$label}: video bitrate must be in the format '3000k'.";
                continue;
            }
            if ($abitrate === '' || !preg_match('/^\d+k$/i', $abitrate)) {
                $errors[] = "{$label}: audio bitrate must be in the format '192k'.";
                continue;
            }

            Connection::execute(
                'UPDATE rendition_ladder
                    SET width    = :width,
                        height   = :height,
                        crf      = :crf,
                        vbitrate = :vbitrate,
                        abitrate = :abitrate
                  WHERE label    = :label',
                [
                    ':width'    => $width,
                    ':height'   => $height,
                    ':crf'      => $crf,
                    ':vbitrate' => strtolower($vbitrate),
                    ':abitrate' => strtolower($abitrate),
                    ':label'    => $label,
                ]
            );
        }

        // Flush the in-process cache so subsequent requests and encode jobs
        // pick up the updated values immediately.
        RenditionLadder::invalidate();

        if (!empty($errors)) {
            TwigFactory::flash('error', implode(' ', $errors));
        } else {
            TwigFactory::flash('success', 'Encoding settings saved successfully.');
        }

        return $this->redirect($response, '/admin/encoding-settings');
    }

    private function redirect(ResponseInterface $response, string $url): ResponseInterface
    {
        return $response->withStatus(302)->withHeader('Location', $url);
    }
}
