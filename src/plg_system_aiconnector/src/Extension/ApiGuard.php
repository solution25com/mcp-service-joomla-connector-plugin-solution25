<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Plugin\System\AiConnector\Extension;

use Joomla\CMS\Access\Exception\NotAllowed;
use Joomla\CMS\Event\Model\BeforeSaveEvent;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

final class ApiGuard extends CMSPlugin implements SubscriberInterface
{
    private const OWN_COMPONENT = 'com_aiconnector';

    private const READ = ['displayList', 'displayItem'];

    private const SITE_CLIENT = 0;

    private const ARTICLE_CONTEXT = 'com_content.article';

    private const ARTICLE_TEXT_COLUMNS = ['introtext', 'fulltext'];

    private const PATCH = 'PATCH';

    private const CONNECTOR_LOGIN = 'AiConnector';

    private const CONNECTOR_USERNAME_PREFIX = 'ai-connector-';

    private const CONNECTOR_EMAIL_DOMAIN = '@ai-connector.invalid';

    private const TEXT = 'text';

    private const ID = 'id';

    private const ARTICLE_EDIT_FIELDS = [
        'title' => self::TEXT,
        'articletext' => self::TEXT,
        'introtext' => self::TEXT,
        'fulltext' => self::TEXT,
        'metadesc' => self::TEXT,
        'state' => [0, 1],
    ];

    private const ARTICLE_CREATE_FIELDS = [
        'title' => self::TEXT,
        'catid' => self::ID,
        'articletext' => self::TEXT,
        'introtext' => self::TEXT,
        'fulltext' => self::TEXT,
        'metadesc' => self::TEXT,
        'language' => self::TEXT,
        'state' => [0],
    ];

    private const ROUTES = [
        'content.viewer' => [
            ['com_content', ['articles'], self::READ, [], null],
            ['com_categories', ['categories'], self::READ, ['extension' => 'com_content'], null],
        ],
        'content.creator' => [
            ['com_content', ['articles'], ['add'], [], self::ARTICLE_CREATE_FIELDS],
        ],
        'content.editor' => [
            ['com_content', ['articles'], ['edit'], [], self::ARTICLE_EDIT_FIELDS],
        ],
        'menu.viewer' => [
            ['com_menus', ['menus', 'items'], ['displayList'], ['client_id' => self::SITE_CLIENT], null],
        ],
    ];

    private array $untouchedArticleText = [];

    public static function getSubscribedEvents(): array
    {
        return ['onUserAfterLogin' => 'onUserAfterLogin', 'onContentBeforeSave' => 'onContentBeforeSave'];
    }

    public function onUserAfterLogin(Event $event): void
    {
        if (!$this->getApplication()->isClient('api')) {
            return;
        }

        $options = $event->getArgument('options');
        $user = \is_array($options) ? ($options['user'] ?? null) : null;

        if (!$user instanceof User || $user->guest) {
            return;
        }

        try {
            $connection = $this->connectionOf((int) $user->id);
        } catch (\Throwable) {
            if ($this->isConnectorLogin($user, $options)) {
                throw new NotAllowed('The AI connector cannot be checked right now.', 403);
            }

            return;
        }

        if ($connection === null) {
            if ($this->isConnectorLogin($user, $options)) {
                throw new NotAllowed('The AI connector is not connected.', 403);
            }

            return;
        }

        if (!$this->allows($connection)) {
            throw new NotAllowed('The AI connector is not allowed to use this route.', 403);
        }

        $this->untouchedArticleText = $this->getApplication()->getInput()->getMethod() === self::PATCH
            ? $this->untouchedArticleText()
            : [];
    }

    public function onContentBeforeSave(Event $event): void
    {
        if ($this->untouchedArticleText === [] || !$event instanceof BeforeSaveEvent
            || $event->getContext() !== self::ARTICLE_CONTEXT || $event->getIsNew()) {
            return;
        }

        $article = $event->getItem();
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $stored = $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName($this->untouchedArticleText))
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('id') . ' = ' . (int) $article->id)
        )->loadAssoc();

        foreach (\is_array($stored) ? $stored : [] as $column => $value) {
            $article->{$column} = $value;
        }
    }

    private function untouchedArticleText(): array
    {
        return array_values(array_diff(self::ARTICLE_TEXT_COLUMNS, array_keys($this->requestData())));
    }

    private function requestData(): array
    {
        $input = $this->getApplication()->getInput();
        $data = $input->get('data', json_decode($input->json->getRaw(), true), 'array');

        return \is_array($data) ? $data : [];
    }

    private function allows(array $connection): bool
    {
        $input = $this->getApplication()->getInput();
        $option = $input->getCmd('option');
        $controller = $input->getCmd('controller');
        $task = $input->getCmd('task');

        if ($option === self::OWN_COMPONENT) {
            return true;
        }

        foreach ($connection['permissions'] as $permission) {
            foreach (self::ROUTES[$permission] ?? [] as [$routeOption, $controllers, $tasks, $required, $fields]) {
                if ($option !== $routeOption || !\in_array($controller, $controllers, true) || !\in_array($task, $tasks, true)) {
                    continue;
                }

                foreach ($required as $name => $value) {
                    if ((string) $input->get($name, '', 'string') !== (string) $value) {
                        continue 2;
                    }
                }

                if ($fields !== null && !$this->sendsOnly($fields)) {
                    continue;
                }

                return true;
            }
        }

        return false;
    }

    private function sendsOnly(array $fields): bool
    {
        foreach ($this->requestData() as $name => $value) {
            $rule = $fields[$name] ?? null;
            $valid = match (true) {
                $rule === self::TEXT => \is_string($value),
                $rule === self::ID => $this->isWholeNumber($value) && (int) $value > 0,
                \is_array($rule) => $this->isWholeNumber($value) && \in_array((int) $value, $rule, true),
                default => false,
            };

            if (!$valid) {
                return false;
            }
        }

        return true;
    }

    private function isWholeNumber(mixed $value): bool
    {
        return \is_int($value) || (\is_string($value) && preg_match('/^-?\d+$/', $value) === 1);
    }

    private function isConnectorLogin(User $user, array $options): bool
    {
        return ($options['responseType'] ?? '') === self::CONNECTOR_LOGIN
            || (str_starts_with((string) $user->username, self::CONNECTOR_USERNAME_PREFIX)
                && str_ends_with((string) $user->email, self::CONNECTOR_EMAIL_DOMAIN));
    }

    private function connectionOf(int $userId): ?array
    {
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $row = $db->setQuery(
            $db->createQuery()
                ->select($db->quoteName('permissions'))
                ->from($db->quoteName('#__aiconnector_connection'))
                ->where($db->quoteName('user_id') . ' = ' . $userId)
        )->loadAssoc();

        if (!\is_array($row)) {
            return null;
        }

        $permissions = json_decode((string) $row['permissions'], true);

        return [
            'permissions' => \is_array($permissions) ? array_values(array_filter($permissions, 'is_string')) : [],
        ];
    }
}
