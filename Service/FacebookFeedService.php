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
use FacebookFeed\FacebookFeed;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Thelia\Model\ConfigQuery;
use Thelia\Model\Currency;
use Thelia\Model\Lang;
use Thelia\Model\LangQuery;
use Thelia\Tools\URL;

/**
 * Writes the feed of a language: one csv file, `;` separated, one line per combination of every
 * visible product that is not excluded.
 *
 * The file is written next to its final name and renamed once complete, so a reader (the feed
 * address, the back-office download) never sees a half-written feed.
 */
final class FacebookFeedService
{
    public const HEADER = [
        'id', 'item_group_ID', 'title', 'description', 'availability', 'condition', 'price', 'link',
        'image_link', 'additional_image_link', 'brand', 'quantity_to_sell_on_facebook', 'sale_price', 'color', 'size',
    ];

    private const TITLE_LENGTH = 150;
    private const DESCRIPTION_LENGTH = 9999;

    public function __construct(
        private readonly FeedRowReader $rowReader,
        private readonly TaxedPrices $taxedPrices,
        private readonly FeedImageUrls $imageUrls,
        #[Autowire('%kernel.project_dir%/local/'.FacebookFeed::EXPORT_DIRECTORY_NAME)]
        private readonly string $exportDirectory,
    ) {
    }

    public function fileNameFor(string $locale): string
    {
        return 'fluxfacebook_'.$locale.'.csv';
    }

    public function pathFor(string $locale): string
    {
        return $this->exportDirectory.\DIRECTORY_SEPARATOR.$this->fileNameFor($locale);
    }

    /**
     * Takes the lock of the generation, so that two runs (overlapping scheduled tasks) never write
     * the same files at once. Null when another run holds it; the lock is released when the
     * returned handle is closed or the process ends.
     *
     * @return resource|null
     */
    public function tryLock()
    {
        $this->ensureDirectory();
        $handle = fopen($this->exportDirectory.\DIRECTORY_SEPARATOR.'.generate.lock', 'c');
        if (false === $handle) {
            throw FeedGenerationException::directoryNotWritable($this->exportDirectory);
        }
        if (!flock($handle, \LOCK_EX | \LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /**
     * The generated files, by name, with their last modification.
     *
     * @return array<string, \DateTimeImmutable>
     */
    public function files(): array
    {
        $files = [];
        foreach (glob($this->exportDirectory.\DIRECTORY_SEPARATOR.'fluxfacebook_*.csv') ?: [] as $path) {
            $files[basename($path)] = (new \DateTimeImmutable('@'.(int) filemtime($path)));
        }
        ksort($files);

        return $files;
    }

    /**
     * The path of a generated file asked by name, null when the name is not one of a feed
     * file (nothing outside the folder can be reached) or the file does not exist.
     */
    public function resolve(string $fileName): ?string
    {
        if (1 !== preg_match('/^fluxfacebook_[a-z]{2,3}_[A-Z]{2}\.csv$/', $fileName)) {
            return null;
        }

        $path = $this->exportDirectory.\DIRECTORY_SEPARATOR.$fileName;

        return is_file($path) ? $path : null;
    }

    public function delete(string $fileName): void
    {
        $path = $this->resolve($fileName);
        if (null !== $path) {
            unlink($path);
        }
    }

    /**
     * @param (callable(int): void)|null $onBatch called with the number of lines each batch wrote
     *
     * @throws FeedGenerationException
     */
    public function generate(string $locale, ?FeedSettings $settings = null, ?int $limit = null, ?int $offset = null, ?callable $onBatch = null): string
    {
        $settings ??= FeedSettings::fromConfiguration();
        $baseUrl = $this->baseUrl($locale);
        $this->imageUrls->assertFilterSetExists($settings->imageFilter);
        $currencyCode = (string) Currency::getDefaultCurrency()->getCode();

        $this->ensureDirectory();
        $path = $this->pathFor($locale);
        $temporaryPath = $path.'.tmp';

        $handle = fopen($temporaryPath, 'w');
        if (false === $handle) {
            throw FeedGenerationException::directoryNotWritable($this->exportDirectory);
        }

        try {
            fputcsv($handle, self::HEADER, ';', '"', '');

            $skipped = 0;
            $written = 0;
            foreach ($this->rowReader->batches($locale, $settings) as $batch) {
                $lines = 0;
                foreach ($batch as $row) {
                    if (null !== $offset && $skipped < $offset) {
                        ++$skipped;
                        continue;
                    }
                    if (null !== $limit && $written >= $limit) {
                        break 2;
                    }

                    fputcsv($handle, $this->line($row, $locale, $baseUrl, $currencyCode, $settings), ';', '"', '');
                    ++$written;
                    ++$lines;
                }
                if (null !== $onBatch) {
                    $onBatch($lines);
                }
            }
        } catch (\Throwable $exception) {
            fclose($handle);
            @unlink($temporaryPath);

            throw $exception;
        }

        fclose($handle);
        if (!rename($temporaryPath, $path)) {
            @unlink($temporaryPath);

            throw FeedGenerationException::directoryNotWritable($this->exportDirectory);
        }

        return $path;
    }

    /**
     * @return list<string>
     */
    private function line(FeedRow $row, string $locale, string $baseUrl, string $currencyCode, FeedSettings $settings): array
    {
        $price = $this->taxedPrices->taxedPrice($row->price, $row->taxRuleId, $row->productId);
        $salePrice = $row->isOnSale ? $this->taxedPrices->taxedPrice($row->salePrice, $row->taxRuleId, $row->productId) : null;

        return [
            $row->productReference.'-'.$row->combinationId,
            $row->productReference,
            mb_substr($row->title, 0, self::TITLE_LENGTH),
            $this->description($row->description),
            $row->quantity > 0 ? 'in stock' : 'out of stock',
            'new',
            $this->formattedPrice($price, $currencyCode),
            $this->link($row, $locale, $baseUrl),
            $this->imageLink($row->imageFile, $baseUrl, $settings),
            $this->imageLink($row->additionalImageFile, $baseUrl, $settings),
            $row->brand,
            $this->formattedQuantity($row->quantity),
            null === $salePrice ? '' : $this->formattedPrice($salePrice, $currencyCode),
            $row->color,
            $row->size,
        ];
    }

    private function formattedPrice(float $price, string $currencyCode): string
    {
        return round($price, 2).' '.$currencyCode;
    }

    private function formattedQuantity(float $quantity): string
    {
        return floor($quantity) === $quantity ? (string) (int) $quantity : (string) $quantity;
    }

    /**
     * Plain text: tags removed, entities decoded (the csv writer escapes what needs it). The
     * text is cut in characters, never in bytes, so an accented letter is never split.
     */
    private function description(string $html): string
    {
        return trim(mb_substr(html_entity_decode(strip_tags($html)), 0, self::DESCRIPTION_LENGTH));
    }

    private function link(FeedRow $row, string $locale, string $baseUrl): string
    {
        if (null !== $row->rewrittenUrl) {
            return str_starts_with($row->rewrittenUrl, 'http') ? $row->rewrittenUrl : $baseUrl.'/'.ltrim($row->rewrittenUrl, '/');
        }

        $retrieved = URL::getInstance()->retrieve('product', $row->productId, $locale);
        $url = !empty($retrieved->rewrittenUrl) ? $retrieved->rewrittenUrl : $retrieved->url;
        $parts = \is_string($url) ? parse_url($url) : false;
        if (!\is_array($parts) || !isset($parts['path'])) {
            return '';
        }

        return $baseUrl.'/'.ltrim($parts['path'], '/').(isset($parts['query']) ? '?'.$parts['query'] : '');
    }

    private function imageLink(?string $file, string $baseUrl, FeedSettings $settings): string
    {
        if (null === $file) {
            return '';
        }

        $path = $this->imageUrls->pathOf($file, $settings->imageFilter);

        return null === $path ? '' : $baseUrl.$path;
    }

    /**
     * The address of the language when the shop has one domain for each language, else the
     * address of the shop.
     */
    private function baseUrl(string $locale): string
    {
        $lang = LangQuery::create()->findOneByLocale($locale);
        if (!$lang instanceof Lang) {
            throw FeedGenerationException::unknownLocale($locale);
        }

        $url = ConfigQuery::isMultiDomainActivated() ? (string) $lang->getUrl() : (string) ConfigQuery::getConfiguredShopUrl();
        $url = rtrim($url, '/');
        if ('' === $url) {
            throw FeedGenerationException::noShopUrl($locale);
        }

        return $url;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->exportDirectory) && !@mkdir($this->exportDirectory, 0o755, true) && !is_dir($this->exportDirectory)) {
            throw FeedGenerationException::directoryNotWritable($this->exportDirectory);
        }
    }
}
