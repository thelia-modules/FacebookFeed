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

use Propel\Runtime\Propel;
use Thelia\Model\Currency;

/**
 * Reads the combinations of the feed in batches, ordered by combination id.
 *
 * A batch costs a fixed number of queries whatever its size: the combinations themselves, then
 * one query for each thing printed next to them (brands, addresses, images, attributes, features).
 * The reader never loads a Propel model per combination.
 */
final class FeedRowReader
{
    public const BATCH_SIZE = 500;

    /**
     * @return \Generator<int, list<FeedRow>>
     */
    public function batches(string $locale, FeedSettings $settings, int $batchSize = self::BATCH_SIZE): \Generator
    {
        $currency = Currency::getDefaultCurrency();
        $afterCombinationId = 0;

        while (true) {
            $combinations = $this->readCombinations($locale, $currency, $settings, $afterCombinationId, $batchSize);
            if ([] === $combinations) {
                return;
            }

            $afterCombinationId = (int) $combinations[array_key_last($combinations)]['id'];

            yield $this->buildRows($combinations, $locale, $settings);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readCombinations(string $locale, Currency $currency, FeedSettings $settings, int $afterCombinationId, int $batchSize): array
    {
        $sql = <<<'SQL'
            SELECT pse.id, pse.product_id, pse.quantity, pse.promo,
                   product.ref AS product_reference, product.brand_id, product.tax_rule_id,
                   product_i18n.title, product_i18n.description,
                   price.price, price.promo_price
            FROM product_sale_elements AS pse
            INNER JOIN product ON product.id = pse.product_id AND product.visible = 1
            LEFT JOIN product_price AS price
                ON price.product_sale_elements_id = pse.id AND price.currency_id = ?
            INNER JOIN product_i18n ON product_i18n.id = product.id AND product_i18n.locale = ? AND COALESCE(product_i18n.title, '') <> ''
            LEFT JOIN facebook_feed_product_excluded AS excluded ON excluded.pse_id = pse.id
            WHERE pse.id > ? AND COALESCE(excluded.is_excluded, 0) = 0
            SQL;

        if ($settings->inStockOnly) {
            $sql .= ' AND pse.quantity >= 1';
        }

        return $this->select($sql.' ORDER BY pse.id LIMIT ?', [$currency->getId(), $locale, $afterCombinationId, $batchSize]);
    }

    /**
     * @param list<array<string, mixed>> $combinations
     *
     * @return list<FeedRow>
     */
    private function buildRows(array $combinations, string $locale, FeedSettings $settings): array
    {
        $combinationIds = array_map(static fn (array $row): int => (int) $row['id'], $combinations);
        $productIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['product_id'], $combinations)));
        $brandIds = array_values(array_unique(array_filter(array_map(static fn (array $row): int => (int) $row['brand_id'], $combinations))));

        $brands = $this->brandTitles($brandIds, $locale);
        $addresses = $this->rewrittenUrls($productIds, $locale);
        $productImages = $this->productImages($productIds, $locale);
        $combinationImages = $this->combinationImages($combinationIds, $locale);
        $attributeValues = $this->attributeValues($combinationIds, array_merge($settings->colorAttributeIds, $settings->sizeAttributeIds), $locale);
        $featureValues = $this->featureValues($productIds, $settings->colorFeatureIds, $locale);

        $rows = [];
        foreach ($combinations as $combination) {
            $combinationId = (int) $combination['id'];
            $productId = (int) $combination['product_id'];
            $images = $productImages[$productId] ?? ['main' => null, 'additional' => null];

            $rows[] = new FeedRow(
                combinationId: $combinationId,
                productId: $productId,
                productReference: (string) $combination['product_reference'],
                title: (string) $combination['title'],
                description: (string) ($combination['description'] ?? ''),
                quantity: max(0.0, (float) $combination['quantity']),
                isOnSale: 1 === (int) $combination['promo'],
                price: (float) $combination['price'],
                salePrice: (float) $combination['promo_price'],
                taxRuleId: (int) $combination['tax_rule_id'],
                brand: $brands[(int) $combination['brand_id']] ?? '',
                rewrittenUrl: $addresses[$productId] ?? null,
                imageFile: $combinationImages[$combinationId] ?? $images['main'],
                additionalImageFile: $images['additional'],
                color: $this->color($combinationId, $productId, $settings, $attributeValues, $featureValues),
                size: $this->joinValues($attributeValues[$combinationId] ?? [], $settings->sizeAttributeIds),
            );
        }

        return $rows;
    }

    /**
     * The first feature configured that gives the product a value wins; without any, the
     * values of the configured attributes of the combination.
     *
     * @param array<int, array<int, list<string>>> $attributeValues
     * @param array<int, array<int, string>>       $featureValues
     */
    private function color(int $combinationId, int $productId, FeedSettings $settings, array $attributeValues, array $featureValues): string
    {
        foreach ($settings->colorFeatureIds as $featureId) {
            $value = $featureValues[$productId][$featureId] ?? null;
            if (null !== $value && '' !== $value) {
                return $value;
            }
        }

        return $this->joinValues($attributeValues[$combinationId] ?? [], $settings->colorAttributeIds);
    }

    /**
     * @param array<int, list<string>> $valuesByAttribute
     * @param list<int>                $attributeIds
     */
    private function joinValues(array $valuesByAttribute, array $attributeIds): string
    {
        $values = [];
        foreach ($valuesByAttribute as $attributeId => $titles) {
            if (\in_array($attributeId, $attributeIds, true)) {
                array_push($values, ...$titles);
            }
        }

        return implode(',', $values);
    }

    /**
     * @param list<int> $brandIds
     *
     * @return array<int, string>
     */
    private function brandTitles(array $brandIds, string $locale): array
    {
        if ([] === $brandIds) {
            return [];
        }

        $titles = [];
        $rows = $this->select(
            'SELECT id, locale, title FROM brand_i18n WHERE id IN ('.$this->placeholders($brandIds).') ORDER BY locale',
            $brandIds,
        );
        foreach ($rows as $row) {
            $brandId = (int) $row['id'];
            if (!isset($titles[$brandId]) || $row['locale'] === $locale) {
                $titles[$brandId] = (string) $row['title'];
            }
        }

        return $titles;
    }

    /**
     * @param list<int> $productIds
     *
     * @return array<int, string>
     */
    private function rewrittenUrls(array $productIds, string $locale): array
    {
        $addresses = [];
        $rows = $this->select(
            "SELECT view_id, url FROM rewriting_url WHERE view = 'product' AND view_locale = ? AND redirected IS NULL AND view_id IN (".$this->placeholders($productIds).') ORDER BY id',
            [$locale, ...$productIds],
        );
        foreach ($rows as $row) {
            $addresses[(int) $row['view_id']] = (string) $row['url'];
        }

        return $addresses;
    }

    /**
     * The main image of a product is the one at position 1 (else the first by position); the
     * additional one is the oldest of the images placed at another position.
     *
     * @param list<int> $productIds
     *
     * @return array<int, array{main: ?string, additional: ?string}>
     */
    private function productImages(array $productIds, string $locale): array
    {
        $rows = $this->select(
            'SELECT image.id, image.product_id, image.position, translation.locale, translation.file'
            .' FROM product_image AS image'
            .' INNER JOIN product_image_i18n AS translation ON translation.id = image.id'
            ." WHERE translation.file IS NOT NULL AND translation.file <> '' AND image.product_id IN (".$this->placeholders($productIds).')'
            .' ORDER BY image.id',
            $productIds,
        );

        $imagesByProduct = [];
        foreach ($rows as $row) {
            $imageId = (int) $row['id'];
            $productId = (int) $row['product_id'];
            $known = $imagesByProduct[$productId][$imageId] ?? null;
            if (null === $known || $row['locale'] === $locale) {
                $imagesByProduct[$productId][$imageId] = [
                    'position' => null === $row['position'] ? null : (int) $row['position'],
                    'file' => (string) $row['file'],
                ];
            }
        }

        $images = [];
        foreach ($imagesByProduct as $productId => $productImages) {
            $images[$productId] = ['main' => $this->mainImageFile($productImages), 'additional' => $this->additionalImageFile($productImages)];
        }

        return $images;
    }

    /**
     * @param array<int, array{position: ?int, file: string}> $productImages by image id
     */
    private function mainImageFile(array $productImages): ?string
    {
        $main = null;
        foreach ($productImages as $image) {
            if (1 === $image['position']) {
                return $image['file'];
            }
            if (null !== $image['position'] && (null === $main || $image['position'] < $main['position'])) {
                $main = $image;
            }
        }

        return $main['file'] ?? null;
    }

    /**
     * @param array<int, array{position: ?int, file: string}> $productImages by image id
     */
    private function additionalImageFile(array $productImages): ?string
    {
        foreach ($productImages as $image) {
            if (null !== $image['position'] && 1 !== $image['position']) {
                return $image['file'];
            }
        }

        return null;
    }

    /**
     * The image tied to a combination, the first by position.
     *
     * @param list<int> $combinationIds
     *
     * @return array<int, string>
     */
    private function combinationImages(array $combinationIds, string $locale): array
    {
        $rows = $this->select(
            'SELECT link.product_sale_elements_id AS combination_id, image.id, image.position, translation.locale, translation.file'
            .' FROM product_sale_elements_product_image AS link'
            .' INNER JOIN product_image AS image ON image.id = link.product_image_id'
            .' INNER JOIN product_image_i18n AS translation ON translation.id = image.id'
            ." WHERE translation.file IS NOT NULL AND translation.file <> '' AND link.product_sale_elements_id IN (".$this->placeholders($combinationIds).')'
            .' ORDER BY image.position, image.id',
            $combinationIds,
        );

        $files = [];
        $imageIds = [];
        foreach ($rows as $row) {
            $combinationId = (int) $row['combination_id'];
            $imageId = (int) $row['id'];
            $imageIds[$combinationId] ??= $imageId;
            if ($imageIds[$combinationId] !== $imageId) {
                continue;
            }
            if (!isset($files[$combinationId]) || $row['locale'] === $locale) {
                $files[$combinationId] = (string) $row['file'];
            }
        }

        return $files;
    }

    /**
     * @param list<int> $combinationIds
     * @param list<int> $attributeIds
     *
     * @return array<int, array<int, list<string>>> titles by combination, then by attribute
     */
    private function attributeValues(array $combinationIds, array $attributeIds, string $locale): array
    {
        if ([] === $attributeIds) {
            return [];
        }

        $rows = $this->select(
            'SELECT combination.product_sale_elements_id AS combination_id, combination.attribute_id, translation.title'
            .' FROM attribute_combination AS combination'
            .' INNER JOIN attribute_av_i18n AS translation ON translation.id = combination.attribute_av_id AND translation.locale = ?'
            .' WHERE combination.attribute_id IN ('.$this->placeholders($attributeIds).')'
            .' AND combination.product_sale_elements_id IN ('.$this->placeholders($combinationIds).')'
            .' ORDER BY combination.attribute_id, combination.attribute_av_id',
            [$locale, ...$attributeIds, ...$combinationIds],
        );

        $values = [];
        foreach ($rows as $row) {
            $values[(int) $row['combination_id']][(int) $row['attribute_id']][] = (string) $row['title'];
        }

        return $values;
    }

    /**
     * @param list<int> $productIds
     * @param list<int> $featureIds
     *
     * @return array<int, array<int, string>> the first title by product, then by feature
     */
    private function featureValues(array $productIds, array $featureIds, string $locale): array
    {
        if ([] === $featureIds) {
            return [];
        }

        $rows = $this->select(
            'SELECT link.product_id, link.feature_id, translation.title'
            .' FROM feature_product AS link'
            .' INNER JOIN feature_av_i18n AS translation ON translation.id = link.feature_av_id AND translation.locale = ?'
            .' WHERE link.feature_id IN ('.$this->placeholders($featureIds).')'
            .' AND link.product_id IN ('.$this->placeholders($productIds).')'
            .' ORDER BY link.id',
            [$locale, ...$featureIds, ...$productIds],
        );

        $values = [];
        foreach ($rows as $row) {
            $values[(int) $row['product_id']][(int) $row['feature_id']] ??= (string) $row['title'];
        }

        return $values;
    }

    /**
     * @param list<int|string> $values
     */
    private function placeholders(array $values): string
    {
        return implode(',', array_fill(0, \count($values), '?'));
    }

    /**
     * @param list<int|string> $parameters
     *
     * @return list<array<string, mixed>>
     */
    private function select(string $sql, array $parameters): array
    {
        $statement = Propel::getConnection()->prepare($sql);
        foreach (array_values($parameters) as $index => $parameter) {
            $statement->bindValue($index + 1, $parameter, \is_int($parameter) ? \PDO::PARAM_INT : \PDO::PARAM_STR);
        }
        $statement->execute();

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
