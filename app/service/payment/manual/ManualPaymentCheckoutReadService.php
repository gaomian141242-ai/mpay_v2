<?php

namespace app\service\payment\manual;

use InvalidArgumentException;
use RuntimeException;
use support\Db;

/**
 * 独立收银台只读订单快照，不修改 MPAY 原订单状态。
 */
class ManualPaymentCheckoutReadService
{
    /** @return array<string, mixed> */
    public function detail(string $payNo): array
    {
        $payNo = trim($payNo);
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $payNo)) {
            throw new InvalidArgumentException('支付单号无效');
        }

        $order = Db::table('ma_pay_order')
            ->where('pay_no', $payNo)
            ->where('plugin_code', 'manual_payment_link')
            ->first();
        if ($order === null) {
            throw new RuntimeException('手动支付订单不存在');
        }

        $binding = Db::table('ma_manual_link_binding')->where('pay_no', $payNo)->first();
        if ($binding === null) {
            throw new RuntimeException('订单尚未分配支付链接');
        }

        $link = Db::table('ma_manual_payment_link')
            ->where('id', (int) $binding->link_id)
            ->where('channel_id', (int) $order->channel_id)
            ->where('amount_cents', (int) $order->pay_amount)
            ->first();
        if ($link === null) {
            throw new RuntimeException('订单与支付链接不匹配');
        }

        $status = (int) $order->status;
        return [
            'pay_no' => $payNo,
            'amount_cents' => (int) $order->pay_amount,
            'status' => $status,
            'payment_url' => in_array($status, [0, 1], true) ? (string) $link->payment_url : '',
        ];
    }

    /** @return array{pay_no:string,status:int} */
    public function status(string $payNo): array
    {
        $payNo = trim($payNo);
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $payNo)) {
            throw new InvalidArgumentException('支付单号无效');
        }
        $order = Db::table('ma_pay_order')
            ->where('pay_no', $payNo)
            ->where('plugin_code', 'manual_payment_link')
            ->first(['pay_no', 'status']);
        if ($order === null) {
            throw new RuntimeException('手动支付订单不存在');
        }
        return ['pay_no' => $payNo, 'status' => (int) $order->status];
    }
}
