<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */

namespace App\Security;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Annotation\Inject;
use RuntimeException;

/**
 * 数据库敏感字段加解密：AES-256-GCM，密钥读配置 encryption.key（`.env` 的 APP_ENCRYPTION_KEY）。
 *
 * 密文格式：base64(12 字节 IV + 16 字节 tag + 密文)。密钥一旦用于生产就不能再换，否则已有密文解不开。
 */
class Encrypter
{
    private const CIPHER = 'aes-256-gcm';

    private const IV_LENGTH = 12;

    private const TAG_LENGTH = 16;

    #[Inject]
    protected ConfigInterface $config;

    public function encrypt(string $plain): string
    {
        $iv = random_bytes(self::IV_LENGTH);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LENGTH);
        if ($cipher === false) {
            throw new RuntimeException('加密失败');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    public function decrypt(string $payload): string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) <= self::IV_LENGTH + self::TAG_LENGTH) {
            throw new RuntimeException('密文格式不对');
        }
        $iv = substr($raw, 0, self::IV_LENGTH);
        $tag = substr($raw, self::IV_LENGTH, self::TAG_LENGTH);
        $plain = openssl_decrypt(substr($raw, self::IV_LENGTH + self::TAG_LENGTH), self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('解密失败，APP_ENCRYPTION_KEY 可能被换过');
        }

        return $plain;
    }

    private function key(): string
    {
        $hex = (string) $this->config->get('encryption.key', '');
        $key = preg_match('/^[0-9a-f]{64}$/i', $hex) === 1 ? hex2bin($hex) : false;
        if ($key === false) {
            throw new RuntimeException('APP_ENCRYPTION_KEY 没有配置，或不是 64 位十六进制');
        }

        return $key;
    }
}
