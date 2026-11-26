<?php

/**
 * @package     solution25 AI Connector
 * @copyright   (C) 2026 solution25
 * @license     GNU General Public License version 2 or later
 */

declare(strict_types=1);

namespace Solution25\Component\AiConnector\Administrator\Connection;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

\defined('_JEXEC') or die;

class ConnectionStore
{
    public const TABLE = '#__aiconnector_connection';

    private const SITE_KEY = 'site';

    private const LIST_COLUMNS = ['permissions', 'requested_permissions'];

    private const SECRET_COLUMN = 'connection_secret';

    private const SEALED_PREFIX = 'v1:';

    private const KEY_CONTEXT = 'aiconnector.connection_secret';

    private const KEY_BYTES = 32;

    private const KEY_ALGORITHM = 'sha256';

    public function __construct(
        private readonly DatabaseInterface $db,
    ) {
    }

    public function database(): DatabaseInterface
    {
        return $this->db;
    }

    public function find(): ?array
    {
        $siteKey = self::SITE_KEY;
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('site_key') . ' = :siteKey')
            ->bind(':siteKey', $siteKey);
        $row = $this->db->setQuery($query)->loadAssoc();

        if (!\is_array($row)) {
            return null;
        }

        foreach (self::LIST_COLUMNS as $column) {
            $decoded = json_decode((string) $row[$column], true);
            $row[$column] = \is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }

        unset($row[self::SECRET_COLUMN]);

        return $row;
    }

    public function secret(int $id): string
    {
        $query = $this->db->createQuery()
            ->select($this->db->quoteName(self::SECRET_COLUMN))
            ->from($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        return $this->open((string) $this->db->setQuery($query)->loadResult());
    }

    public function insert(array $values): void
    {
        $row = (object) $this->encoded($values + ['site_key' => self::SITE_KEY]);
        $this->db->insertObject(self::TABLE, $row);
    }

    public function update(int $id, array $values): void
    {
        $row = (object) ($this->encoded($values) + ['id' => $id]);
        $this->db->updateObject(self::TABLE, $row, 'id');
    }

    public function delete(int $id): void
    {
        $query = $this->db->createQuery()
            ->delete($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);
        $this->db->setQuery($query)->execute();
    }

    private function encoded(array $values): array
    {
        if (\array_key_exists(self::SECRET_COLUMN, $values)) {
            $values[self::SECRET_COLUMN] = $this->seal((string) $values[self::SECRET_COLUMN]);
        }

        foreach (self::LIST_COLUMNS as $column) {
            if (\array_key_exists($column, $values)) {
                $values[$column] = json_encode(array_values($values[$column]), \JSON_THROW_ON_ERROR);
            }
        }

        return $values;
    }

    private function seal(string $plain): string
    {
        $nonce = random_bytes(\SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        return self::SEALED_PREFIX . base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plain, self::KEY_CONTEXT, $nonce, $this->key()));
    }

    private function open(string $sealed): string
    {
        if (!str_starts_with($sealed, self::SEALED_PREFIX)) {
            return '';
        }

        $raw = base64_decode(substr($sealed, \strlen(self::SEALED_PREFIX)), true);

        if (!\is_string($raw) || \strlen($raw) <= \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            return '';
        }

        try {
            $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                substr($raw, \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),
                self::KEY_CONTEXT,
                substr($raw, 0, \SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES),
                $this->key()
            );
        } catch (\RuntimeException | \SodiumException) {
            return '';
        }

        return \is_string($plain) ? $plain : '';
    }

    private function key(): string
    {
        $secret = (string) Factory::getApplication()->get('secret');

        if ($secret === '') {
            throw new \RuntimeException('The site has no secret, so the connection secret cannot be sealed.');
        }

        return hash_hkdf(self::KEY_ALGORITHM, $secret, self::KEY_BYTES, self::KEY_CONTEXT);
    }
}
