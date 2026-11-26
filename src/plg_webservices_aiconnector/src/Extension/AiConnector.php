<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Plugin\WebServices\AiConnector\Extension;

use Joomla\CMS\Event\Application\BeforeApiRouteEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Router\Route;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

final class AiConnector extends CMSPlugin implements SubscriberInterface
{
    private const COMPONENT = 'com_aiconnector';

    private const PREFIX = 'v1/aiconnector/';

    private const ROUTES = [
        [['GET'], 'status', 'status.show', []],
    ];

    public static function getSubscribedEvents(): array
    {
        return ['onBeforeApiRoute' => 'onBeforeApiRoute'];
    }

    public function onBeforeApiRoute(BeforeApiRouteEvent $event): void
    {
        $routes = [];

        foreach (self::ROUTES as [$methods, $path, $task, $patterns]) {
            $routes[] = new Route($methods, self::PREFIX . $path, $task, $patterns, ['component' => self::COMPONENT, 'public' => false]);
        }

        $event->getRouter()->addRoutes($routes);
    }
}
