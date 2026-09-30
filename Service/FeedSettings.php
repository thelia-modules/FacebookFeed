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

use FacebookFeed\FacebookFeed;

/**
 * The module settings that shape the feed. Ids are the attributes and features (comma
 * separated in the configuration) whose value tells the color and the size of a combination.
 */
final readonly class FeedSettings
{
    /**
     * @param list<int> $colorAttributeIds
     * @param list<int> $colorFeatureIds
     * @param list<int> $sizeAttributeIds
     */
    public function __construct(
        public array $colorAttributeIds = [],
        public array $colorFeatureIds = [],
        public array $sizeAttributeIds = [],
        public bool $inStockOnly = false,
        public string $imageFilter = FacebookFeed::DEFAULT_IMAGE_FILTER,
    ) {
    }

    public static function fromConfiguration(): self
    {
        $imageFilter = (string) FacebookFeed::getConfigValue(FacebookFeed::IMAGE_FILTER, '');

        return new self(
            colorAttributeIds: self::parseIds((string) FacebookFeed::getConfigValue(FacebookFeed::ATTRIBUTE_COLOR_ID, '')),
            colorFeatureIds: self::parseIds((string) FacebookFeed::getConfigValue(FacebookFeed::FEATURE_COLOR_ID, '')),
            sizeAttributeIds: self::parseIds((string) FacebookFeed::getConfigValue(FacebookFeed::ATTRIBUTE_SIZE_ID, '')),
            inStockOnly: '1' === (string) FacebookFeed::getConfigValue(FacebookFeed::HAS_STOCK, ''),
            imageFilter: '' !== $imageFilter ? $imageFilter : FacebookFeed::DEFAULT_IMAGE_FILTER,
        );
    }

    /**
     * @return list<int>
     */
    public static function parseIds(string $list): array
    {
        $ids = [];
        foreach (explode(',', $list) as $candidate) {
            $id = (int) trim($candidate);
            if ($id > 0 && !\in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
