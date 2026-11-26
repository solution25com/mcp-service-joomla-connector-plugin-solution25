<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Api;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Solution25\Component\AiConnector\Administrator\Connection\ConnectionStore;

\defined('_JEXEC') or die;

final class ConnectorContext
{
    private function __construct(
        public readonly array $connection,
    ) {
    }

    public static function current(): self
    {
        $user = Factory::getApplication()->getIdentity();
        $connection = (new ConnectionStore(Factory::getContainer()->get(DatabaseInterface::class)))->find();

        if ($user === null || $user->guest || $connection === null || (int) $connection['user_id'] !== (int) $user->id) {
            throw new NotAllowed('Only the AI connector user can use this route.', 403);
        }

        return new self($connection);
    }

    public function has(string $permission): bool
    {
        return \in_array($permission, $this->connection['permissions'], true);
    }

    public function require(string $permission): void
    {
        if (!$this->has($permission)) {
            throw new NotAllowed(\sprintf('This connection does not have the "%s" permission.', $permission), 403);
        }
    }
}
