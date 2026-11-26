CREATE TABLE IF NOT EXISTS `#__aiconnector_connection` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `site_key` varchar(32) NOT NULL DEFAULT 'site',
    `site_url` varchar(2048) NOT NULL DEFAULT '',
    `gateway_url` varchar(2048) NOT NULL DEFAULT '',
    `gateway_connection_id` varchar(255) NOT NULL DEFAULT '',
    `connection_secret` varchar(1024) NOT NULL DEFAULT '',
    `client_name` varchar(255) NOT NULL DEFAULT '',
    `access_level` varchar(255) NOT NULL DEFAULT '',
    `permissions` text NOT NULL,
    `requested_permissions` text NOT NULL,
    `user_id` int unsigned NOT NULL DEFAULT 0,
    `group_id` int unsigned NOT NULL DEFAULT 0,
    `connected_by` int unsigned NOT NULL DEFAULT 0,
    `connected_at` datetime NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_site` (`site_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__aiconnector_audit` (
    `id` int unsigned NOT NULL AUTO_INCREMENT,
    `created` datetime NOT NULL,
    `action` varchar(32) NOT NULL DEFAULT '',
    `outcome` varchar(32) NOT NULL DEFAULT '',
    `reason` varchar(64) NOT NULL DEFAULT '',
    `user_id` int unsigned NOT NULL DEFAULT 0,
    `details` text NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
