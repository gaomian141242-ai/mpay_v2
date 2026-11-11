<?php

namespace app\service\payment\manual;

use InvalidArgumentException;
use RuntimeException;
use support\Db;

/**
 * 固定金额支付链接的一次性绑定。
 *
 * 仅由服务端可信的支付插件/派发层调用，绝不直接开放给商户。
 * 绑定一旦成功，不提供释放或重新入库接口。
 */
class ManualPaymentLinkAllocationService
{
    /**
     * @return array<string, mixed> 绑定详情（含敏感支付链接，仅供内部使用）
     */
    public function allocate(string $payNo): array
    {
        $payNo = trim($payNo);
        if ($payNo === '' || strlen($payNo) > 64) {
            throw new InvalidArgumentException('无效的系统支付单号');
        }

        return Db::transaction(function () use ($payNo): array {
            // 先锁系统支付单，串行化同一 pay_no 的重复调用。
            // 锁顺序固定：支付单 -> 绑定 -> 库存，避免交叉死锁。
            $payOrder = Db::table('ma_pay_order')
                ->where('pay_no', $payNo)
                ->lockForUpdate()
                ->first();
            if ($payOrder === null) {
                throw new RuntimeException('系统支付单不存在');
            }

            $channelId = (int) $payOrder->channel_id;
            $amountCents = (int) $payOrder->pay_amount;
            if ($channelId <= 0 || $amountCents <= 0) {
                throw new RuntimeException('支付单通道或金额无效');
            }

            $existing = Db::table('ma_manual_link_binding')
                ->where('pay_no', $payNo)
                ->first();
            if ($existing !== null) {
                $link = Db::table('ma_manual_payment_link')
                    ->where('id', (int) $existing->link_id)
                    ->first();
                if ($link === null || (int) $link->channel_id !== $channelId
                    || (int) $link->amount_cents !== $amountCents
                    || !in_array((string) $link->status, ['reserved', 'paid', 'exception'], true)) {
                    throw new RuntimeException('支付单已有绑定，但库存状态不一致，需人工处理');
                }
                return $this->toResult($link, $payNo);
            }

            $now = date('Y-m-d H:i:s');
            // MySQL/InnoDB: SELECT ... FOR UPDATE SKIP LOCKED。
            // 被其他事务锁定的库存跳过，避免同一链接并发分配。
            $link = Db::table('ma_manual_payment_link')
                ->where('channel_id', $channelId)
                ->where('amount_cents', $amountCents)
                ->where('status', 'available')
                ->where(static function ($query) use ($now): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
                })
                ->orderBy('id')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();
            if ($link === null) {
                throw new RuntimeException('当前金额没有可分配的支付链接');
            }

            $changed = Db::table('ma_manual_payment_link')
                ->where('id', (int) $link->id)
                ->where('status', 'available')
                ->update(['status' => 'reserved', 'updated_at' => $now]);
            if ($changed !== 1) {
                throw new RuntimeException('支付链接库存已被占用，请重试');
            }

            // 唯一索引 uk_link_id + uk_pay_no 是最终并发防线。
            Db::table('ma_manual_link_binding')->insert([
                'link_id' => (int) $link->id,
                'pay_no' => $payNo,
                'bound_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $link->status = 'reserved';
            return $this->toResult($link, $payNo);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function toResult(object $link, string $payNo): array
    {
        return [
            'pay_no' => $payNo,
            'link_id' => (int) $link->id,
            'external_order_no' => (string) $link->external_order_no,
            'online_order_no' => (string) $link->online_order_no,
            'amount_cents' => (int) $link->amount_cents,
            'shop_id' => (string) $link->shop_id,
            'device_port' => (string) $link->device_port,
            'payment_url' => (string) $link->payment_url,
            'status' => (string) $link->status,
        ];
    }
}
