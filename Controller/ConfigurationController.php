<?php

namespace FacebookFeed\Controller;

use FacebookFeed\FacebookFeed;
use FacebookFeed\Service\FacebookFeedService;
use Symfony\Component\Routing\Annotation\Route;
use Thelia\Controller\Admin\AdminController;
use Thelia\Core\HttpFoundation\Request;
use Thelia\Core\HttpFoundation\Response;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Tools\URL;

#[Route('/admin/module/FacebookFeed', name: 'FacebookFeed_configuration_controller_')]
class ConfigurationController extends AdminController
{
    #[Route('/config/save', name: 'FacebookFeed_config_save', methods: 'POST')]
    public function saveConfig(Request $request)
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, FacebookFeed::DOMAIN_NAME, AccessManager::UPDATE)) {
            return $response;
        }

        FacebookFeed::setConfigValue(FacebookFeed::ATTRIBUTE_COLOR_ID,$request->get('color_attribut_id'));
        FacebookFeed::setConfigValue(FacebookFeed::ATTRIBUTE_SIZE_ID,$request->get('size_attribut_id'));
        FacebookFeed::setConfigValue(FacebookFeed::HAS_STOCK,$request->get('has_stock') === 'on' ? true : null);

        return $this->generateRedirect(
            URL::getInstance()->absoluteUrl('admin/module/FacebookFeed')
        );
    }

    #[Route('/export', name: 'FacebookFeed_export')]
    public function export(FacebookFeedService $facebookFeedService)
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, FacebookFeed::DOMAIN_NAME, AccessManager::UPDATE)) {
            return $response;
        }
        $facebookFeedService->exportFacebookFeed();

        return $this->generateRedirect(
            URL::getInstance()->absoluteUrl('admin/module/FacebookFeed')
        );
    }

    #[Route('/download', name: 'FacebookFeed_download')]
    public function download(Request $request)
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, FacebookFeed::DOMAIN_NAME, AccessManager::UPDATE)) {
            return $response;
        }

        $file = $request->get('file_name');
        $content = file_get_contents(FacebookFeed::EXPORT_DIR.'/'.$file);

        $response = new Response();
        $response->setContent($content);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$file.'"');

        return $response;
    }

    #[Route('/delete', name: 'FacebookFeed_delete')]
    public function delete(Request $request)
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, FacebookFeed::DOMAIN_NAME, AccessManager::UPDATE)) {
            return $response;
        }
        $file = $request->get('file_name');
        $filePath = FacebookFeed::EXPORT_DIR.'/'.$file;

        if (file_exists($filePath)) {
            unlink($filePath);
        }

        return $this->generateRedirect(
            URL::getInstance()->absoluteUrl('admin/module/FacebookFeed')
        );
    }
}