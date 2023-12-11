<?php

namespace FacebookFeed\Controller;

use FacebookFeed\FacebookFeed;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Tools\URL;

class FeedController extends BaseFrontController
{
    #[Route('/facebookfeed/feed', name: 'FacebookFeed_csv', methods: 'GET')]
    public function getCSVFeed()
    {
        $fileName = FacebookFeed::EXPORT_DIR.DS.'fluxfacebook.csv';
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