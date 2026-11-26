<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Plugin\ApiAuthentication\AiConnector\Extension;

use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\User\AuthenticationEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\User\UserFactoryAwareTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\ParameterType;
use Joomla\Event\SubscriberInterface;

\defined('_JEXEC') or die;

final class ConnectorToken extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;
    use UserFactoryAwareTrait;

    public const RESPONSE_TYPE = 'AiConnector';

    public const PROFILE_PREFIX = 'aiconnector.';

    private const ALGORITHM = 'sha256';

    private const COMPONENT = 'com_aiconnector';

    private const GUARD_FOLDER = 'system';

    private const GUARD_ELEMENT = 'aiconnector';

    private const BEARER = 'Bearer ';

    public static function getSubscribedEvents(): array
    {
        return ['onUserAuthenticate' => 'onUserAuthenticate'];
    }

    public function onUserAuthenticate(AuthenticationEvent $event): void
    {
        $token = $this->requestToken();

        if ($token === null) {
            return;
        }

        [$userId, $hmac] = $token;

        if (!PluginHelper::isEnabled(self::GUARD_FOLDER, self::GUARD_ELEMENT) || !ComponentHelper::isEnabled(self::COMPONENT)
            || !$this->isConnectionUser($userId)) {
            return;
        }

        $profile = $this->tokenProfile($userId);
        $secret = (string) $this->getApplication()->get('secret');
        $seed = base64_decode($profile['token'] ?? '', true);

        if ($secret === '' || ($profile['enabled'] ?? '') !== '1' || $seed === false || $seed === ''
            || !hash_equals(hash_hmac(self::ALGORITHM, $seed, $secret), $hmac)) {
            return;
        }

        $user = $this->getUserFactory()->loadUserById($userId);

        if ((int) $user->id !== $userId || $user->block || trim((string) $user->activation) !== '' || $user->requireReset) {
            return;
        }

        $response = $event->getAuthenticationResponse();
        $response->type = self::RESPONSE_TYPE;
        $response->status = Authentication::STATUS_SUCCESS;
        $response->error_message = '';
        $response->username = $user->username;
        $response->email = $user->email;
        $response->fullname = $user->name;
        $response->timezone = $user->getParam('timezone', $this->getApplication()->get('offset', 'UTC'));
        $response->language = $user->getParam('language', $this->getApplication()->get('language'));

        $event->stopPropagation();
    }

    private function requestToken(): ?array
    {
        $server = $this->getApplication()->getInput()->server;
        $header = $server->get('HTTP_AUTHORIZATION', '', 'string');

        if ($header === '' && \PHP_SAPI === 'apache2handler' && \function_exists('apache_request_headers')) {
            $headers = array_change_key_case((array) apache_request_headers(), \CASE_LOWER);
            $header = \is_string($headers['authorization'] ?? null) ? $headers['authorization'] : '';
        }

        if ($header === '') {
            $header = $server->get('REDIRECT_HTTP_AUTHORIZATION', '', 'string');
        }

        $token = str_starts_with($header, self::BEARER) ? trim(substr($header, \strlen(self::BEARER))) : '';

        if ($token === '') {
            $token = $server->get('HTTP_X_JOOMLA_TOKEN', '', 'string');
        }

        $decoded = base64_decode($token, true);

        if ($decoded === false || substr_count($decoded, ':') < 2) {
            return null;
        }

        [$algorithm, $userId, $hmac] = explode(':', $decoded, 3);

        if ($algorithm !== self::ALGORITHM || !ctype_digit($userId) || $hmac === '') {
            return null;
        }

        return [(int) $userId, $hmac];
    }

    private function isConnectionUser(int $userId): bool
    {
        $db = $this->getDatabase();

        try {
            $query = $db->createQuery()
                ->select('COUNT(*)')
                ->from($db->quoteName('#__aiconnector_connection'))
                ->where($db->quoteName('user_id') . ' = :id')
                ->bind(':id', $userId, ParameterType::INTEGER);

            return (int) $db->setQuery($query)->loadResult() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function tokenProfile(int $userId): array
    {
        $db = $this->getDatabase();
        $prefix = self::PROFILE_PREFIX . '%';
        $query = $db->createQuery()
            ->select([$db->quoteName('profile_key'), $db->quoteName('profile_value')])
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = :id')
            ->where($db->quoteName('profile_key') . ' LIKE :prefix')
            ->bind(':id', $userId, ParameterType::INTEGER)
            ->bind(':prefix', $prefix);
        $profile = [];

        foreach ($db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $profile[substr((string) $row['profile_key'], \strlen(self::PROFILE_PREFIX))] = (string) $row['profile_value'];
        }

        return $profile;
    }
}
