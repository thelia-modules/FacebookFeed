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

namespace FacebookFeed;

use Propel\Runtime\Connection\ConnectionInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ServicesConfigurator;
use Thelia\Core\Install\Database;
use Thelia\Module\BaseModule;

class FacebookFeed extends BaseModule
{
    public const DOMAIN_NAME = 'facebookfeed';

    /** Folder of the generated files, under the `local/` directory of the project. */
    public const EXPORT_DIRECTORY_NAME = 'fluxFacebook';

    public const ATTRIBUTE_COLOR_ID = 'attribute_color_id';
    public const FEATURE_COLOR_ID = 'feature_color_id';
    public const ATTRIBUTE_SIZE_ID = 'attribute_size_id';
    public const HAS_STOCK = 'has_stock';
    public const IMAGE_FILTER = 'image_filter';

    /** Filter set that serves the image as uploaded: defined by the Flexy theme, not by the image library. */
    public const DEFAULT_IMAGE_FILTER = 'default';

    /**
     * Creates the exclusion table once. The script never drops anything: on a database carried
     * over from the 0.x line, the exclusions already stored are kept.
     */
    public function postActivation(?ConnectionInterface $con = null): void
    {
        if ('1' === self::getConfigValue('is_initialized')) {
            return;
        }

        (new Database($con))->insertSql(null, [__DIR__.'/Config/TheliaMain.sql']);

        self::setConfigValue('is_initialized', '1');
    }

    public static function configureServices(ServicesConfigurator $servicesConfigurator): void
    {
        $servicesConfigurator->load(self::getModuleCode().'\\', __DIR__)
            ->exclude([
                __DIR__.'/I18n/*',
                __DIR__.'/Model/*',
                __DIR__.'/Tests/*',
                __DIR__.'/templates/*',
            ])
            ->autowire()
            ->autoconfigure();
    }
}
