<?php

namespace FacebookFeed\EventListeners;

use ClassicRide\ClassicRide;
use ClassicRide\Model\ProductSaleElementsEan13Query;
use FacebookFeed\FacebookFeed;
use FacebookFeed\Model\FacebookFeedProductExcluded;
use FacebookFeed\Model\FacebookFeedProductExcludedQuery;
use Propel\Runtime\Exception\PropelException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\HttpFoundation\RequestStack;
use Thelia\Core\Event\ActionEvent;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Core\Event\TheliaFormEvent;
use Thelia\Core\Translation\Translator;
use Thelia\Model\ProductQuery;

class ProductEditFormListener implements EventSubscriberInterface
{
    public function __construct(
        protected RequestStack $requestStack
    ) {}

    /**
     * Returns an array of event names this subscriber wants to listen to.
     *
     * @return array The event names to listen to
     */
    public static function getSubscribedEvents(): array
    {
        return [
            TheliaEvents::FORM_BEFORE_BUILD . '.thelia_product_sale_element_update_form' => ['extendProductPriceForm', 100],
            TheliaEvents::FORM_BEFORE_BUILD . '.thelia_product_default_sale_element_update_form' => ['extendProductPriceDefaultForm', 100],
            TheliaEvents::PRODUCT_UPDATE_PRODUCT_SALE_ELEMENT => ['saveProductData', 100]
        ];
    }

    /**
     * Add a custom EAN price input to the product
     *
     * @param TheliaFormEvent $event
     * @throws PropelException
     */
    public function extendProductPriceForm(TheliaFormEvent $event)
    {
        if (null === $productId = $event->getForm()->getRequest()->get('product_id')) {
            return ;
        }

        $product = ProductQuery::create()->findPk($productId);

        $pses = $product->getProductSaleElementss();

        foreach ($pses as $pse) {
            $pseExcluded = FacebookFeedProductExcludedQuery::create()
                ->findPk($pse->getId());

            $event->getForm()->getFormBuilder()
                ->add(
                    'facebook_feed_product_excluded_' . $pse->getId(),
                    CheckboxType::class,
                    [
                        'data' => ($pseExcluded) && $pseExcluded->getIsExcluded(),
                        'required' => false,
                        'label' => Translator::getInstance()->trans(
                            'Exclure cette déclinaison du flux facebook',
                            [],
                            FacebookFeed::DOMAIN_NAME
                        )
                    ]
                );
        }
    }

    public function extendProductPriceDefaultForm(TheliaFormEvent $event)
    {
        if (null === $productId = $event->getForm()->getRequest()->get('product_id')) {
            return ;
        }
        $product = ProductQuery::create()->findPk($productId);

        $pseExcluded = FacebookFeedProductExcludedQuery::create()
            ->findPk($product->getDefaultSaleElements()->getId());

        $event->getForm()->getFormBuilder()
            ->add(
                'facebook_feed_product_excluded',
                CheckboxType::class,
                [
                    'data' => ($pseExcluded) && $pseExcluded->getIsExcluded(),
                    'required' => false,
                    'label' => Translator::getInstance()->trans(
                        'Exclure cette déclinaison du flux facebook',
                        [],
                        FacebookFeed::DOMAIN_NAME
                    )
                ]
            );
    }

    /**
     * @param ActionEvent $event
     * @throws PropelException
     */
    public function saveProductData(ActionEvent $event)
    {
        $pseId = $event->getProductSaleElementId();
        $varName = 'facebook_feed_product_excluded_' . $pseId;

        $form = $this->requestStack->getCurrentRequest()->get('thelia_product_sale_element_update_form');

        if (!isset($form[$varName])) {
            if (empty($form['facebook_feed_product_excluded'])) {
                $form[$varName] = null;
            } else {
                $varName = 'facebook_feed_product_excluded';
            }
        }

        $isExcluded = $form[$varName] ?? null;

        $productIsExcluded = FacebookFeedProductExcludedQuery::create()
            ->filterByPseId($pseId)
            ->findOneOrCreate();

        $productIsExcluded->setIsExcluded($isExcluded)->save();
    }
}
