<?php

declare(strict_types=1);

namespace app\common\payment;

use app\common\base\BasePayment;
use app\common\constant\PaymentPluginStatusConstant;
use app\common\constant\PaymentPluginTypeConstant;
use app\exception\UnsupportedPaymentOperationException;
use app\service\payment\manual\ManualPaymentLinkAllocationService;
use support\Request;
use support\Response;

/**
 * 固定金额手动链接支付插件。
 *
 * pay() 只分配已上传的外部订单并返回收银台承接数据，绝不确认支付成功。
 * 用户点击「已支付」后必须由独立 Go/Aardio 查询任务验证真实付款。
 */
class ManualPaymentLinkPayment extends BasePayment
{
    protected array $paymentInfo = [
        'code' => 'manual_payment_link',
        'name' => '手动固定金额支付链接',
        'plugin_type' => PaymentPluginTypeConstant::TYPE_BACKEND,
        'author' => 'MPAY Custom',
        'version' => '1.0.0',
        'pay_types' => ['alipay', 'wxpay'],
        'transfer_types' => [],
        'config_schema' => [],
    ];

    public function __construct(
        private readonly ManualPaymentLinkAllocationService $allocation
    ) {
    }

    public function pay(array $order): array
    {
        $payNo = (string) ($order['pay_no'] ?? '');
        $bound = $this->allocation->allocate($payNo);

        // 双重校验：分配服务从 ma_pay_order 读取金额，插件再校验入参一致。
        if ((int) ($order['amount'] ?? 0) !== (int) $bound['amount_cents']) {
            throw new \RuntimeException('手动支付链接金额与系统支付单不一致');
        }

        return $this->pendingPaymentResult($order, [
            'pay_page' => 'page',
            'pay_type' => (string) ($order['pay_type_code'] ?? ''),
            'pay_product' => 'manual_payment_link',
            'pay_action' => 'link',
            'pay_params' => [
                '_page' => 'manualPaymentLink',
                'payment_url' => (string) $bound['payment_url'],
                'external_order_no' => (string) $bound['external_order_no'],
                'online_order_no' => (string) $bound['online_order_no'],
                'amount_cents' => (int) $bound['amount_cents'],
                'description' => '请使用当前链接完成付款，然后点击「已支付」查询实际收款结果。',
            ],
            'chan_order_no' => (string) $bound['online_order_no'],
            'chan_trade_no' => '',
            'channel_context' => [
                'manual_link_id' => (int) $bound['link_id'],
            ],
        ]);
    }

    public function query(array $order): array
    {
        // 不能把用户点击、插件查单或尚未接入的设备结果当作付款成功。
        return [
            'status' => PaymentPluginStatusConstant::PENDING,
            'pay_no' => (string) ($order['pay_no'] ?? ''),
            'paid_amount' => null,
            'chan_order_no' => (string) ($order['chan_order_no'] ?? ''),
            'chan_trade_no' => (string) ($order['chan_trade_no'] ?? ''),
            'message' => '等待 Aardio 查询真实付款结果',
        ];
    }

    public function close(array $order): array
    {
        // 外部支付链接可能继续有效，禁止将本地关单误认为外部订单已取消。
        throw new UnsupportedPaymentOperationException('手动支付链接不能自动关闭外部订单', 40200);
    }

    public function refund(array $order): array
    {
        throw new UnsupportedPaymentOperationException('手动支付链接不支持自动退款', 40200);
    }

    public function notify(Request $request): array
    {
        // 禁止使用未签名的公共渠道通知绕过 Aardio 查询确认。
        throw new UnsupportedPaymentOperationException('手动支付链接不接受公共渠道通知', 40200);
    }

    public function notifySuccess(): string|Response
    {
        return 'success';
    }

    public function notifyFail(): string|Response
    {
        return 'fail';
    }
}
