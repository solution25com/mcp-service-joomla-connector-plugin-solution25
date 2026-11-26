<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Administrator;

use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Solution25\Component\AiConnector\Administrator\Connection\AuditLog;
use Solution25\Component\AiConnector\Administrator\Connection\ConnectionService;
use Solution25\Component\AiConnector\Administrator\Connection\ConnectionStore;
use Solution25\Component\AiConnector\Administrator\Connection\ConnectorAccount;
use Solution25\Component\AiConnector\Administrator\Connection\Lock;
use Solution25\Component\AiConnector\Administrator\Connection\PermissionMap;
use Solution25\Component\AiConnector\Administrator\Connection\SiteAddress;
use Solution25\Component\AiConnector\Administrator\Gateway\GatewayClient;
use Solution25\Component\AiConnector\Administrator\Gateway\GatewayConfiguration;
use Solution25\Component\AiConnector\Administrator\Gateway\JoomlaProtocol;

\defined('_JEXEC') or die;

final class Connector
{
    public const COMPONENT = 'com_aiconnector';

    public const MANAGE_ACTION = 'aiconnector.manage';

    public const LOG_CATEGORY = 'aiconnector';

    private static bool $loggerAdded = false;

    public static function service(): ConnectionService
    {
        self::addLogger();
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        return new ConnectionService(
            new GatewayClient(new GatewayConfiguration()),
            new JoomlaProtocol(new PermissionMap()),
            new ConnectionStore($db),
            new ConnectorAccount($db),
            new SiteAddress($db),
            new AuditLog($db),
            new Lock($db),
        );
    }

    public static function auditLog(): AuditLog
    {
        return new AuditLog(Factory::getContainer()->get(DatabaseInterface::class));
    }

    private static function addLogger(): void
    {
        if (self::$loggerAdded) {
            return;
        }

        Log::addLogger(['text_file' => 'aiconnector.php'], Log::WARNING | Log::ERROR | Log::CRITICAL, [self::LOG_CATEGORY]);
        self::$loggerAdded = true;
    }

    public static function canManage(User $user): bool
    {
        return $user->authorise('core.manage', self::COMPONENT) && $user->authorise(self::MANAGE_ACTION, self::COMPONENT);
    }
}
