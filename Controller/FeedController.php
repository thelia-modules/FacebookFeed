<?php

namespace FacebookFeed\Controller;

use FacebookFeed\FacebookFeed;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Model\ConfigQuery;
use Thelia\Tools\URL;

class FeedController extends BaseFrontController
{
    #[Route('/facebookfeed/feed', name: 'FacebookFeed_csv', methods: 'GET')]
    public function getCSVFeed(RequestStack $requestStack): Response|RedirectResponse
    {
        $locale = $requestStack->getCurrentRequest()->getSession()->getLang()->getLocale();

        $fileName = FacebookFeed::EXPORT_DIR.DS.'fluxfacebook_'. $locale .'.csv';

        if (!file_exists($fileName)){
            return $this->generateRedirect(
                URL::getInstance()->absoluteUrl('/')
            );
        }
        $content = file_get_contents($fileName);

        $response = new Response();
        $response->setContent($content);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        //$response->headers->set('Content-Disposition', 'attachment; filename="'.$file.'"');

        return $response;
    }
}