<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Api\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;
use Solution25\Component\AiConnector\Administrator\Connection\SiteAddress;
use Solution25\Component\AiConnector\Api\ConnectorContext;

\defined('_JEXEC') or die;

class StatusController extends ResourceController
{
    protected $contentType = 'status';

    public function show(): static
    {
        $context = ConnectorContext::current();
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $hikashop = (string) $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName('manifest_cache'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('element') . ' = ' . $db->quote('com_hikashop'))
                ->where($db->quoteName('enabled') . ' = 1')
        )->loadResult();
        $hikashopVersion = json_decode($hikashop, true)['version'] ?? null;

        return $this->item([
            'id' => (string) $context->connection['gateway_connection_id'],
            'siteUrl' => (string) $context->connection['site_url'],
            'permissions' => $context->connection['permissions'],
            'joomlaVersion' => (new Version())->getShortVersion(),
            'extensionVersion' => SiteAddress::EXTENSION_VERSION,
            'hikashopVersion' => $hikashopVersion,
        ]);
    }
}
