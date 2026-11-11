<?php

namespace app\service\payment\manual;

use InvalidArgumentException;
use RuntimeException;
use support\Db;

/**
 * 用户点击「支付完成」只创建一次查询任务，不确认订单成功。
 */
class ManualPaymentQueryRequestService
{
    /** @return array{pay_no:string,task_id:int,task_status:string,order_status:int} */
    public function request(string $payNo): array
    {
        $payNo = trim($payNo);
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $payNo)) {
            throw new InvalidArgumentException('支付单号无效');
        }

        return Db::transaction(function () use ($payNo): array {
            // 锁定支付单，确保同一 pay_no 并发请求串行处理。
            $order = Db::table('ma_pay_order')
                ->where('pay_no', $payNo)
                ->where('plugin_code', 'manual_payment_link')
                ->lockForUpdate()
                ->first();
            if ($order === null) {
                throw new RuntimeException('手动支付订单不存在');
            }

            $status = (int) $order->status;
            $existing = Db::table('ma_manual_payment_query_task')->where('pay_no', $payNo)->first();
            if ($existing !== null) {
                return $this->result($payNo, $existing, $status);
            }

            if (!in_array($status, [0, 1], true)) {
                throw new RuntimeException('订单已结束，无法新建支付查询任务');
            }

            $binding = Db::table('ma_manual_link_binding')->where('pay_no', $payNo)->first();
            if ($binding === null) {
                throw new RuntimeException('支付订单尚未绑定链接');
            }
            $link = Db::table('ma_manual_payment_link')
                ->where('id', (int) $binding->link_id)
                ->where('channel_id', (int) $order->channel_id)
                ->where('amount_cents', (int) $order->pay_amount)
                ->first();
            if ($link === null || (string) $link->status !== 'reserved') {
                throw new RuntimeException('支付链接状态异常，不能发起查询');
            }

            $now = date('Y-m-d H:i:s');
            $id = Db::table('ma_manual_payment_query_task')->insertGetId([
                'pay_no' => $payNo,
                'link_id' => (int) $link->id,
                'status' => 'pending',
                'attempt' => 0,
                'device_id' => '',
                'lease_token' => '',
                'next_retry_at' => $now,
                'last_error' => '',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return [
                'pay_no' => $payNo,
                'task_id' => (int) $id,
                'task_status' => 'pending',
                'order_status' => $status,
            ];
        }, 3);
    }

    /** @return array{pay_no:string,task_id:int,task_status:string,order_status:int} */
    private function result(string $payNo, object $task, int $orderStatus): array
    {
        return [
            'pay_no' => $payNo,
            'task_id' => (int) $task->id,
            'task_status' => (string) $task->status,
            'order_status' => $orderStatus,
        ];
    }
}
