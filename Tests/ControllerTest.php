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

use FacebookFeed\FacebookFeed;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Thelia\Core\HttpFoundation\Session\Session;
use Thelia\Model\LangQuery;

/**
 * The feed address and the configuration screen. The feed file is written in the folder the
 * controllers read (`local/fluxFacebook` of the project) and removed at the end of the test.
 */
final class ControllerTest extends FeedIntegrationTestCase
{
    private string $feedFolder;

    /** @var list<string> */
    private array $createdFiles = [];

    private bool $folderExisted;

    protected function setUp(): void
    {
        parent::setUp();

        $this->feedFolder = static::getContainer()->getParameter('kernel.project_dir').'/local/'.FacebookFeed::EXPORT_DIRECTORY_NAME;
        $this->folderExisted = is_dir($this->feedFolder);
    }

    protected function tearDown(): void
    {
        foreach ($this->createdFiles as $file) {
            @unlink($file);
        }
        if (!$this->folderExisted && is_dir($this->feedFolder)) {
            @rmdir($this->feedFolder);
        }

        parent::tearDown();
    }

    public function testWithoutAFileTheFeedAddressAnswersAnExplicitErrorNotAPageOfTheShop(): void
    {
        $response = $this->handleAsMainRequest($this->frontRequest('/facebookfeed/feed', 'de_DE'));

        self::assertSame(404, $response->getStatusCode());
        self::assertStringStartsWith('text/plain', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('de_DE', (string) $response->getContent());
        self::assertStringNotContainsString('<html', (string) $response->getContent());
    }

    public function testTheFeedAddressServesTheFileOfTheLanguageOfTheDomain(): void
    {
        $this->writeFeedFile('fr_FR', "id;title\nFR-1;Casque\n");
        $this->writeFeedFile('de_DE', "id;title\nDE-1;Helm\n");

        $german = $this->handleAsMainRequest($this->frontRequest('/facebookfeed/feed', 'de_DE'));
        $french = $this->handleAsMainRequest($this->frontRequest('/facebookfeed/feed', 'fr_FR'));

        self::assertSame(200, $german->getStatusCode());
        self::assertStringStartsWith('text/csv', (string) $german->headers->get('Content-Type'));
        self::assertSame("id;title\nDE-1;Helm\n", $this->body($german));
        self::assertSame("id;title\nFR-1;Casque\n", $this->body($french));
    }

    public function testTheConfigurationSavesTheSettingsAndNormalizesTheIdentifiers(): void
    {
        $response = $this->postSettings([
            'color_feature_ids' => ' 14, 12 ,1 ',
            'color_attribute_ids' => '7',
            'size_attribute_ids' => '3,3,4',
            'in_stock_only' => '1',
            'image_filter' => 'product_card',
        ]);

        self::assertSame(302, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('14,12,1', FacebookFeed::getConfigValue(FacebookFeed::FEATURE_COLOR_ID));
        self::assertSame('7', FacebookFeed::getConfigValue(FacebookFeed::ATTRIBUTE_COLOR_ID));
        self::assertSame('3,4', FacebookFeed::getConfigValue(FacebookFeed::ATTRIBUTE_SIZE_ID));
        self::assertSame('1', FacebookFeed::getConfigValue(FacebookFeed::HAS_STOCK));
        self::assertSame('product_card', FacebookFeed::getConfigValue(FacebookFeed::IMAGE_FILTER));
    }

    public function testTheConfigurationRefusesAnInvalidListAndKeepsTheSettings(): void
    {
        FacebookFeed::setConfigValue(FacebookFeed::FEATURE_COLOR_ID, '5');

        $this->postSettings(['color_feature_ids' => '5; DROP TABLE lang', 'color_attribute_ids' => '', 'size_attribute_ids' => '', 'image_filter' => '../x']);

        self::assertSame('5', FacebookFeed::getConfigValue(FacebookFeed::FEATURE_COLOR_ID));
        self::assertNotNull(LangQuery::create()->findOneByLocale('fr_FR'));
    }

    public function testTheConfigurationScreenListsTheFilesAndOnlyServesFeedFiles(): void
    {
        $path = $this->writeFeedFile('fr_FR', "id\nX-1\n");
        file_put_contents(\dirname($path).'/notes.txt', 'private');
        $this->createdFiles[] = \dirname($path).'/notes.txt';

        $screen = $this->handleAsAdmin(Request::create('/admin/module/FacebookFeed'));
        $download = $this->handleAsAdmin(Request::create('/admin/module/FacebookFeed/download/fluxfacebook_fr_FR.csv'));
        $foreign = $this->handleAsAdmin(Request::create('/admin/module/FacebookFeed/download/notes.txt'));

        self::assertSame(200, $screen->getStatusCode(), substr((string) $screen->getContent(), 0, 500));
        self::assertStringContainsString('fluxfacebook_fr_FR.csv', (string) $screen->getContent());
        self::assertStringNotContainsString('notes.txt', (string) $screen->getContent());
        self::assertSame(200, $download->getStatusCode());
        self::assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
        self::assertSame(404, $foreign->getStatusCode());
    }

    public function testDeletingAFileWithoutTokenOrWithAWrongTokenIsRefused(): void
    {
        $path = $this->writeFeedFile('fr_FR', "id\nX-1\n");

        foreach ([[], ['_token' => 'not-the-token']] as $token) {
            $request = Request::create('/admin/module/FacebookFeed/delete', 'POST');
            $request->setSession($this->adminSession());
            $request->request->add(['file_name' => 'fluxfacebook_fr_FR.csv'] + $token);
            $this->handleAsMainRequest($request);

            self::assertFileExists($path);
        }
    }

    public function testAnAdministratorWithoutTheRightCannotSeeChangeOrDeleteAnything(): void
    {
        $path = $this->writeFeedFile('fr_FR', "id\nX-1\n");
        FacebookFeed::setConfigValue(FacebookFeed::FEATURE_COLOR_ID, '5');

        $restrictedAdmin = $this->fixtures->restrictedAdmin([]);
        $download = $this->handleAsRestrictedAdmin(Request::create('/admin/module/FacebookFeed/download/fluxfacebook_fr_FR.csv'), $restrictedAdmin);
        $delete = Request::create('/admin/module/FacebookFeed/delete', 'POST', ['file_name' => 'fluxfacebook_fr_FR.csv']);
        $delete->request->set('_token', 'irrelevant');
        $deleted = $this->handleAsRestrictedAdmin($delete, $restrictedAdmin);
        $settings = Request::create('/admin/module/FacebookFeed/settings', 'POST');
        $settings->request->set('facebookfeed_settings', ['color_feature_ids' => '9', 'color_attribute_ids' => '', 'size_attribute_ids' => '', 'image_filter' => '']);
        $changed = $this->handleAsRestrictedAdmin($settings, $restrictedAdmin);

        foreach ([$download, $deleted, $changed] as $response) {
            self::assertSame(403, $response->getStatusCode());
        }
        self::assertFileExists($path);
        self::assertSame('5', FacebookFeed::getConfigValue(FacebookFeed::FEATURE_COLOR_ID));
    }

    public function testAVisitorWhoIsNotLoggedInGetsNothingFromTheAdministrationRoutes(): void
    {
        $path = $this->writeFeedFile('fr_FR', "id\nX-1\n");

        $download = $this->handleAsMainRequest($this->withSession(Request::create('/admin/module/FacebookFeed/download/fluxfacebook_fr_FR.csv')));
        $delete = $this->handleAsMainRequest($this->withSession(Request::create('/admin/module/FacebookFeed/delete', 'POST', ['file_name' => 'fluxfacebook_fr_FR.csv'])));

        foreach ([$download, $delete] as $response) {
            self::assertContains($response->getStatusCode(), [302, 401, 403]);
            self::assertStringNotContainsString('X-1', (string) $response->getContent());
        }
        self::assertFileExists($path);
    }

    private function withSession(Request $request): Request
    {
        $request->setSession(new Session(new MockArraySessionStorage()));

        return $request;
    }

    private function handleAsRestrictedAdmin(Request $request, \Thelia\Model\Admin $admin): Response
    {
        $session = new Session(new MockArraySessionStorage());
        $session->setAdminUser($admin);
        $request->setSession($session);

        return $this->handleAsMainRequest($request);
    }

    private function writeFeedFile(string $locale, string $content): string
    {
        if (!is_dir($this->feedFolder)) {
            mkdir($this->feedFolder, 0o755, true);
        }
        $path = $this->feedFolder.'/fluxfacebook_'.$locale.'.csv';
        file_put_contents($path, $content);
        $this->createdFiles[] = $path;

        return $path;
    }

    private function body(Response $response): string
    {
        if ($response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse) {
            return (string) file_get_contents($response->getFile()->getPathname());
        }

        return (string) $response->getContent();
    }

    private function frontRequest(string $uri, string $locale): Request
    {
        $lang = LangQuery::create()->findOneByLocale($locale);
        self::assertNotNull($lang);

        $session = new Session(new MockArraySessionStorage());
        $session->setLang($lang);
        $request = Request::create($uri);
        $request->setSession($session);

        return $request;
    }

    /**
     * @param array<string, string> $values
     */
    private function postSettings(array $values): Response
    {
        $request = Request::create('/admin/module/FacebookFeed/settings', 'POST');
        $request->setSession($this->adminSession());
        $request->request->set('facebookfeed_settings', $values + ['_token' => $this->csrfToken($request, 'facebookfeed_settings')]);

        return $this->handleAsMainRequest($request);
    }
}
