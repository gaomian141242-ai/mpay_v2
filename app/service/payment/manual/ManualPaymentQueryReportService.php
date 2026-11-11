<?php

namespace app\service\payment\manual;

use InvalidArgumentException;
use support\Db;

/**
 * 接收设备查询结果；只记录查询事实，不推进支付单状态。
 */
class ManualPaymentQueryReportService
{
    /** @return array<string, mixed> */
    public function report(string $deviceId, int $taskId, string $leaseToken, string $result, string $orderNo, string $devicePort): array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $deviceId)
            || $taskId < 1
            || !preg_match('/^[a-f0-9]{64}$/', $leaseToken)
            || !in_array($result, ['paid', 'unpaid', 'unknown'], true)
            || $orderNo === '' || strlen($orderNo) > 128
            || !preg_match('/^[0-9]{1,5}$/', $devicePort)
            || (int) $devicePort < 1 || (int) $devicePort > 65535) {
            throw new InvalidArgumentException('查询结果参数无效');
        }

        $devicePort = (string) (int) $devicePort;
        $mapping = json_decode((string) env('MANUAL_PAYMENT_DEVICE_PORTS', '{}'), true);
        $allowed = is_array($mapping) ? ($mapping[$deviceId] ?? []) : [];
        if (!is_array($allowed) || !in_array($devicePort, array_map('strval', $allowed), true)) {
            throw new InvalidArgumentException('设备未获授权使用该脚本端口');
        }
        foreach ($mapping as $otherDeviceId => $ports) {
            if ((string) $otherDeviceId !== $deviceId && is_array($ports)
                && in_array($devicePort, array_map('strval', $ports), true)) {
                throw new InvalidArgumentException('脚本端口配置冲突');
            }
        }

        return Db::transaction(function () use ($deviceId, $taskId, $leaseToken, $result, $orderNo, $devicePort): array {
            $task = Db::table('ma_manual_payment_query_task')->where('id', $taskId)->lockForUpdate()->first();
            if ($task === null || (string) $task->status !== 'running'
                || (string) $task->device_id !== $deviceId
                || !hash_equals((string) $task->lease_token, hash('sha256', $leaseToken))
                || $task->lease_until === null || (string) $task->lease_until < date('Y-m-d H:i:s')) {
                throw new InvalidArgumentException('任务租约无效或已过期');
            }

            $link = Db::table('ma_manual_payment_link')->where('id', (int) $task->link_id)->first();
            $binding = Db::table('ma_manual_link_binding')->where('pay_no', (string) $task->pay_no)
                ->where('link_id', (int) $task->link_id)->first();
            $order = Db::table('ma_pay_order')->where('pay_no', (string) $task->pay_no)
                ->where('plugin_code', 'manual_payment_link')->first();
            if ($link === null || $binding === null || $order === null
                || (string) $link->device_port !== $devicePort
                || (string) $link->online_order_no !== $orderNo
                || (int) $order->channel_id !== (int) $link->channel_id
                || (int) $order->pay_amount !== (int) $link->amount_cents
                || (string) $link->status !== 'reserved') {
                throw new InvalidArgumentException('任务订单绑定不匹配');
            }

            $now = date('Y-m-d H:i:s');
            // paid 仅表示设备声称查到付款；后续必须独立核验证据才能调用支付生命周期。
            $status = $result === 'paid' ? 'unknown' : $result;
            Db::table('ma_manual_payment_query_task')->where('id', $taskId)->update([
                'status' => $status,
                'result_payload' => json_encode([
                    'reported_result' => $result,
                    'online_order_no' => $orderNo,
                    'device_port' => $devicePort,
                ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'lease_token' => '',
                'lease_until' => null,
                'finished_at' => $now,
                'updated_at' => $now,
            ]);

            return ['task_id' => $taskId, 'task_status' => $status, 'payment_confirmed' => false];
        }, 3);
    }
}
