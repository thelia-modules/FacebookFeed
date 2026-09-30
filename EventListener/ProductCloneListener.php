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

namespace FacebookFeed\EventListener;

use FacebookFeed\Service\ExclusionRepository;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Thelia\Core\Event\Product\ProductCloneEvent;
use Thelia\Core\Event\TheliaEvents;

/**
 * A cloned product is kept out of the feed exactly where its original is. It runs after the
 * clone made by the core (priority 128), which has created the combinations.
 */
final readonly class ProductCloneListener implements EventSubscriberInterface
{
    public function __construct(private ExclusionRepository $exclusionRepository)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [TheliaEvents::PRODUCT_CLONE => ['copyExclusions', 100]];
    }

    public function copyExclusions(ProductCloneEvent $event): void
    {
        $this->exclusionRepository->copyToClonedProduct(
            (int) $event->getOriginalProduct()->getId(),
            (int) $event->getClonedProduct()->getId(),
        );
    }
}
