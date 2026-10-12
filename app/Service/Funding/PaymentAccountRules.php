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

namespace App\Service\Funding;

use App\Enum\PaymentAccountType;
use App\Model\MerchantFundingRequest;
use Hyperf\HttpMessage\Exception\HttpException;

/**
 * 收款账户、商户付款账号共用的校验和展示：只支持支付宝和对公账户。
 */
class PaymentAccountRules
{
    /**
     * 每次都校验全部字段（表单整体提交）。对公账号去掉空格后必须是 6~32 位数字，开户行必填；支付宝不要开户行。
     *
     * @param array<string, mixed> $data type / account_name / account_no / bank_name
     * @return array{type: string, account_name: string, account_no: string, bank_name: null|string}
     */
    public function validate(array $data): array
    {
        $type = PaymentAccountType::tryFrom(is_string($data['type'] ?? null) ? $data['type'] : '');
        if ($type === null) {
            throw new HttpException(422, '账户类型只能是支付宝或对公账户');
        }
        $name = $this->text($data['account_name'] ?? null);
        if ($name === '' || mb_strlen($name) > 64) {
            throw new HttpException(422, $type === PaymentAccountType::Bank ? '户名为 1~64 个字' : '支付宝实名为 1~64 个字');
        }
        $no = preg_replace('/\s+/u', '', $this->text($data['account_no'] ?? null));
        if ($type === PaymentAccountType::Bank && preg_match('/^\d{6,32}$/', $no) !== 1) {
            throw new HttpException(422, '对公账号必须是 6~32 位数字');
        }
        if ($type === PaymentAccountType::Alipay && ($no === '' || mb_strlen($no) > 64)) {
            throw new HttpException(422, '支付宝账号为 1~64 个字符（手机号或邮箱）');
        }
        $bank = $this->text($data['bank_name'] ?? null);
        if ($type === PaymentAccountType::Bank && ($bank === '' || mb_strlen($bank) > 64)) {
            throw new HttpException(422, '开户行为 1~64 个字，写到支行');
        }

        return [
            'type' => $type->value,
            'account_name' => $name,
            'account_no' => $no,
            'bank_name' => $type === PaymentAccountType::Bank ? $bank : null,
        ];
    }

    /**
     * 加款申请里付款方、收款方的快照，给前端展示。
     *
     * @return array{payer: array<string, mixed>, receiving: array<string, mixed>}
     */
    public function snapshots(MerchantFundingRequest $r): array
    {
        return [
            'payer' => self::present($r->payer_type, $r->payer_account_name, $r->payer_account_no, $r->payer_bank_name) + ['id' => $r->payer_account_id],
            'receiving' => self::present($r->receiving_type, $r->receiving_account_name, $r->receiving_account_no, $r->receiving_bank_name) + ['id' => $r->receiving_account_id],
        ];
    }

    /**
     * @return array{type: string, type_label: string, account_name: string, account_no: string, bank_name: null|string}
     */
    public static function present(string $type, string $name, string $no, ?string $bank): array
    {
        return [
            'type' => $type,
            'type_label' => PaymentAccountType::tryFrom($type)?->label() ?? $type,
            'account_name' => $name,
            'account_no' => $no,
            'bank_name' => $bank,
        ];
    }

    private function text(mixed $value): string
    {
        return trim(is_string($value) || is_int($value) ? (string) $value : '');
    }
}
