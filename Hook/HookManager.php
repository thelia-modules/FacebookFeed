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

namespace FacebookFeed\Hook;

use FacebookFeed\Form\ExclusionForm;
use FacebookFeed\Form\SettingsForm;
use FacebookFeed\Service\ExclusionRepository;
use FacebookFeed\Service\FacebookFeedService;
use FacebookFeed\Service\FeedSettings;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Form\TheliaFormFactory;
use Thelia\Core\Hook\BaseHook;
use Thelia\Core\Template\Parser\ParserResolver;
use Thelia\Tools\TokenProvider;

class HookManager extends BaseHook
{
    public function __construct(
        private readonly TheliaFormFactory $formFactory,
        private readonly FacebookFeedService $facebookFeedService,
        private readonly ExclusionRepository $exclusionRepository,
        private readonly TokenProvider $tokenProvider,
        ?EventDispatcherInterface $dispatcher = null,
        ?ParserResolver $parserResolver = null,
    ) {
        parent::__construct($dispatcher, $parserResolver);
    }

    /**
     * @return array<string, list<array{type: string, method: string}>>
     */
    public static function getSubscribedHooks(): array
    {
        return [
            'module.configuration' => [
                ['type' => 'back', 'method' => 'onModuleConfiguration'],
            ],
            'product.tab-content' => [
                ['type' => 'back', 'method' => 'onProductTabContent'],
            ],
        ];
    }

    public function onModuleConfiguration(HookRenderEvent $event): void
    {
        $settings = FeedSettings::fromConfiguration();

        $settingsForm = $this->formFactory->createForm(SettingsForm::class, data: [
            'color_feature_ids' => implode(',', $settings->colorFeatureIds),
            'color_attribute_ids' => implode(',', $settings->colorAttributeIds),
            'size_attribute_ids' => implode(',', $settings->sizeAttributeIds),
            'in_stock_only' => $settings->inStockOnly,
            'image_filter' => $settings->imageFilter,
        ]);

        $event->add($this->render('FacebookFeed/module_configuration.html.twig', [
            'settings_form' => $settingsForm->createView()->getView(),
            'files' => $this->facebookFeedService->files(),
            'delete_token' => $this->tokenProvider->assignToken(),
        ]));
    }

    public function onProductTabContent(HookRenderEvent $event): void
    {
        $productId = (int) $event->getArgument('product');

        $exclusionForm = $this->formFactory->createForm(ExclusionForm::class, data: [
            'excluded_combinations' => $this->exclusionRepository->excludedCombinationIdsOfProduct($productId),
        ]);

        $event->add($this->render('FacebookFeed/product_tab_content.html.twig', [
            'product_id' => $productId,
            'exclusion_form' => $exclusionForm->createView()->getView(),
        ]));
    }
}
