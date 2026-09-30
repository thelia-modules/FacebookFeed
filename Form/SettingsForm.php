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

namespace FacebookFeed\Form;

use FacebookFeed\FacebookFeed;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Validator\Constraints\Regex;
use Thelia\Core\Translation\Translator;
use Thelia\Form\BaseForm;

/**
 * The settings of the feed: which attributes and features tell the color and the size, whether
 * combinations out of stock are left out, and the image filter set of the library.
 */
class SettingsForm extends BaseForm
{
    private const IDENTIFIER_LIST = '/^\s*\d+(\s*,\s*\d+)*\s*$/';

    protected function buildForm(): void
    {
        $translator = Translator::getInstance();
        $identifierList = new Regex(
            pattern: self::IDENTIFIER_LIST,
            message: $translator->trans('Enter identifiers separated by commas, for example 14,12,1', [], FacebookFeed::DOMAIN_NAME),
        );

        $this->formBuilder
            ->add('color_feature_ids', TextType::class, [
                'required' => false,
                'constraints' => [$identifierList],
                'label' => $translator->trans('Features that give the color', [], FacebookFeed::DOMAIN_NAME),
                'label_attr' => ['help' => $translator->trans('Identifiers of the features (14,12,1). The first one that has a value for the product gives the color.', [], FacebookFeed::DOMAIN_NAME)],
            ])
            ->add('color_attribute_ids', TextType::class, [
                'required' => false,
                'constraints' => [$identifierList],
                'label' => $translator->trans('Attributes that give the color', [], FacebookFeed::DOMAIN_NAME),
                'label_attr' => ['help' => $translator->trans('Identifiers of the attributes (14,12,1), used when no feature gives a color.', [], FacebookFeed::DOMAIN_NAME)],
            ])
            ->add('size_attribute_ids', TextType::class, [
                'required' => false,
                'constraints' => [$identifierList],
                'label' => $translator->trans('Attributes that give the size', [], FacebookFeed::DOMAIN_NAME),
                'label_attr' => ['help' => $translator->trans('Identifiers of the attributes (14,12,1).', [], FacebookFeed::DOMAIN_NAME)],
            ])
            ->add('in_stock_only', CheckboxType::class, [
                'required' => false,
                'label' => $translator->trans('Only combinations in stock', [], FacebookFeed::DOMAIN_NAME),
                'label_attr' => ['help' => $translator->trans('When checked, combinations without stock are left out of the feed.', [], FacebookFeed::DOMAIN_NAME)],
            ])
            ->add('image_filter', TextType::class, [
                'required' => false,
                'constraints' => [new Regex(pattern: '/^[A-Za-z0-9_\-]*$/', message: $translator->trans('Enter the name of an image filter set', [], FacebookFeed::DOMAIN_NAME))],
                'label' => $translator->trans('Image filter set', [], FacebookFeed::DOMAIN_NAME),
                'label_attr' => ['help' => $translator->trans('Filter set of the image library used for the image links (default: the image as uploaded).', [], FacebookFeed::DOMAIN_NAME)],
            ]);
    }

    public static function getName(): string
    {
        return 'facebookfeed_settings';
    }
}
