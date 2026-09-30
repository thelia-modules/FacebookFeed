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

/**
 * One combination of a visible product, with everything the feed prints about it.
 */
final readonly class FeedRow
{
    public function __construct(
        public int $combinationId,
        public int $productId,
        public string $productReference,
        public string $title,
        public string $description,
        public float $quantity,
        public bool $isOnSale,
        public float $price,
        public float $salePrice,
        public int $taxRuleId,
        public string $brand,
        public ?string $rewrittenUrl,
        public ?string $imageFile,
        public ?string $additionalImageFile,
        public string $color,
        public string $size,
    ) {
    }
}
