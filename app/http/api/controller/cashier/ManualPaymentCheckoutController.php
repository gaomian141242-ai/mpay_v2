<?php

namespace app\http\api\controller\cashier;

use app\common\base\BaseController;
use app\service\payment\manual\ManualPaymentCheckoutReadService;
use InvalidArgumentException;
use RuntimeException;
use support\Request;
use support\Response;

class ManualPaymentCheckoutController extends BaseController
{
    public function __construct(
        protected ManualPaymentCheckoutReadService $checkout
    ) {
    }

    public function detail(Request $request): Response
    {
        try {
            return $this->noStore($this->success($this->checkout->detail((string) $request->get('pay_no', ''))));
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->noStore($this->fail($e->getMessage(), 404));
        }
    }

    public function status(Request $request): Response
    {
        try {
            return $this->noStore($this->success($this->checkout->status((string) $request->get('pay_no', ''))));
        } catch (InvalidArgumentException|RuntimeException $e) {
            return $this->noStore($this->fail($e->getMessage(), 404));
        }
    }

    private function noStore(Response $response): Response
    {
        return $response->withHeaders([
            'Cache-Control' => 'no-store, private',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }
}
