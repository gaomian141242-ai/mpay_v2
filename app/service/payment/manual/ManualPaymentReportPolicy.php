<?php

namespace app\service\payment\manual;

/**
 * 查询结果策略：未经独立核验的 paid 不能直接触发支付成功。
 */
final class ManualPaymentReportPolicy
{
    public static function outcome(string $reportedResult, int $attempt): array
    {
        if (!in_array($reportedResult, ['paid', 'unpaid', 'unknown'], true) || $attempt < 1) {
            throw new \InvalidArgumentException('查询结果或次数无效');
        }

        if ($reportedResult === 'paid') {
            return ['status' => 'unknown', 'retry_seconds' => null, 'reason' => '设备报告已付款，等待可信支付证据核验'];
        }

        if ($attempt >= 3) {
            return ['status' => $reportedResult === 'unpaid' ? 'unpaid' : 'error', 'retry_seconds' => null, 'reason' => '查询次数已达上限'];
        }

        return ['status' => 'pending', 'retry_seconds' => 30 * $attempt, 'reason' => '等待下一次查询'];
    }
}
