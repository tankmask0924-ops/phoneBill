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

namespace App\Service\Merchant;

use App\Network\IpAddress;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 商户接入凭证的公共规则：IP 白名单的校验和格式、签名密钥的生成。平台后台和商户后台共用。
 */
class MerchantAccessService
{
    public function newSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * 支持数组或逗号 / 换行分隔的字符串，每项是 IP 或 CIDR；返回逗号拼接的结果，空表示不限制。
     */
    public function ipWhitelist(mixed $value): ?string
    {
        $items = is_array($value) ? $value : preg_split('/[\s,，]+/u', is_string($value) ? $value : '');
        $result = [];
        foreach ($items as $item) {
            $item = trim(is_string($item) ? $item : '');
            if ($item === '') {
                continue;
            }
            if (! $this->isValidIpOrCidr($item)) {
                throw new HttpException(422, "IP 白名单里的「{$item}」不是合法的 IP 或网段");
            }
            $result[] = $item;
        }
        $joined = implode(',', array_values(array_unique($result)));
        if (mb_strlen($joined) > 1000) {
            throw new HttpException(422, 'IP 白名单太长，请合并成网段');
        }

        return $joined === '' ? null : $joined;
    }

    private function isValidIpOrCidr(string $item): bool
    {
        if (! str_contains($item, '/')) {
            return IpAddress::toBinary($item) !== null;
        }
        [$network, $prefix] = explode('/', $item, 2);
        $binary = IpAddress::toBinary($network);

        return $binary !== null && ctype_digit($prefix) && (int) $prefix <= strlen($binary) * 8;
    }
}
