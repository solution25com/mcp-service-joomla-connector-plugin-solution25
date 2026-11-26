<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Solution25\Component\AiConnector\Administrator\Connection\ConnectorAccount;
use Solution25\Component\AiConnector\Administrator\Connector;

return new class () implements InstallerScriptInterface {
    private const MINIMUM_PHP = '8.1.0';

    private const MINIMUM_JOOMLA = '5.0.0';

    private const NEEDED_PLUGINS = [
        ['system', 'aiconnector'],
        ['webservices', 'aiconnector'],
        ['api-authentication', 'aiconnector'],
        ['api-authentication', 'token'],
        ['user', 'token'],
        ['webservices', 'content'],
        ['webservices', 'menus'],
    ];

    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        $this->registerNamespace();

        try {
            $service = Connector::service();
            $connection = $service->connection();

            if ($connection !== null) {
                $service->disconnect((int) $connection['id'], (int) Factory::getApplication()->getIdentity()?->id);
            }
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage('AI connector: the connection could not be removed cleanly: ' . $e->getMessage(), 'warning');
        }

        $this->removeLeftoverAccounts();

        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if (version_compare(PHP_VERSION, self::MINIMUM_PHP, '<') || version_compare(JVERSION, self::MINIMUM_JOOMLA, '<')) {
            Factory::getApplication()->enqueueMessage(
                \sprintf('The AI connector needs PHP %s and Joomla %s or newer.', self::MINIMUM_PHP, self::MINIMUM_JOOMLA),
                'error'
            );

            return false;
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'install' || $type === 'update' || $type === 'discover_install') {
            $this->enableNeededPlugins();
        }

        return true;
    }

    private function enableNeededPlugins(): void
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);

        foreach (self::NEEDED_PLUGINS as [$folder, $element]) {
            $query = $db->createQuery()
                ->update($db->quoteName('#__extensions'))
                ->set($db->quoteName('enabled') . ' = 1')
                ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                ->where($db->quoteName('folder') . ' = ' . $db->quote($folder))
                ->where($db->quoteName('element') . ' = ' . $db->quote($element));
            $db->setQuery($query)->execute();
        }
    }

    private function removeLeftoverAccounts(): void
    {
        try {
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $account = new ConnectorAccount($db);
            $users = $db->setQuery(
                $db->createQuery()
                    ->select($db->quoteName('id'))
                    ->from($db->quoteName('#__users'))
                    ->where($db->quoteName('username') . ' LIKE ' . $db->quote('ai-connector-%'))
                    ->where($db->quoteName('email') . ' LIKE ' . $db->quote('%@ai-connector.invalid'))
            )->loadColumn() ?: [];

            foreach ($users as $userId) {
                $account->remove((int) $userId, 0);
            }

            $groups = $db->setQuery(
                $db->createQuery()
                    ->select([$db->quoteName('id'), $db->quoteName('title')])
                    ->from($db->quoteName('#__usergroups'))
                    ->where($db->quoteName('parent_id') . ' = ' . ConnectorAccount::PUBLIC_GROUP)
                    ->where($db->quoteName('title') . ' LIKE ' . $db->quote('AI connector %'))
            )->loadAssocList() ?: [];
            $kept = [];

            foreach ($groups as $group) {
                if (!$account->remove(0, (int) $group['id'])) {
                    $kept[] = $group['title'];
                }
            }

            try {
                $account->cleanSystemCache();
            } catch (\Throwable) {
            }

            if ($kept !== []) {
                Factory::getApplication()->enqueueMessage(
                    'AI connector: these user groups were kept because they may not belong to the connector: ' . implode(', ', $kept) . '. Delete them in Users: Groups if the connector made them.',
                    'warning'
                );
            }
        } catch (\Throwable $e) {
            Factory::getApplication()->enqueueMessage('AI connector: leftover connector accounts could not be removed: ' . $e->getMessage(), 'warning');
        }
    }

    private function registerNamespace(): void
    {
        \JLoader::registerNamespace(
            'Solution25\\Component\\AiConnector\\Administrator',
            JPATH_ADMINISTRATOR . '/components/com_aiconnector/src'
        );
    }
};
