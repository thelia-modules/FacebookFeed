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

namespace FacebookFeed\Tests;

use FacebookFeed\Exception\FeedGenerationException;
use FacebookFeed\FacebookFeed;
use FacebookFeed\Model\FacebookFeedProductExcluded;
use FacebookFeed\Service\FeedSettings;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Thelia\Domain\Taxation\TaxEngine\TaxCalculatorFactoryInterface;
use Thelia\Domain\Taxation\TaxEngine\TaxCalculatorInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\ProductImage;

final class FeedGenerationTest extends FeedIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ConfigQuery::write('one_domain_foreach_lang', '0');
        ConfigQuery::write('url_site', 'https://www.example.fr');
    }

    public function testColumnsKeepTheirNamesAndTheirOrder(): void
    {
        $this->product('HELMET');

        $handle = fopen($this->service()->generate('fr_FR'), 'r');
        self::assertNotFalse($handle);

        self::assertSame(
            ['id', 'item_group_ID', 'title', 'description', 'availability', 'condition', 'price', 'link', 'image_link', 'additional_image_link', 'brand', 'quantity_to_sell_on_facebook', 'sale_price', 'color', 'size'],
            fgetcsv($handle, null, ';', '"', ''),
        );
    }

    public function testEachCombinationHasItsOwnIdentifierAndTheGroupOfItsProduct(): void
    {
        $product = $this->product('HELMET');
        $second = $this->fixtures->productSaleElement($product);
        $this->fixtures->productPrice($second, $this->fixtures->currency(), ['price' => '20.000000']);
        $defaultCombinationId = $this->defaultCombination($product)->getId();

        $feed = $this->feed('fr_FR');

        $first = $this->lineOf($feed, 'HELMET-'.$defaultCombinationId);
        $other = $this->lineOf($feed, 'HELMET-'.$second->getId());
        self::assertSame('HELMET', $first['item_group_ID']);
        self::assertSame('HELMET', $other['item_group_ID']);
        self::assertSame('12 EUR', $first['price']);
        self::assertSame('24 EUR', $other['price']);
        self::assertSame('new', $first['condition']);
        self::assertSame('A helmet', $first['title']);
    }

    public function testAnExcludedCombinationIsLeftOutAndTheOthersStay(): void
    {
        $product = $this->product('HELMET');
        $second = $this->fixtures->productSaleElement($product);
        $this->fixtures->productPrice($second, $this->fixtures->currency());
        (new FacebookFeedProductExcluded())->setPseId($second->getId())->setIsExcluded(1)->save();
        (new FacebookFeedProductExcluded())->setPseId($this->defaultCombination($product)->getId())->setIsExcluded(0)->save();

        $identifiers = array_column($this->feed('fr_FR'), 'id');

        self::assertContains('HELMET-'.$this->defaultCombination($product)->getId(), $identifiers);
        self::assertNotContains('HELMET-'.$second->getId(), $identifiers);
    }

    public function testAnInvisibleProductIsNotInTheFeed(): void
    {
        $this->product('VISIBLE');
        $this->product('HIDDEN', 'Hidden', ['visible' => 0]);

        $groups = array_column($this->feed('fr_FR'), 'item_group_ID');

        self::assertContains('VISIBLE', $groups);
        self::assertNotContains('HIDDEN', $groups);
    }

    public function testInStockOnlyLeavesOutTheCombinationsWithoutStock(): void
    {
        $inStock = $this->product('IN', 'In stock', ['baseQuantity' => 4]);
        $outOfStock = $this->product('OUT', 'Out of stock', ['baseQuantity' => 0]);
        $inStockId = 'IN-'.$this->defaultCombination($inStock)->getId();
        $outOfStockId = 'OUT-'.$this->defaultCombination($outOfStock)->getId();

        $everything = $this->feed('fr_FR');
        self::assertSame('in stock', $this->lineOf($everything, $inStockId)['availability']);
        self::assertSame('4', $this->lineOf($everything, $inStockId)['quantity_to_sell_on_facebook']);
        self::assertSame('out of stock', $this->lineOf($everything, $outOfStockId)['availability']);

        FacebookFeed::setConfigValue(FacebookFeed::HAS_STOCK, '1');
        $onlyInStock = array_column($this->feed('fr_FR'), 'id');

        self::assertContains($inStockId, $onlyInStock);
        self::assertNotContains($outOfStockId, $onlyInStock);
    }

    public function testAnAccentedTitleIsCutInCharactersNotInBytes(): void
    {
        $product = $this->product('LONG', str_repeat('é', 200));

        $line = $this->lineOf($this->feed('fr_FR'), 'LONG-'.$this->defaultCombination($product)->getId());

        self::assertSame(str_repeat('é', 150), $line['title']);
        self::assertTrue(mb_check_encoding($line['title'], 'UTF-8'));
    }

    public function testTheDescriptionIsPlainTextCutInCharacters(): void
    {
        $product = $this->product('DESC');
        $product->setLocale('fr_FR')->setDescription('<p>Casque <strong>intégral</strong> &amp; léger</p>'.str_repeat('é', 10000))->save();

        $line = $this->lineOf($this->feed('fr_FR'), 'DESC-'.$this->defaultCombination($product)->getId());

        self::assertStringStartsWith('Casque intégral & léger', $line['description']);
        self::assertSame(9999, mb_strlen($line['description']));
        self::assertStringNotContainsString('<', $line['description']);
    }

    public function testASaleCombinationShowsItsSalePriceWithTaxes(): void
    {
        $product = $this->product('SALE');
        $combination = $this->defaultCombination($product);
        $combination->setPromo(1)->save();
        $price = \Thelia\Model\ProductPriceQuery::create()->filterByProductSaleElementsId($combination->getId())->findOne();
        $price->setPromoPrice('5.000000')->save();
        $regular = $this->product('REGULAR');

        $feed = $this->feed('fr_FR');

        self::assertSame('6 EUR', $this->lineOf($feed, 'SALE-'.$combination->getId())['sale_price']);
        self::assertSame('', $this->lineOf($feed, 'REGULAR-'.$this->defaultCombination($regular)->getId())['sale_price']);
    }

    public function testTheGermanFeedIsInGermanOnTheGermanDomain(): void
    {
        $this->useOneDomainForEachLanguage();
        $product = $this->product('HELM', 'Un casque');
        $product->setLocale('de_DE')->setTitle('Ein Helm')->save();
        $this->addressOf($product, 'fr_FR', 'casque.html');
        $this->addressOf($product, 'de_DE', 'helm.html');
        $combinationId = $this->defaultCombination($product)->getId();

        $french = $this->lineOf($this->feed('fr_FR'), 'HELM-'.$combinationId);
        $german = $this->lineOf($this->feed('de_DE'), 'HELM-'.$combinationId);

        self::assertSame('https://www.example.fr/casque.html', $french['link']);
        self::assertSame('Un casque', $french['title']);
        self::assertSame('https://www.example.de/helm.html', $german['link']);
        self::assertSame('Ein Helm', $german['title']);
    }

    public function testImageLinksComeFromTheImageLibraryOnTheDomainOfTheLanguage(): void
    {
        $this->useOneDomainForEachLanguage();
        $product = $this->product('PIC');
        $product->setLocale('de_DE')->setTitle('Bild')->save();
        $this->addImage($product, 1, 'front.jpg');
        $this->addImage($product, 2, 'side.jpg');
        $this->addImage($product, 3, 'back.jpg');

        $line = $this->lineOf($this->feed('de_DE'), 'PIC-'.$this->defaultCombination($product)->getId());

        self::assertStringStartsWith('https://www.example.de/', $line['image_link']);
        self::assertStringContainsString('front.jpg', $line['image_link']);
        self::assertStringStartsWith('https://www.example.de/', $line['additional_image_link']);
        self::assertStringContainsString('side.jpg', $line['additional_image_link']);
    }

    public function testTheColorComesFromAFeatureThenFromAnAttributeAndTheSizeFromAnAttribute(): void
    {
        $colorFeature = $this->fixtures->feature(['locale' => 'fr_FR']);
        $colorAttribute = $this->fixtures->attribute(['locale' => 'fr_FR']);
        $sizeAttribute = $this->fixtures->attribute(['locale' => 'fr_FR']);

        $withFeature = $this->product('FEATURE');
        $this->fixtures->featureProduct($withFeature, $this->fixtures->featureAv($colorFeature, ['locale' => 'fr_FR', 'title' => 'Noir']));
        $this->fixtures->attributeCombination($this->defaultCombination($withFeature), $this->fixtures->attributeAv($sizeAttribute, ['locale' => 'fr_FR', 'title' => 'XL']));

        $withAttribute = $this->product('ATTRIBUTE');
        $this->fixtures->attributeCombination($this->defaultCombination($withAttribute), $this->fixtures->attributeAv($colorAttribute, ['locale' => 'fr_FR', 'title' => 'Rouge']));

        $feed = $this->feed('fr_FR', new FeedSettings(
            colorAttributeIds: [$colorAttribute->getId()],
            colorFeatureIds: [$colorFeature->getId()],
            sizeAttributeIds: [$sizeAttribute->getId()],
        ));

        $featureLine = $this->lineOf($feed, 'FEATURE-'.$this->defaultCombination($withFeature)->getId());
        $attributeLine = $this->lineOf($feed, 'ATTRIBUTE-'.$this->defaultCombination($withAttribute)->getId());
        self::assertSame('Noir', $featureLine['color']);
        self::assertSame('XL', $featureLine['size']);
        self::assertSame('Rouge', $attributeLine['color']);
        self::assertSame('', $attributeLine['size']);
    }

    public function testAFieldWithDelimiterQuoteAndLineBreakSurvivesTheRoundTrip(): void
    {
        $product = $this->product('CSV', "Casque; \"intégral\"\nnoir");

        $line = $this->lineOf($this->feed('fr_FR'), 'CSV-'.$this->defaultCombination($product)->getId());

        self::assertSame("Casque; \"intégral\"\nnoir", $line['title']);
    }

    public function testTheNumberOfQueriesDoesNotGrowWithTheNumberOfProducts(): void
    {
        $this->service()->generate('fr_FR');
        $first = $this->product('BASE');
        $this->addImage($first, 1, 'base.jpg');
        $this->addressOf($first, 'fr_FR', 'base.html');
        $queriesForOneProduct = $this->countQueries(fn () => $this->service()->generate('fr_FR'));

        for ($index = 0; $index < 12; ++$index) {
            $product = $this->product('MANY-'.$index);
            $this->addImage($product, 1, 'many-'.$index.'.jpg');
            $this->addressOf($product, 'fr_FR', 'many-'.$index.'.html');
        }
        $queriesForThirteenProducts = $this->countQueries(fn () => $this->service()->generate('fr_FR'));

        self::assertGreaterThan(0, $queriesForOneProduct);
        self::assertSame($queriesForOneProduct, $queriesForThirteenProducts);
    }

    public function testTheFeedIsReadInSeveralBatches(): void
    {
        for ($index = 0; $index < 7; ++$index) {
            $this->product('BATCH-'.$index);
        }
        $reader = new \FacebookFeed\Service\FeedRowReader();

        $sizes = array_map('count', iterator_to_array($reader->batches('fr_FR', new FeedSettings(), 3), false));

        self::assertGreaterThan(1, \count($sizes));
        self::assertSame(3, $sizes[0]);
    }

    public function testAGenerationThatFailsMidWayLeavesThePreviousFileInPlaceAndNoTemporaryFile(): void
    {
        $this->product('KEEP');
        $path = $this->service()->generate('fr_FR');
        $before = file_get_contents($path);
        $failingTaxes = new class implements TaxCalculatorFactoryInterface {
            public function createTaxCalculator(): TaxCalculatorInterface
            {
                throw new \RuntimeException('taxes unavailable');
            }
        };

        try {
            $this->service($failingTaxes)->generate('fr_FR');
            self::fail('The generation should have failed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('taxes unavailable', $exception->getMessage());
        }

        self::assertSame($before, file_get_contents($path));
        self::assertSame([], glob($this->exportDirectory().'/*.tmp'));
    }

    public function testAFeedWithoutAShopAddressIsRefusedWithAMessageForTheAdministrator(): void
    {
        $this->product('NOURL');
        ConfigQuery::write('url_site', '');

        try {
            $this->service()->generate('fr_FR');
            self::fail('A feed without a shop address must not be written.');
        } catch (FeedGenerationException $exception) {
            self::assertStringContainsString('fr_FR', $exception->getMessage());
        }

        self::assertSame([], glob($this->exportDirectory().'/*'));
    }

    public function testFilesAreListedAndOnlyFeedFilesCanBeReachedByName(): void
    {
        $this->product('LIST');
        $service = $this->service();
        $service->generate('fr_FR');
        file_put_contents($this->exportDirectory().'/other.txt', 'secret');

        self::assertSame(['fluxfacebook_fr_FR.csv'], array_keys($service->files()));
        self::assertNotNull($service->resolve('fluxfacebook_fr_FR.csv'));
        self::assertNull($service->resolve('other.txt'));
        self::assertNull($service->resolve('../facebookfeed-test/fluxfacebook_fr_FR.csv'));
        self::assertNull($service->resolve('fluxfacebook_fr_FR.csv/../other.txt'));

        $service->delete('other.txt');
        self::assertFileExists($this->exportDirectory().'/other.txt');
        $service->delete('fluxfacebook_fr_FR.csv');
        self::assertSame([], $service->files());
    }

    public function testACombinationPricedInSeveralCurrenciesIsWrittenOnceWithItsDefaultCurrencyPrice(): void
    {
        $product = $this->product('MULTI');
        $combination = $this->defaultCombination($product);
        // The core stores a row at 0 for every other currency, flagged as converted.
        foreach ([2, 3] as $currencyId) {
            $currency = \Thelia\Model\CurrencyQuery::create()->findPk($currencyId);
            self::assertNotNull($currency);
            $this->fixtures->productPrice($combination, $currency, ['price' => '0.000000', 'promoPrice' => '0.000000', 'fromDefaultCurrency' => true]);
        }

        $lines = array_filter($this->feed('fr_FR'), static fn (array $line): bool => 'MULTI' === $line['item_group_ID']);

        self::assertCount(1, $lines);
        self::assertSame('12 EUR', array_values($lines)[0]['price']);
    }

    public function testAnUnknownImageFilterSetStopsTheGenerationWithAnExplicitMessage(): void
    {
        $this->product('FILTER');

        try {
            $this->service()->generate('fr_FR', new FeedSettings(imageFilter: 'no_such_filter'));
            self::fail('An unknown filter set must stop the generation.');
        } catch (FeedGenerationException $exception) {
            self::assertStringContainsString('no_such_filter', $exception->getMessage());
        }

        self::assertSame([], glob($this->exportDirectory().'/*'));
    }

    public function testAnImageIsResolvedOncePerFileAndFilterSet(): void
    {
        $product = $this->product('SHARED');
        foreach (range(1, 3) as $index) {
            $this->fixtures->productPrice($this->fixtures->productSaleElement($product), $this->fixtures->currency());
        }
        $this->addImage($product, 1, 'front.jpg');
        $this->addImage($product, 2, 'side.jpg');

        $calls = 0;
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getBrowserPath')->willReturnCallback(static function (string $path) use (&$calls): string {
            ++$calls;

            return 'https://ignored.example'.$path.'?filtered';
        });

        $lines = array_filter($this->feedWith($this->service(null, $this->imageUrls($cacheManager)), 'fr_FR'), static fn (array $line): bool => 'SHARED' === $line['item_group_ID']);

        self::assertCount(4, $lines);
        self::assertSame(2, $calls);
        self::assertSame('https://www.example.fr/product/front.jpg?filtered', array_values($lines)[0]['image_link']);
    }

    public function testAProductWithoutATitleInTheLanguageIsLeftOutOfItsFeed(): void
    {
        $this->useOneDomainForEachLanguage();
        $product = $this->product('ONLYFR', 'Seulement français');

        self::assertContains('ONLYFR', array_column($this->feed('fr_FR'), 'item_group_ID'));
        self::assertNotContains('ONLYFR', array_column($this->feed('de_DE'), 'item_group_ID'));
        self::assertNotNull($product->getId());
    }

    public function testANegativeStockIsWrittenAsZero(): void
    {
        $product = $this->product('NEGATIVE');
        $combination = $this->defaultCombination($product);
        $combination->setQuantity(-3)->save();

        $line = $this->lineOf($this->feed('fr_FR'), 'NEGATIVE-'.$combination->getId());

        self::assertSame('0', $line['quantity_to_sell_on_facebook']);
        self::assertSame('out of stock', $line['availability']);
    }

    public function testTheGenerationLockRefusesASecondRun(): void
    {
        $service = $this->service();
        $first = $service->tryLock();
        self::assertNotNull($first);

        self::assertNull($service->tryLock());

        fclose($first);
        $again = $service->tryLock();
        self::assertNotNull($again);
        fclose($again);
    }

    private function addImage(\Thelia\Model\Product $product, int $position, string $file): void
    {
        $image = (new ProductImage())->setProductId($product->getId())->setPosition($position)->setVisible(1);
        $image->setLocale('fr_FR')->setFile($file);
        $image->setLocale('de_DE')->setFile($file);
        $image->save();
    }
}
