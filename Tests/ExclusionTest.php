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

use FacebookFeed\EventListener\ProductCloneListener;
use FacebookFeed\Model\FacebookFeedProductExcludedQuery;
use FacebookFeed\Service\ExclusionRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Thelia\Core\Event\Product\ProductCloneEvent;
use Thelia\Model\Product;

final class ExclusionTest extends FeedIntegrationTestCase
{
    private ExclusionRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new ExclusionRepository();
    }

    public function testManyExclusionsAreWrittenAtOnceAndUnknownCombinationsAreReturned(): void
    {
        $product = $this->product('SAVE');
        $second = $this->fixtures->productSaleElement($product);
        $first = $this->defaultCombination($product);
        $unknownId = 999999999;

        $unknown = $this->repository->save([$first->getId() => true, $second->getId() => false, $unknownId => true]);

        self::assertSame([$unknownId], $unknown);
        self::assertSame(1, (int) FacebookFeedProductExcludedQuery::create()->findPk($first->getId())?->getIsExcluded());
        self::assertSame(0, (int) FacebookFeedProductExcludedQuery::create()->findPk($second->getId())?->getIsExcluded());
        self::assertNull(FacebookFeedProductExcludedQuery::create()->findPk($unknownId));

        $this->repository->save([$first->getId() => false, $second->getId() => true]);

        self::assertSame([$second->getId()], $this->repository->excludedCombinationIdsOfProduct($product->getId()));
    }

    public function testTheCombinationsOfAProductShowTheirReferenceAndTheirAttributeValues(): void
    {
        $attribute = $this->fixtures->attribute(['locale' => 'fr_FR']);
        $product = $this->product('LIST');
        $second = $this->fixtures->productSaleElement($product, ['ref' => 'LIST-XL']);
        $this->fixtures->attributeCombination($second, $this->fixtures->attributeAv($attribute, ['locale' => 'fr_FR', 'title' => 'XL']));
        $this->repository->save([$second->getId() => true]);

        $combinations = $this->repository->combinationsOfProduct($product->getId(), 'fr_FR');

        self::assertSame(
            [
                ['id' => $this->defaultCombination($product)->getId(), 'reference' => $product->getRef(), 'label' => '', 'excluded' => false],
                ['id' => $second->getId(), 'reference' => 'LIST-XL', 'label' => 'XL', 'excluded' => true],
            ],
            $combinations,
        );
    }

    public function testTheProductTabSavesTheCheckedCombinationsAndPutsTheOthersBack(): void
    {
        $product = $this->product('TAB');
        $second = $this->fixtures->productSaleElement($product);
        $first = $this->defaultCombination($product);
        $this->repository->save([$first->getId() => true]);

        $response = $this->postExclusions($product, [$second->getId()]);

        self::assertSame(302, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$second->getId()], $this->repository->excludedCombinationIdsOfProduct($product->getId()));
        self::assertSame(0, (int) FacebookFeedProductExcludedQuery::create()->findPk($first->getId())?->getIsExcluded());
    }

    public function testACheckedBoxOfAnotherProductIsRefused(): void
    {
        $product = $this->product('MINE');
        $other = $this->product('OTHER');
        $foreignCombination = $this->defaultCombination($other);

        $response = $this->postExclusions($product, [$foreignCombination->getId()]);

        self::assertSame([], $this->repository->excludedCombinationIdsOfProduct($other->getId()));
        self::assertSame([], $this->repository->excludedCombinationIdsOfProduct($product->getId()));
        self::assertNotSame(200, $response->getStatusCode());
    }

    public function testTheProductPageShowsTheBoxesAndTheirState(): void
    {
        $product = $this->product('PAGE');
        $second = $this->fixtures->productSaleElement($product);
        $this->fixtures->productPrice($second, $this->fixtures->currency());
        $this->repository->save([$second->getId() => true]);

        $response = $this->handleAsAdmin(Request::create('/admin/products/update', 'GET', ['product_id' => $product->getId(), 'current_tab' => 'modules']));

        self::assertSame(200, $response->getStatusCode(), substr((string) $response->getContent(), 0, 500));
        $html = (string) $response->getContent();
        self::assertStringContainsString('facebookfeed_exclusion[excluded_combinations][]', $html);
        self::assertMatchesRegularExpression('/value="'.$second->getId().'"[^>]*checked/', $html);
        self::assertDoesNotMatchRegularExpression('/value="'.$this->defaultCombination($product)->getId().'"[^>]*checked/', $html);
    }

    public function testAClonedProductIsExcludedWhereItsOriginalIs(): void
    {
        $attribute = $this->fixtures->attribute(['locale' => 'fr_FR']);
        $small = $this->fixtures->attributeAv($attribute, ['locale' => 'fr_FR', 'title' => 'S']);
        $large = $this->fixtures->attributeAv($attribute, ['locale' => 'fr_FR', 'title' => 'L']);
        $original = $this->product('ORIGINAL');
        $cloned = $this->product('CLONED');
        $combinations = [];
        foreach ([$original, $cloned] as $product) {
            foreach ([$small, $large] as $value) {
                $combination = $this->fixtures->productSaleElement($product);
                $this->fixtures->attributeCombination($combination, $value);
                $combinations[$product->getRef()][$value->getId()] = $combination;
            }
        }
        $this->repository->save([$combinations['ORIGINAL'][$large->getId()]->getId() => true]);

        // The clone made by the core is not replayed: its listeners of the project need tables the
        // test database does not have. Only the listener of the module is called.
        $event = new ProductCloneEvent('CLONED', 'fr_FR', $original);
        $event->setClonedProduct($cloned);
        (new ProductCloneListener($this->repository))->copyExclusions($event);

        self::assertSame([$combinations['CLONED'][$large->getId()]->getId()], $this->repository->excludedCombinationIdsOfProduct($cloned->getId()));
    }

    public function testWhenTwoOriginalCombinationsShareTheirValuesTheOldestOneDecides(): void
    {
        $attribute = $this->fixtures->attribute(['locale' => 'fr_FR']);
        $value = $this->fixtures->attributeAv($attribute, ['locale' => 'fr_FR', 'title' => 'S']);
        $original = $this->product('TWINS');
        $cloned = $this->product('TWINCLONE');
        $oldest = $this->fixtures->productSaleElement($original);
        $newest = $this->fixtures->productSaleElement($original);
        $copy = $this->fixtures->productSaleElement($cloned);
        foreach ([$oldest, $newest, $copy] as $combination) {
            $this->fixtures->attributeCombination($combination, $value);
        }
        $this->repository->save([$oldest->getId() => true, $newest->getId() => false]);

        $event = new ProductCloneEvent('TWINCLONE', 'fr_FR', $original);
        $event->setClonedProduct($cloned);
        (new ProductCloneListener($this->repository))->copyExclusions($event);

        self::assertSame([$copy->getId()], $this->repository->excludedCombinationIdsOfProduct($cloned->getId()));
    }

    /**
     * @param list<int> $checkedCombinationIds
     */
    private function postExclusions(Product $product, array $checkedCombinationIds): Response
    {
        $request = Request::create('/admin/module/FacebookFeed/product/'.$product->getId().'/exclusions', 'POST');
        $request->setSession($this->adminSession());
        $request->request->set('facebookfeed_exclusion', [
            'excluded_combinations' => array_map('strval', $checkedCombinationIds),
            '_token' => $this->csrfToken($request, 'facebookfeed_exclusion'),
        ]);

        return $this->handleAsMainRequest($request);
    }
}
