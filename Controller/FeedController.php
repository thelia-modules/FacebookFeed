<?php

namespace FacebookFeed\Controller;

use FacebookFeed\FacebookFeed;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Controller\Front\BaseFrontController;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Tools\URL;

class FeedController extends BaseFrontController
{
    #[Route('/facebookfeed/{filename}/feed.csv', name: 'FacebookFeed_csv', methods: 'GET')]
    public function getCSVFeed(Request $request,$filename)
    {
        $fileExtension = pathinfo($filename, PATHINFO_EXTENSION);

        if (!in_array($fileExtension, ['csv'])) {
            return $this->generateRedirect(
                URL::getInstance()->absoluteUrl('/')
            );
        }

        $dir = dirname(realpath(FacebookFeed::EXPORT_DIR.'/'.$filename));
        if ($dir !== FacebookFeed::EXPORT_DIR){
            return $this->generateRedirect(
                URL::getInstance()->absoluteUrl('/')
            );
        }

        $content = file_get_contents(FacebookFeed::EXPORT_DIR.'/'.$filename);

        $response = new Response();
        $response->setContent($content);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        //$response->headers->set('Content-Disposition', 'attachment; filename="'.$file.'"');

        return $response;
    }
}