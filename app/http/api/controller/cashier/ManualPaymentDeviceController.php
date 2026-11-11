<?php

namespace app\http\api\controller\cashier;

use app\common\base\BaseController;
use app\service\payment\manual\ManualPaymentQueryLeaseService;
use InvalidArgumentException;
use support\Request;
use support\Response;

/**
 * Go 查询代理领取接口。独立于公开收银台 API。
 * 仅在 HTTPS 反向代理后使用，并配置独立的高强度设备密钥。
 */
class ManualPaymentDeviceController extends BaseController
{
    public function __construct(
        protected ManualPaymentQueryLeaseService $leases
    ) {
    }

    public function claim(Request $request): Response
    {
        $expected = (string) env('MANUAL_PAYMENT_DEVICE_API_KEY', '');
        $supplied = (string) $request->header('X-Manual-Device-Key', '');
        if (strlen($expected) < 32 || $supplied === '' || !hash_equals($expected, $supplied)) {
            return $this->fail('设备认证失败', 401);
        }
        try {
            $task = $this->leases->claim((string) $request->post('device_id', ''));
            return $this->success(['task' => $task]);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }
}
