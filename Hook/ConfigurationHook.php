<?php

namespace FacebookFeed\Hook;

use DateTime;
use FacebookFeed\FacebookFeed;
use Thelia\Core\Event\Hook\HookRenderEvent;
use Thelia\Core\Hook\BaseHook;

/**
 * Class HookManager
 * @package GoogleShoppingXml\Hook
 */
class ConfigurationHook extends BaseHook
{
    public function onModuleConfiguration(HookRenderEvent $event): void
    {
        $files = array_diff(scandir(FacebookFeed::EXPORT_DIR), array('.', '..'));
        $i = 0;
        $data = [];
        foreach ($files as $file){
            if (preg_match('/-(\d+)-/', $file, $matches)) {
                $timestamp = (int)$matches[1];
            }
            $datetime = new DateTime("@$timestamp");

            $data[$file] = $datetime->format('Y-m-d H:i:s');
        }

        $event->add($this->render("module-configuration.html",[
            FacebookFeed::ATTRIBUTE_SIZE_ID => FacebookFeed::getConfigValue(FacebookFeed::ATTRIBUTE_SIZE_ID,null),
            FacebookFeed::ATTRIBUTE_COLOR_ID => FacebookFeed::getConfigValue(FacebookFeed::ATTRIBUTE_COLOR_ID,null),
            FacebookFeed::HAS_STOCK => FacebookFeed::getConfigValue(FacebookFeed::HAS_STOCK,null),
            'facebook_feed' => $data,
        ]));
    }

    public static function getSubscribedHooks(): array
    {
        return [
            "module.configuration" => [
                [
                    "type" => "back",
                    "method" => "onModuleConfiguration"
                ],
            ]
        ];
    }
}



