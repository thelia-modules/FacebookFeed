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

use FacebookFeed\FacebookFeed;
use FacebookFeed\Form\SettingsForm;
use FacebookFeed\Service\FacebookFeedService;
use FacebookFeed\Service\FeedSettings;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Thelia\Controller\Admin\BaseAdminController;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Exception\TokenAuthenticationException;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Form\Exception\FormValidationException;
use Thelia\Tools\TokenProvider;

#[Route('/admin/module/FacebookFeed', name: 'facebookfeed.')]
class ConfigurationController extends BaseAdminController
{
    #[Route('/settings', name: 'settings.save', methods: ['POST'])]
    public function saveSettings(UrlGeneratorInterface $urlGenerator): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, FacebookFeed::DOMAIN_NAME, AccessManager::UPDATE)) {
            return $response;
        }

        $form = $this->createForm(SettingsForm::class);
        $fallbackUrl = $urlGenerator->generate('admin.module.configure', ['module_code' => FacebookFeed::getModuleCode()]);

        try {
            $data = $this->validateForm($form)->getData();

            FacebookFeed::setConfigValue(FacebookFeed::FEATURE_COLOR_ID, implode(',', FeedSettings::parseIds((string) $data['color_feature_ids'])));
            FacebookFeed::setConfigValue(FacebookFeed::ATTRIBUTE_COLOR_ID, implode(',', FeedSettings::parseIds((string) $data['color_attribute_ids'])));
            FacebookFeed::setConfigValue(FacebookFeed::ATTRIBUTE_SIZE_ID, implode(',', FeedSettings::parseIds((string) $data['size_attribute_ids'])));
            FacebookFeed::setConfigValue(FacebookFeed::HAS_STOCK, true === $data['in_stock_only'] ? '1' : '');
            FacebookFeed::setConfigValue(FacebookFeed::IMAGE_FILTER, trim((string) $data['image_filter']));

            return $this->generateSuccessRedirect($form) ?? $this->generateRedirect($fallbackUrl);
        } catch (FormValidationException $exception) {
            $this->setupFormErrorContext(
                $this->translator->trans('Facebook feed settings', [], FacebookFeed::DOMAIN_NAME),
                $this->createStandardFormValidationErrorMessage($exception),
                $form,
                $exception,
            );
            $this->addFlash('danger', $this->createStandardFormValidationErrorMessage($exception));
        }

        return $this->generateErrorRedirect($form) ?? $this->generateRedirect($fallbackUrl);
    }

    #[Route('/download/{fileName}', name: 'file.download', methods: ['GET'])]
    public function download(string $fileName, FacebookFeedService $facebookFeedService): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, FacebookFeed::DOMAIN_NAME, AccessManager::VIEW)) {
            return $response;
        }

        $path = $facebookFeedService->resolve($fileName);
        if (null === $path) {
            return new Response('', Response::HTTP_NOT_FOUND);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $fileName);

        return $response;
    }

    #[Route('/delete', name: 'file.delete', methods: ['POST'])]
    public function delete(FacebookFeedService $facebookFeedService, TokenProvider $tokenProvider, UrlGeneratorInterface $urlGenerator): Response
    {
        if (null !== $response = $this->checkAuth(AdminResources::MODULE, FacebookFeed::DOMAIN_NAME, AccessManager::UPDATE)) {
            return $response;
        }

        $request = $this->getRequest();

        try {
            $tokenProvider->checkToken((string) $request->request->get('_token', ''));
            $facebookFeedService->delete((string) $request->request->get('file_name', ''));
        } catch (TokenAuthenticationException) {
            $this->addFlash('danger', $this->translator->trans('Your session has expired, please reload the page and try again', [], FacebookFeed::DOMAIN_NAME));
        }

        return $this->generateRedirect($urlGenerator->generate('admin.module.configure', ['module_code' => FacebookFeed::getModuleCode()]));
    }
}
