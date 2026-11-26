<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Administrator\Connection;

use Joomla\CMS\Cache\CacheControllerFactoryInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

class ConnectorAccount
{
    public const PUBLIC_GROUP = 1;

    public const TOKEN_PROFILE_PREFIX = 'aiconnector.';

    private const TOKEN_ALGORITHM = 'sha256';

    private const TOKEN_SEED_BYTES = 32;

    private const PASSWORD_BYTES = 48;

    private const NAME_SUFFIX_BYTES = 4;

    private const SUPER_USERS_GROUP = 8;

    private const GROUP_TITLE = 'AI connector';

    private const USER_NAME = 'AI connector';

    private const USERNAME_PREFIX = 'ai-connector-';

    private const EMAIL_DOMAIN = '@ai-connector.invalid';

    private const TEXT_FILTER = ['filter_type' => 'BL', 'filter_tags' => '', 'filter_attributes' => ''];

    private const SYSTEM_CACHE_GROUP = '_system';

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {
    }

    public function create(string $clientName, array $rights): array
    {
        $suffix = bin2hex(random_bytes(self::NAME_SUFFIX_BYTES));
        $groupId = $this->createGroup(self::GROUP_TITLE . ' ' . $suffix);
        $this->setTextFilter($groupId);
        $this->applyRights($groupId, $rights);
        $userId = $this->createUser($suffix, $clientName, $groupId);

        return ['userId' => $userId, 'groupId' => $groupId, 'token' => $this->createToken($userId)];
    }

    public function applyRights(int $groupId, array $rights): void
    {
        if (!$this->ownsGroup($groupId) || !$this->isLeaf($groupId)) {
            throw new \RuntimeException('Refusing to change the rights of a group the connector did not create.');
        }

        $rights[] = [PermissionMap::ROOT_ASSET, PermissionMap::API_LOGIN];
        $byAsset = [];

        foreach ($rights as $right) {
            if (!PermissionMap::isAllowedRight($right)) {
                throw new \RuntimeException('Refusing to grant a right outside the connector allow-list.');
            }

            $byAsset[$right[0]][] = $right[1];
        }

        $this->stripGroupFromAssets($groupId);

        foreach ($byAsset as $assetName => $actions) {
            $asset = $this->asset($assetName);

            if ($asset === null) {
                continue;
            }

            $rules = $this->decodeRules((string) $asset['rules']);

            foreach (array_unique($actions) as $action) {
                $rules[$action][(string) $groupId] = 1;
            }

            $this->saveRules((int) $asset['id'], $rules);
        }
    }

    public function revokeAccess(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $query = $this->db->createQuery()
            ->update($this->db->quoteName('#__users'))
            ->set($this->db->quoteName('block') . ' = 1')
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $userId, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
        $this->deleteToken($userId);
        $this->deleteSessions($userId);
    }

    public function remove(int $userId, int $groupId): bool
    {
        if ($userId > 0) {
            $this->revokeAccess($userId);

            foreach (['#__user_usergroup_map', '#__user_profiles'] as $table) {
                $query = $this->db->createQuery()
                    ->delete($this->db->quoteName($table))
                    ->where($this->db->quoteName('user_id') . ' = :id')
                    ->bind(':id', $userId, ParameterType::INTEGER);
                $this->db->setQuery($query)->execute();
            }

            $query = $this->db->createQuery()
                ->delete($this->db->quoteName('#__users'))
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':id', $userId, ParameterType::INTEGER);
            $this->db->setQuery($query)->execute();
        }

        if ($groupId <= 0) {
            return true;
        }

        if (!$this->ownsGroup($groupId) || !$this->isLeaf($groupId)) {
            return false;
        }

        $this->stripGroupFromAssets($groupId);
        $this->disallowTokenForGroup($groupId);
        $this->removeTextFilter($groupId);
        $this->deleteGroup($groupId);

        return true;
    }

    public function ownsGroup(int $groupId): bool
    {
        $query = $this->db->createQuery()
            ->select([$this->db->quoteName('parent_id'), $this->db->quoteName('title')])
            ->from($this->db->quoteName('#__usergroups'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $groupId, ParameterType::INTEGER);
        $group = $this->db->setQuery($query)->loadAssoc();
        $title = '/^' . preg_quote(self::GROUP_TITLE, '/') . ' [0-9a-f]{' . (self::NAME_SUFFIX_BYTES * 2) . '}$/';

        if (!\is_array($group) || (int) $group['parent_id'] !== self::PUBLIC_GROUP || preg_match($title, (string) $group['title']) !== 1) {
            return false;
        }

        return !$this->hasOtherMembers($groupId) && !$this->hasForeignRules($groupId);
    }

    public function hasRights(int $groupId, array $rights): bool
    {
        foreach ($rights as [$assetName, $action]) {
            $asset = $this->asset($assetName);

            if ($asset !== null && ($this->decodeRules((string) $asset['rules'])[$action][$groupId] ?? null) !== 1) {
                return false;
            }
        }

        return true;
    }

    public function cleanSystemCache(): void
    {
        Factory::getContainer()->get(CacheControllerFactoryInterface::class)
            ->createCacheController('callback', ['defaultgroup' => self::SYSTEM_CACHE_GROUP])
            ->clean();
    }

    private function isLeaf(int $groupId): bool
    {
        $query = $this->db->createQuery()
            ->select([$this->db->quoteName('lft'), $this->db->quoteName('rgt')])
            ->from($this->db->quoteName('#__usergroups'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $groupId, ParameterType::INTEGER);
        $group = $this->db->setQuery($query)->loadAssoc();

        return \is_array($group) && (int) $group['rgt'] === (int) $group['lft'] + 1;
    }

    private function hasOtherMembers(int $groupId): bool
    {
        $username = self::USERNAME_PREFIX . '%';
        $email = '%' . self::EMAIL_DOMAIN;
        $query = $this->db->createQuery()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__user_usergroup_map', 'm'))
            ->join('LEFT', $this->db->quoteName('#__users', 'u'), $this->db->quoteName('u.id') . ' = ' . $this->db->quoteName('m.user_id'))
            ->where($this->db->quoteName('m.group_id') . ' = :id')
            ->where('(' . $this->db->quoteName('u.id') . ' IS NULL OR ' . $this->db->quoteName('u.username') . ' NOT LIKE :username OR ' . $this->db->quoteName('u.email') . ' NOT LIKE :email)')
            ->bind(':id', $groupId, ParameterType::INTEGER)
            ->bind(':username', $username)
            ->bind(':email', $email);

        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    private function hasForeignRules(int $groupId): bool
    {
        $pattern = '%"' . $groupId . '":%';
        $query = $this->db->createQuery()
            ->select([$this->db->quoteName('name'), $this->db->quoteName('rules')])
            ->from($this->db->quoteName('#__assets'))
            ->where($this->db->quoteName('rules') . ' LIKE :pattern')
            ->bind(':pattern', $pattern);

        foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $asset) {
            foreach ($this->decodeRules((string) $asset['rules']) as $action => $groups) {
                if (\array_key_exists((string) $groupId, $groups) && ($groups[(string) $groupId] !== 1 || !PermissionMap::isAllowedRight([(string) $asset['name'], $action]))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function createGroup(string $title): int
    {
        $parentId = self::PUBLIC_GROUP;
        $right = (int) $this->db->setQuery(
            'SELECT ' . $this->db->quoteName('rgt') . ' FROM ' . $this->db->quoteName('#__usergroups')
            . ' WHERE ' . $this->db->quoteName('id') . ' = ' . $parentId . ' FOR UPDATE'
        )->loadResult();

        if ($right <= 0) {
            throw new \RuntimeException('The Public user group is missing.');
        }

        $this->db->setQuery(
            'UPDATE ' . $this->db->quoteName('#__usergroups') . ' SET ' . $this->db->quoteName('rgt') . ' = ' . $this->db->quoteName('rgt') . ' + 2 WHERE ' . $this->db->quoteName('rgt') . ' >= ' . $right
        )->execute();
        $this->db->setQuery(
            'UPDATE ' . $this->db->quoteName('#__usergroups') . ' SET ' . $this->db->quoteName('lft') . ' = ' . $this->db->quoteName('lft') . ' + 2 WHERE ' . $this->db->quoteName('lft') . ' > ' . $right
        )->execute();

        $group = (object) ['parent_id' => $parentId, 'lft' => $right, 'rgt' => $right + 1, 'title' => $title];
        $this->db->insertObject('#__usergroups', $group, 'id');

        return (int) $group->id;
    }

    private function deleteGroup(int $groupId): void
    {
        $query = $this->db->createQuery()
            ->select([$this->db->quoteName('lft'), $this->db->quoteName('rgt')])
            ->from($this->db->quoteName('#__usergroups'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $groupId, ParameterType::INTEGER);
        $group = $this->db->setQuery($query)->loadAssoc();

        if (!\is_array($group) || (int) $group['rgt'] !== (int) $group['lft'] + 1) {
            return;
        }

        $right = (int) $group['rgt'];
        $query = $this->db->createQuery()
            ->delete($this->db->quoteName('#__usergroups'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $groupId, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
        $this->db->setQuery(
            'UPDATE ' . $this->db->quoteName('#__usergroups') . ' SET ' . $this->db->quoteName('lft') . ' = ' . $this->db->quoteName('lft') . ' - 2 WHERE ' . $this->db->quoteName('lft') . ' > ' . $right
        )->execute();
        $this->db->setQuery(
            'UPDATE ' . $this->db->quoteName('#__usergroups') . ' SET ' . $this->db->quoteName('rgt') . ' = ' . $this->db->quoteName('rgt') . ' - 2 WHERE ' . $this->db->quoteName('rgt') . ' > ' . $right
        )->execute();
    }

    private function createUser(string $suffix, string $clientName, int $groupId): int
    {
        $user = (object) [
            'name' => mb_substr(self::USER_NAME . ' (' . $clientName . ')', 0, 400),
            'username' => self::USERNAME_PREFIX . $suffix,
            'email' => self::USERNAME_PREFIX . $suffix . self::EMAIL_DOMAIN,
            'password' => UserHelper::hashPassword(bin2hex(random_bytes(self::PASSWORD_BYTES))),
            'block' => 0,
            'sendEmail' => 0,
            'registerDate' => Factory::getDate()->toSql(),
            'activation' => '',
            'params' => '{}',
            'requireReset' => 0,
        ];
        $this->db->insertObject('#__users', $user, 'id');
        $userId = (int) $user->id;

        $map = (object) ['user_id' => $userId, 'group_id' => $groupId];
        $this->db->insertObject('#__user_usergroup_map', $map);

        return $userId;
    }

    private function createToken(int $userId): string
    {
        $seed = random_bytes(self::TOKEN_SEED_BYTES);
        $this->deleteToken($userId);

        $ordering = 1;
        foreach (['token' => base64_encode($seed), 'enabled' => '1'] as $key => $value) {
            $row = (object) [
                'user_id' => $userId,
                'profile_key' => self::TOKEN_PROFILE_PREFIX . $key,
                'profile_value' => $value,
                'ordering' => $ordering++,
            ];
            $this->db->insertObject('#__user_profiles', $row);
        }

        $secret = (string) Factory::getApplication()->get('secret');

        if ($secret === '') {
            throw new \RuntimeException('The site has no secret, so no API token can be made.');
        }

        $hmac = hash_hmac(self::TOKEN_ALGORITHM, $seed, $secret);

        return base64_encode(self::TOKEN_ALGORITHM . ':' . $userId . ':' . $hmac);
    }

    private function deleteToken(int $userId): void
    {
        $this->deleteProfiles($userId, self::TOKEN_PROFILE_PREFIX);
    }

    private function deleteProfiles(int $userId, string $prefix): void
    {
        $pattern = $prefix . '%';
        $query = $this->db->createQuery()
            ->delete($this->db->quoteName('#__user_profiles'))
            ->where($this->db->quoteName('user_id') . ' = :id')
            ->where($this->db->quoteName('profile_key') . ' LIKE :prefix')
            ->bind(':id', $userId, ParameterType::INTEGER)
            ->bind(':prefix', $pattern);
        $this->db->setQuery($query)->execute();
    }

    private function deleteSessions(int $userId): void
    {
        $query = $this->db->createQuery()
            ->delete($this->db->quoteName('#__session'))
            ->where($this->db->quoteName('userid') . ' = :id')
            ->bind(':id', $userId, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
    }

    private function disallowTokenForGroup(int $groupId): void
    {
        $this->changeTokenGroups(static fn (array $groups): array => array_values(array_filter($groups, static fn (int $id): bool => $id !== $groupId)));
    }

    private function changeTokenGroups(\Closure $change): void
    {
        $query = $this->db->createQuery()
            ->select([$this->db->quoteName('extension_id'), $this->db->quoteName('params')])
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('plugin'))
            ->where($this->db->quoteName('folder') . ' = ' . $this->db->quote('user'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('token'));
        $plugin = $this->db->setQuery($query)->loadAssoc();

        if (!\is_array($plugin)) {
            throw new \RuntimeException('The "User - Joomla API Token" plugin is not installed.');
        }

        $params = json_decode((string) $plugin['params'], true);
        $params = \is_array($params) ? $params : [];
        $groups = array_map('intval', (array) ($params['allowedUserGroups'] ?? [self::SUPER_USERS_GROUP]));
        $params['allowedUserGroups'] = array_map('strval', $change($groups));

        $row = (object) ['extension_id' => (int) $plugin['extension_id'], 'params' => json_encode($params, \JSON_UNESCAPED_SLASHES)];
        $this->db->updateObject('#__extensions', $row, 'extension_id');
    }

    private function setTextFilter(int $groupId): void
    {
        $this->changeTextFilters(static function (array $filters) use ($groupId): array {
            $filters[$groupId] = self::TEXT_FILTER;

            return $filters;
        });
    }

    private function removeTextFilter(int $groupId): void
    {
        $this->changeTextFilters(static function (array $filters) use ($groupId): array {
            unset($filters[$groupId]);

            return $filters;
        });
    }

    private function changeTextFilters(\Closure $change): void
    {
        $query = $this->db->createQuery()
            ->select([$this->db->quoteName('extension_id'), $this->db->quoteName('params')])
            ->from($this->db->quoteName('#__extensions'))
            ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
            ->where($this->db->quoteName('element') . ' = ' . $this->db->quote('com_config'));
        $component = $this->db->setQuery($query)->loadAssoc();

        if (!\is_array($component)) {
            throw new \RuntimeException('The global configuration component is not installed.');
        }

        $params = json_decode((string) $component['params']);
        $params = $params instanceof \stdClass ? $params : new \stdClass();
        $filters = isset($params->filters) && \is_object($params->filters) ? (array) $params->filters : [];
        $changed = $change($filters);

        if ($changed === $filters) {
            return;
        }

        $params->filters = (object) $changed;
        $row = (object) ['extension_id' => (int) $component['extension_id'], 'params' => json_encode($params, \JSON_UNESCAPED_SLASHES)];
        $this->db->updateObject('#__extensions', $row, 'extension_id');
    }

    private function stripGroupFromAssets(int $groupId): void
    {
        $pattern = '%"' . $groupId . '":%';
        $query = $this->db->createQuery()
            ->select([$this->db->quoteName('id'), $this->db->quoteName('rules')])
            ->from($this->db->quoteName('#__assets'))
            ->where($this->db->quoteName('rules') . ' LIKE :pattern')
            ->bind(':pattern', $pattern);

        foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $asset) {
            $rules = $this->decodeRules((string) $asset['rules']);
            $changed = false;

            foreach ($rules as $action => $groups) {
                if (\array_key_exists((string) $groupId, $groups)) {
                    unset($rules[$action][(string) $groupId]);
                    $changed = true;
                }

                if ($rules[$action] === []) {
                    unset($rules[$action]);
                }
            }

            if ($changed) {
                $this->saveRules((int) $asset['id'], $rules);
            }
        }
    }

    private function asset(string $name): ?array
    {
        $query = $this->db->createQuery()
            ->select([$this->db->quoteName('id'), $this->db->quoteName('rules')])
            ->from($this->db->quoteName('#__assets'))
            ->where($this->db->quoteName('name') . ' = :name')
            ->bind(':name', $name);
        $asset = $this->db->setQuery($query)->loadAssoc();

        return \is_array($asset) ? $asset : null;
    }

    private function decodeRules(string $json): array
    {
        $rules = json_decode($json, true);

        if (!\is_array($rules)) {
            return [];
        }

        $clean = [];
        foreach ($rules as $action => $groups) {
            $clean[(string) $action] = \is_array($groups) ? array_map('intval', $groups) : [];
        }

        return $clean;
    }

    private function saveRules(int $assetId, array $rules): void
    {
        $encoded = [];
        foreach ($rules as $action => $groups) {
            $encoded[$action] = (object) $groups;
        }

        $row = (object) ['id' => $assetId, 'rules' => json_encode((object) $encoded, \JSON_UNESCAPED_SLASHES)];
        $this->db->updateObject('#__assets', $row, 'id');
    }
}
