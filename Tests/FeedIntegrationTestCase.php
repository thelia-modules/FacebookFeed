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

use FacebookFeed\Service\FacebookFeedService;
use FacebookFeed\Service\FeedImageUrls;
use FacebookFeed\Service\FeedRowReader;
use FacebookFeed\Service\TaxedPrices;
use Liip\ImagineBundle\Imagine\Cache\CacheManager;
use Liip\ImagineBundle\Imagine\Filter\FilterConfiguration;
use Propel\Runtime\Propel;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Domain\Taxation\TaxEngine\TaxCalculatorFactory;
use Thelia\Domain\Taxation\TaxEngine\TaxCalculatorFactoryInterface;
use Thelia\Model\ConfigQuery;
use Thelia\Model\LangQuery;
use Thelia\Model\ModuleConfigQuery;
use Thelia\Model\Product;
use Thelia\Model\ProductSaleElements;
use Thelia\Model\RewritingUrl;
use Thelia\Test\FixtureFactory;
use Thelia\Test\IntegrationTestCase;

/**
 * Runs on the test database of the project (`php bin/test-prepare`), never on the shop's.
 * Each test writes its feed in a folder of its own, removed afterwards.
 */
abstract class FeedIntegrationTestCase extends IntegrationTestCase
{
    protected FixtureFactory $fixtures;

    private string $exportDirectory;

    protected function setUp(): void
    {
        $databaseName = $_SERVER['DATABASE_NAME'] ?? getenv('DATABASE_NAME');
        if (!\is_string($databaseName) || !str_ends_with($databaseName, '_test')) {
            self::fail(\sprintf('Refusing to run on the database "%s": use a *_test database.', (string) $databaseName));
        }

        parent::setUp();

        // var/propel/test keeps the database of the last agent that used it: check the target
        // before any write.
        $statement = $this->getPropelConnection()->query('SELECT DATABASE()');
        self::assertSame($databaseName, $statement->fetchColumn(), 'The connection does not point to the test database.');

        $this->fixtures = $this->createFixtureFactory();
        $this->exportDirectory = sys_get_temp_dir().'/facebookfeed-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->exportDirectory)) {
            foreach (array_diff(scandir($this->exportDirectory) ?: [], ['.', '..']) as $file) {
                unlink($this->exportDirectory.'/'.$file);
            }
            rmdir($this->exportDirectory);
        }

        parent::tearDown();

        // The configuration caches outlive the rolled back transaction.
        ConfigQuery::resetCache();
        ModuleConfigQuery::resetConfigCache();
    }

    protected function service(?TaxCalculatorFactoryInterface $taxCalculatorFactory = null, ?FeedImageUrls $imageUrls = null): FacebookFeedService
    {
        return new FacebookFeedService(
            new FeedRowReader(),
            new TaxedPrices($taxCalculatorFactory ?? new TaxCalculatorFactory()),
            $imageUrls ?? $this->imageUrls(),
            $this->exportDirectory,
        );
    }

    protected function imageUrls(?CacheManager $cacheManager = null): FeedImageUrls
    {
        $filterConfiguration = static::getContainer()->get('liip_imagine.filter.configuration');
        self::assertInstanceOf(FilterConfiguration::class, $filterConfiguration);
        $cacheManager ??= static::getContainer()->get(CacheManager::class);
        self::assertInstanceOf(CacheManager::class, $cacheManager);

        return new FeedImageUrls($cacheManager, $filterConfiguration);
    }

    /**
     * One domain for each language, the way the shop runs.
     */
    protected function useOneDomainForEachLanguage(): void
    {
        ConfigQuery::write('one_domain_foreach_lang', '1');
        $this->giveLanguageAnAddress('fr_FR', 'https://www.example.fr');
        $this->giveLanguageAnAddress('de_DE', 'https://www.example.de');
    }

    protected function giveLanguageAnAddress(string $locale, string $address): void
    {
        $lang = LangQuery::create()->findOneByLocale($locale);
        self::assertNotNull($lang, \sprintf('The test database has no %s language.', $locale));
        $lang->setUrl($address)->save();
    }

    /**
     * A visible product with its default combination (price 10 excluding taxes, 20% tax).
     *
     * @param array<string, mixed> $overrides
     */
    protected function product(string $reference, string $title = 'A helmet', array $overrides = []): Product
    {
        $product = $this->fixtures->product(
            $this->fixtures->category(),
            $this->fixtures->taxRule(),
            $this->fixtures->currency(),
            $overrides + ['ref' => $reference, 'title' => $title, 'locale' => 'fr_FR'],
        );

        return $product;
    }

    protected function defaultCombination(Product $product): ProductSaleElements
    {
        $combination = $product->getDefaultSaleElements();
        self::assertNotNull($combination);

        return $combination;
    }

    protected function addressOf(Product $product, string $locale, string $url): void
    {
        (new RewritingUrl())
            ->setUrl($url)
            ->setView('product')
            ->setViewId((string) $product->getId())
            ->setViewLocale($locale)
            ->save();
    }

    /**
     * @return list<array<string, string>> the lines of the feed of a language, by column name
     */
    protected function feed(string $locale, ?\FacebookFeed\Service\FeedSettings $settings = null): array
    {
        return $this->feedWith($this->service(), $locale, $settings);
    }

    /**
     * @return list<array<string, string>>
     */
    protected function feedWith(FacebookFeedService $service, string $locale, ?\FacebookFeed\Service\FeedSettings $settings = null): array
    {
        $path = $service->generate($locale, $settings);

        $handle = fopen($path, 'r');
        self::assertNotFalse($handle);
        $header = fgetcsv($handle, null, ';', '"', '');
        self::assertIsArray($header);

        $lines = [];
        while (false !== $line = fgetcsv($handle, null, ';', '"', '')) {
            self::assertCount(\count($header), $line);
            $lines[] = array_combine($header, $line);
        }
        fclose($handle);

        return $lines;
    }

    /**
     * @param list<array<string, string>> $feed
     *
     * @return array<string, string>
     */
    protected function lineOf(array $feed, string $identifier): array
    {
        foreach ($feed as $line) {
            if ($line['id'] === $identifier) {
                return $line;
            }
        }

        self::fail(\sprintf('The feed has no line "%s".', $identifier));
    }

    protected function countQueries(callable $callable): int
    {
        $connection = Propel::getReadConnection('TheliaMain');
        $connection->useDebug(true);
        $before = $connection->getQueryCount();

        $callable();

        return $connection->getQueryCount() - $before;
    }

    protected function exportDirectory(): string
    {
        return $this->exportDirectory;
    }

    protected function handleAsAdmin(Request $request): Response
    {
        $request->setSession($this->adminSession());

        return $this->handleAsMainRequest($request);
    }

    protected function adminSession(): Session
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setAdminUser($this->fixtures->admin());

        return $session;
    }

    /**
     * IntegrationTestCase pushes a synthetic request: it is taken off the stack while the
     * kernel handles this one, so that this request is the main request the controller reads.
     */
    protected function handleAsMainRequest(Request $request): Response
    {
        $requestStack = $this->requestStack();
        $pushedRequests = [];
        while (null !== $pushedRequest = $requestStack->pop()) {
            $pushedRequests[] = $pushedRequest;
        }

        try {
            return self::$kernel->handle($request);
        } finally {
            while (null !== $requestStack->pop()) {
            }
            foreach (array_reverse($pushedRequests) as $pushedRequest) {
                $requestStack->push($pushedRequest);
            }
        }
    }

    protected function requestStack(): RequestStack
    {
        $requestStack = static::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $requestStack);

        return $requestStack;
    }

    protected function csrfToken(Request $request, string $tokenId): string
    {
        $requestStack = $this->requestStack();
        $tokenManager = static::getContainer()->get('security.csrf.token_manager');
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $tokenManager);

        $requestStack->push($request);
        try {
            return $tokenManager->getToken($tokenId)->getValue();
        } finally {
            $requestStack->pop();
        }
    }
}
