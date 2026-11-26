<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Administrator\Connection;

\defined('_JEXEC') or die;

final class PermissionMap
{
    public const ROOT_ASSET = 'root.1';

    public const API_LOGIN = 'core.login.api';

    private const CONTENT = 'com_content';

    private const MENUS = 'com_menus';

    private const HIKASHOP = 'com_hikashop';

    private const MANAGE = 'core.manage';

    private const EDIT = 'core.edit';

    private const EDIT_STATE = 'core.edit.state';

    private const CREATE = 'core.create';

    public const ALLOWED_RIGHTS = [
        [self::ROOT_ASSET, self::API_LOGIN],
        [self::CONTENT, self::MANAGE],
        [self::CONTENT, self::EDIT],
        [self::CONTENT, self::EDIT_STATE],
        [self::CONTENT, self::CREATE],
        [self::MENUS, self::MANAGE],
        [self::HIKASHOP, self::MANAGE],
        [self::HIKASHOP, self::EDIT],
    ];

    private const RIGHTS = [
        'content.viewer' => [[self::CONTENT, self::MANAGE]],
        'content.editor' => [[self::CONTENT, self::MANAGE], [self::CONTENT, self::EDIT], [self::CONTENT, self::EDIT_STATE]],
        'content.creator' => [[self::CONTENT, self::MANAGE], [self::CONTENT, self::CREATE]],
        'menu.viewer' => [[self::MENUS, self::MANAGE]],
        'product.viewer' => [[self::HIKASHOP, self::MANAGE]],
        'product.editor' => [[self::HIKASHOP, self::MANAGE], [self::HIKASHOP, self::EDIT]],
        'order.viewer' => [[self::HIKASHOP, self::MANAGE]],
        'order.editor' => [[self::HIKASHOP, self::MANAGE], [self::HIKASHOP, self::EDIT]],
        'customer.viewer' => [[self::HIKASHOP, self::MANAGE]],
    ];

    private const LABELS = [
        'content.viewer' => 'Content: View articles and categories',
        'content.editor' => 'Content: Edit articles',
        'content.creator' => 'Content: Create articles',
        'menu.viewer' => 'Menus: View site menus',
        'product.viewer' => 'Products: View',
        'product.editor' => 'Products: Edit price, stock and published',
        'order.viewer' => 'Orders: View orders and sales figures',
        'order.editor' => 'Orders: Change order status',
        'customer.viewer' => 'Customers: View',
    ];

    private const NEEDS = [
        'content.editor' => 'content.viewer',
        'content.creator' => 'content.viewer',
        'product.editor' => 'product.viewer',
        'order.editor' => 'order.viewer',
    ];

    private const HIKASHOP_PREFIXES = ['product.', 'order.', 'customer.'];

    public function isKnown(string $permission): bool
    {
        return isset(self::RIGHTS[$permission]) && !self::isShop($permission);
    }

    public static function isShop(string $permission): bool
    {
        foreach (self::HIKASHOP_PREFIXES as $prefix) {
            if (str_starts_with($permission, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function label(string $permission): string
    {
        return self::LABELS[$permission] ?? $permission;
    }

    public static function missingDependency(array $permissions): ?string
    {
        foreach ($permissions as $permission) {
            $needed = self::NEEDS[$permission] ?? null;

            if ($needed !== null && !\in_array($needed, $permissions, true)) {
                return $permission;
            }
        }

        return null;
    }

    public function rights(array $permissions): array
    {
        $rights = [];

        foreach ($permissions as $permission) {
            foreach (self::RIGHTS[$permission] ?? [] as $right) {
                $rights[implode('|', $right)] = $right;
            }
        }

        return array_values($rights);
    }

    public static function isAllowedRight(array $right): bool
    {
        return \in_array($right, self::ALLOWED_RIGHTS, true);
    }
}
