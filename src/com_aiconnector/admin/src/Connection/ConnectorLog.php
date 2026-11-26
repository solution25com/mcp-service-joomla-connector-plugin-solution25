<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Administrator\Connection;

use Joomla\CMS\Log\Log;
use Solution25\Component\AiConnector\Administrator\Connector;

\defined('_JEXEC') or die;

final class ConnectorLog
{
    public static function add(string $message, int $priority): void
    {
        try {
            Log::add($message, $priority, Connector::LOG_CATEGORY);
        } catch (\Throwable) {
        }
    }
}
