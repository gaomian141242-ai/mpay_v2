<?php

namespace app\service\payment\manual;

use InvalidArgumentException;
use support\Db;

/**
 * Go 设备领取查询任务：MySQL 8/InnoDB SKIP LOCKED + 租约令牌。
 * 本阶段只发任务，不接受设备结果、不修改支付状态。
 */
class ManualPaymentQueryLeaseService
{
    /** @return array<string, mixed>|null */
    public function claim(string $deviceId, string $devicePort): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $deviceId)) {
            throw new InvalidArgumentException('设备标识无效');
        }

        if (!preg_match('/^[0-9]{1,5}$/', $devicePort) || (int) $devicePort < 1 || (int) $devicePort > 65535) {
            throw new InvalidArgumentException('脚本端口无效');
        }
        $devicePort = (string) (int) $devicePort;
        // 设备与本机端口显式绑定；缺少配置时拒绝领取，避免共享密钥跨设备抢任务。
        $mapping = json_decode((string) env('MANUAL_PAYMENT_DEVICE_PORTS', '{}'), true);
        $allowed = is_array($mapping) ? ($mapping[$deviceId] ?? []) : [];
        if (!is_array($allowed) || !in_array($devicePort, array_map('strval', $allowed), true)) {
            throw new InvalidArgumentException('设备未获授权使用该脚本端口');
        }

        return Db::transaction(function () use ($deviceId, $devicePort): ?array {
            $now = date('Y-m-d H:i:s');
            $task = Db::table('ma_manual_payment_query_task')
                ->join('ma_manual_payment_link as inventory', 'inventory.id', '=', 'ma_manual_payment_query_task.link_id')
                ->where('inventory.device_port', $devicePort)
                ->where(function ($q) use ($now) {
                    $q->where(function ($pending) use ($now) {
                        $pending->where('status', 'pending')
                            ->where(function ($retry) use ($now) {
                                $retry->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', $now);
                            });
                    })->orWhere(function ($running) use ($now) {
                        $running->where('status', 'running')->where('lease_until', '<=', $now);
                    });
                })
                ->orderBy('ma_manual_payment_query_task.id')
                ->lock('FOR UPDATE SKIP LOCKED')
                ->first();
            if ($task === null) {
                return null;
            }

            $order = Db::table('ma_pay_order')
                ->where('pay_no', (string) $task->pay_no)
                ->where('plugin_code', 'manual_payment_link')
                ->first();
            $binding = Db::table('ma_manual_link_binding')
                ->where('pay_no', (string) $task->pay_no)
                ->where('link_id', (int) $task->link_id)
                ->first();
            $link = Db::table('ma_manual_payment_link')->where('id', (int) $task->link_id)->first();
            if ($order === null || $binding === null || $link === null
                || (int) $order->channel_id !== (int) $link->channel_id
                || (int) $order->pay_amount !== (int) $link->amount_cents
                || !in_array((int) $order->status, [0, 1], true)
                || (string) $link->status !== 'reserved') {
                Db::table('ma_manual_payment_query_task')->where('id', (int) $task->id)->update([
                    'status' => 'error',
                    'last_error' => '订单或绑定状态不允许设备查询',
                    'finished_at' => $now,
                    'updated_at' => $now,
                    'lease_token' => '',
                    'lease_until' => null,
                ]);
                return null;
            }

            $token = bin2hex(random_bytes(32));
            $leaseUntil = date('Y-m-d H:i:s', time() + 120);
            $attempt = (int) $task->attempt + 1;
            Db::table('ma_manual_payment_query_task')->where('id', (int) $task->id)->update([
                'status' => 'running',
                'attempt' => $attempt,
                'device_id' => $deviceId,
                'lease_token' => hash('sha256', $token),
                'lease_until' => $leaseUntil,
                'updated_at' => $now,
            ]);

            return [
                'device_id' => $deviceId,
                'task_id' => (int) $task->id,
                'pay_no' => (string) $task->pay_no,
                'link_id' => (int) $task->link_id,
                'attempt' => $attempt,
                'lease_token' => $token,
                'lease_seconds' => 120,
                'shop_id' => (string) $link->shop_id,
                'device_port' => (string) $link->device_port,
                'external_order_no' => (string) $link->external_order_no,
                'online_order_no' => (string) $link->online_order_no,
                'amount_cents' => (int) $link->amount_cents,
            ];
        }, 3);
    }
}
