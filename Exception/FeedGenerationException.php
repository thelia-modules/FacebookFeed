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

namespace FacebookFeed\Exception;

/**
 * The feed cannot be written: a business precondition is not met (no shop address for the
 * language, folder not writable). The message is meant for the administrator.
 */
final class FeedGenerationException extends \RuntimeException
{
    public static function noShopUrl(string $locale): self
    {
        return new self(\sprintf('No shop address for the language "%s": set the address of the language (or the shop URL) before generating the feed.', $locale));
    }

    public static function unknownLocale(string $locale): self
    {
        return new self(\sprintf('The language "%s" does not exist.', $locale));
    }

    public static function unknownImageFilter(string $filterSet): self
    {
        return new self(\sprintf('The image filter set "%s" is not configured: choose an existing one in the module settings.', $filterSet));
    }

    public static function directoryNotWritable(string $directory): self
    {
        return new self(\sprintf('The folder "%s" cannot be created or written.', $directory));
    }
}
