<?php

namespace app\service\payment\manual;

use app\common\base\BaseService;
use InvalidArgumentException;
use support\Db;

/**
 * 固定金额外部支付订单库存。仅提供服务层导入与查询，暂不暴露公开接口。
 */
class ManualPaymentLinkInventoryService extends BaseService
{
    /**
     * 批量导入。整批原子提交；重复外部订单由数据库唯一索引拒绝。
     * 金额使用整数分，不接受浮点数。
     *
     * @param array<int, array<string, mixed>> $items
     */
    public function import(array $items, int $createdBy): int
    {
        if ($items === [] || count($items) > 500) {
            throw new InvalidArgumentException('每批必须包含 1 至 500 条支付链接');
        }
        if ($createdBy <= 0) {
            throw new InvalidArgumentException('缺少有效操作人');
        }

        $rows = [];
        $now = $this->now();
        foreach ($items as $item) {
            $row = [];
            foreach ([
                'source_code' => 64,
                'external_order_no' => 128,
                'online_order_no' => 128,
                'shop_id' => 128,
                'device_port' => 64,
            ] as $key => $maxLength) {
                $value = trim((string) ($item[$key] ?? ''));
                if ($value === '' || strlen($value) > $maxLength) {
                    throw new InvalidArgumentException("字段 {$key} 不能为空或超长");
                }
                $row[$key] = $value;
            }

            $amount = $item['amount_cents'] ?? null;
            if (!is_int($amount) || $amount <= 0) {
                throw new InvalidArgumentException('amount_cents 必须是正整数（分）');
            }
            $channelId = $item['channel_id'] ?? null;
            if (!is_int($channelId) || $channelId <= 0) {
                throw new InvalidArgumentException('channel_id 必须是有效正整数');
            }
            $url = trim((string) ($item['payment_url'] ?? ''));
            if (strlen($url) > 4096 || !filter_var($url, FILTER_VALIDATE_URL)
                || strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https'
                || parse_url($url, PHP_URL_USER) !== null
                || parse_url($url, PHP_URL_PASS) !== null) {
                throw new InvalidArgumentException('payment_url 必须是有效 HTTPS 地址');
            }

            $row['amount_cents'] = $amount;
            $row['channel_id'] = $channelId;
            $row['payment_url'] = $url;
            $row['status'] = 'available';
            $row['expires_at'] = $item['expires_at'] ?? null;
            if ($row['expires_at'] !== null) {
                $expiry = $row['expires_at'];
                if (!is_string($expiry) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $expiry)
                    || strtotime($expiry) === false || strtotime($expiry) <= time()) {
                    throw new InvalidArgumentException('expires_at 必须是未来的有效日期时间');
                }
            }
            $row['created_by'] = $createdBy;
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
            $rows[] = $row;
        }

        Db::transaction(static function () use ($rows): void {
            Db::table('ma_manual_payment_link')->insert($rows);
        });

        return count($rows);
    }

    /**
     * 库存列表仅返回脱敏字段；不在列表中暴露原始支付链接。
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAvailable(int $channelId, int $amountCents, int $limit = 50): array
    {
        if ($channelId <= 0 || $amountCents <= 0) {
            throw new InvalidArgumentException('通道和金额必须大于零');
        }
        $limit = max(1, min(100, $limit));

        return Db::table('ma_manual_payment_link')
            ->where('channel_id', $channelId)
            ->where('amount_cents', $amountCents)
            ->where('status', 'available')
            ->where(static function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', date('Y-m-d H:i:s'));
            })
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'source_code', 'external_order_no', 'online_order_no',
                'amount_cents', 'shop_id', 'device_port', 'channel_id', 'status', 'expires_at', 'created_at'])
            ->map(static fn ($item): array => (array) $item)
            ->all();
    }
}
