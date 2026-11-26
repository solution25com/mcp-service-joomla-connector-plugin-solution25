<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Administrator\Connection;

use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Solution25\Component\AiConnector\Administrator\Gateway\GatewayException;

\defined('_JEXEC') or die;

class Lock
{
    private const NAME_PREFIX = 'aiconnector_';

    private const WAIT_SECONDS = 0;

    private const ACQUIRED = 1;

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {
    }

    public function exclusively(\Closure $work): mixed
    {
        $name = 'CONCAT(' . $this->db->quote(self::NAME_PREFIX) . ', MD5(CONCAT(DATABASE(), ' . $this->db->quote('.' . $this->db->getPrefix()) . ')))';

        try {
            $result = $this->db->setQuery('SELECT GET_LOCK(' . $name . ', ' . self::WAIT_SECONDS . ')')->loadResult();
        } catch (\Throwable $e) {
            ConnectorLog::add('Taking the connection lock failed: ' . $e::class . ' ' . $e->getMessage(), Log::ERROR);

            throw GatewayException::localFailure();
        }

        if ($result === null) {
            ConnectorLog::add('Taking the connection lock failed: the database returned no result.', Log::ERROR);

            throw GatewayException::localFailure();
        }

        if ((int) $result !== self::ACQUIRED) {
            throw GatewayException::busy();
        }

        try {
            return $work();
        } finally {
            try {
                $this->db->setQuery('SELECT RELEASE_LOCK(' . $name . ')')->execute();
            } catch (\Throwable) {
            }
        }
    }
}
