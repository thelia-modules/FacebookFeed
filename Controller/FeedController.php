<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FacebookFeed\Controller;

use FacebookFeed\Service\FacebookFeedService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\Lang;

class FeedController extends BaseFrontController
{
    /**
     * The feed of the language of the domain. Without a generated file the answer is an explicit
     * error, never a page of the shop: the reader of the feed would take it for the feed.
     */
    #[Route('/facebookfeed/feed', name: 'FacebookFeed_csv', methods: ['GET'], defaults: ['ignore_thelia_view' => true])]
    public function getCSVFeed(Request $request, FacebookFeedService $facebookFeedService): Response
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        $lang = $session instanceof Session ? $session->getLang() : null;
        $locale = $lang instanceof Lang ? (string) $lang->getLocale() : $request->getLocale();

        $path = $facebookFeedService->resolve($facebookFeedService->fileNameFor($locale));
        if (null === $path) {
            return new Response(\sprintf("The feed of the language %s has not been generated yet.\n", $locale), Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');

        return $response;
    }
}
