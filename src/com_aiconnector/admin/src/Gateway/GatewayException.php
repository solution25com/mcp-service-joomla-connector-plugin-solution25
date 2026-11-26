<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Administrator\Gateway;

\defined('_JEXEC') or die;

final class GatewayException extends \RuntimeException
{
    public const REASON_INVALID_CODE = 'invalid_code';
    public const REASON_INVALID_GATEWAY_URL = 'invalid_gateway_url';
    public const REASON_UNREACHABLE = 'gateway_unreachable';
    public const REASON_INVALID_PERMISSIONS = 'invalid_permissions';
    public const REASON_NOT_YOUR_RIGHTS = 'not_your_rights';
    public const REASON_REQUEST_CHANGED = 'request_changed';
    public const REASON_OTHER_PLATFORM = 'other_platform';
    public const REASON_BUSY = 'connection_busy';
    public const REASON_ALREADY_CONNECTED = 'already_connected';
    public const REASON_NOT_CONNECTED = 'not_connected';
    public const REASON_INVALID_SITE_URL = 'invalid_site_url';
    public const REASON_GATEWAY_ERROR = 'gateway_error';
    public const REASON_PERMISSIONS_NOT_SYNCED = 'permissions_not_synced';
    public const REASON_LOCAL_FAILURE = 'local_failure';
    public const REASON_STALE_FORM = 'stale_form';
    public const REASON_SHOP_NOT_AVAILABLE = 'shop_not_available';
    public const REASON_SECRET_UNAVAILABLE = 'secret_unavailable';

    private function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status = 0,
    ) {
        parent::__construct($message);
    }

    public static function invalidCode(): self
    {
        return new self(self::REASON_INVALID_CODE, 'A connection code looks like S25-XXXX-XXXX. Copy it again from the sign-in page opened by your AI client.');
    }

    public static function invalidGatewayUrl(): self
    {
        return new self(self::REASON_INVALID_GATEWAY_URL, 'This connection uses an unsupported connector server. Disconnect it and connect again.');
    }

    public static function unreachable(): self
    {
        return new self(self::REASON_UNREACHABLE, 'The solution25 connector server could not be reached. Try again later.');
    }

    public static function invalidPermissions(): self
    {
        return new self(self::REASON_INVALID_PERMISSIONS, 'Choose at least one thing the AI client may do on this site, out of what it asked for. Edit and Create need View of the same area.');
    }

    public static function notYourRights(string $permission): self
    {
        return new self(self::REASON_NOT_YOUR_RIGHTS, \sprintf('You cannot grant "%s", because you do not have the matching rights on this site yourself.', $permission));
    }

    public static function otherPlatformCode(string $mcpUrl): self
    {
        return new self(self::REASON_OTHER_PLATFORM, \sprintf('This code asks for no permissions this site can grant. In your AI client add the connector %s, sign in there and paste the new code.', $mcpUrl));
    }

    public static function shopNotAvailable(): self
    {
        return new self(self::REASON_SHOP_NOT_AVAILABLE, 'This code asks only for shop access (products, orders or customers). The AI connector cannot grant that yet: it can give access to articles, categories and menus.');
    }

    public static function secretUnavailable(): self
    {
        return new self(self::REASON_SECRET_UNAVAILABLE, 'The connection secret stored on this site could not be read, so the connector server could not be told.');
    }

    public static function requestChanged(): self
    {
        return new self(self::REASON_REQUEST_CHANGED, 'The AI client changed what it asks for. Check the code again and review the permissions.');
    }

    public static function busy(): self
    {
        return new self(self::REASON_BUSY, 'Another change to the AI connection is in progress. Wait a moment and try again.');
    }

    public static function alreadyConnected(): self
    {
        return new self(self::REASON_ALREADY_CONNECTED, 'This site is already connected. Disconnect it before connecting another AI client.');
    }

    public static function notConnected(): self
    {
        return new self(self::REASON_NOT_CONNECTED, 'This site has no AI connection. Reload the page.');
    }

    public static function staleForm(): self
    {
        return new self(self::REASON_STALE_FORM, 'The AI connection changed after this page was opened. Nothing was changed. Reload the page and try again.');
    }

    public static function invalidSiteUrl(): self
    {
        return new self(self::REASON_INVALID_SITE_URL, 'The site has to be reachable over https:// on its own public domain. Open the administrator through the site\'s public https address and try again.');
    }

    public static function siteAddressMissing(): self
    {
        return new self(self::REASON_INVALID_SITE_URL, 'Enter this site\'s public https address, for example https://www.example.com.');
    }

    public static function permissionsNotSynced(): self
    {
        return new self(self::REASON_PERMISSIONS_NOT_SYNCED, 'The new permissions apply on this site, but the connector server could not be told. Save them again later so the AI client shows the right tools.');
    }

    public static function localFailure(): self
    {
        return new self(self::REASON_LOCAL_FAILURE, 'The connection could not be saved on this site. Nothing was changed. Try again, and check the Joomla log if it keeps failing.');
    }

    public static function disconnectFailure(): self
    {
        return new self(self::REASON_LOCAL_FAILURE, 'The AI client could not be fully disconnected on this site. Its access may already be blocked, but the connection is still listed here. Disconnect again, and check the Joomla log if it keeps failing.');
    }

    public static function rejected(string $reason, string $message, int $status): self
    {
        return new self($reason, $message, $status);
    }
}
