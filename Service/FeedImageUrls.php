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

namespace FacebookFeed\Service;

use FacebookFeed\Exception\FeedGenerationException;
use Liip\ImagineBundle\Exception\Imagine\Filter\NonExistingFilterException;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Liip\ImagineBundle\Imagine\Filter\FilterConfiguration;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The address of a product image for a filter set of the image library, returned without host
 * so that each language of the feed puts its own domain in front.
 *
 * The address is the browser path of the library (the image is not generated here, no modern
 * format variant is written for a feed), remembered for each file and filter set.
 */
final class FeedImageUrls
{
    /** @var array<string, ?string> */
    private array $paths = [];

    public function __construct(
        private readonly CacheManager $cacheManager,
        #[Autowire(service: 'liip_imagine.filter.configuration')]
        private readonly FilterConfiguration $filterConfiguration,
    ) {
    }

    /**
     * @throws FeedGenerationException when the filter set is not configured
     */
    public function assertFilterSetExists(string $filterSet): void
    {
        try {
            $this->filterConfiguration->get($filterSet);
        } catch (NonExistingFilterException) {
            throw FeedGenerationException::unknownImageFilter($filterSet);
        }
    }

    public function pathOf(string $file, string $filterSet): ?string
    {
        $key = $file.'|'.$filterSet;
        if (\array_key_exists($key, $this->paths)) {
            return $this->paths[$key];
        }

        $parts = parse_url($this->cacheManager->getBrowserPath('/product/'.$file, $filterSet));
        if (!\is_array($parts) || !isset($parts['path'])) {
            return $this->paths[$key] = null;
        }

        return $this->paths[$key] = $parts['path'].(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
