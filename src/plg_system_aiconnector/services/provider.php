<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Solution25\Plugin\System\AiConnector\Extension\ApiGuard;

return new class () implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container) {
                $config = (array) PluginHelper::getPlugin('system', 'aiconnector');
                $firstParameter = (new \ReflectionMethod(CMSPlugin::class, '__construct'))->getParameters()[0]->getName();
                $plugin = $firstParameter === 'config'
                    ? new ApiGuard($config)
                    : new ApiGuard($container->get(DispatcherInterface::class), $config);
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
