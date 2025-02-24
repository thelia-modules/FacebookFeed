<?php

namespace FacebookFeed\Hook;


use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;

class HookPseManager extends BaseHook
{
    public function onPsePrice(HookRenderEvent $event)
    {
        $event->add($this->render(
            'product-edit-pse.html',
            [
                'pseId' => $event->getArgument('pse')
            ]
        ));
    }

    public static function getSubscribedHooks(): array
    {
        return [
            "product.combinations-row" => [
                [
                    "type" => "back",
                    "method" => "onPsePrice"
                ],
            ],
            "product.details-details-form" => [
                [
                    "type" => "back",
                    "method" => "onPsePrice"
                ],
            ]
        ];
    }
}
