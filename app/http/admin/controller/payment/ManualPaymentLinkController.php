<?php

namespace app\http\admin\controller\payment;

use app\common\base\BaseController;
use app\service\payment\manual\ManualPaymentLinkInventoryService;
use app\service\payment\manual\ManualPaymentXlsReader;
use Webman\Http\UploadFile;
use InvalidArgumentException;
use support\Request;
use support\Response;

/**
 * 固定金额手动支付链接后台导入与库存查询。
 * 仅供 AdminAuthMiddleware 保护的 adminapi 路由使用。
 */
class ManualPaymentLinkController extends BaseController
{
    public function __construct(
        protected ManualPaymentLinkInventoryService $inventory,
        protected ManualPaymentXlsReader $xlsReader
    ) {
    }

    public function import(Request $request): Response
    {
        $adminId = $this->currentAdminId($request);
        if ($adminId <= 0) {
            return $this->fail('管理员未授权', 401);
        }

        $items = $request->input('items');
        if (!is_array($items) || !array_is_list($items)) {
            return $this->fail('items 必须是支付链接数组', 422);
        }

        // 上传功能必须显式配置外部支付平台域名，避免收银台指向任意网址。
        $hosts = array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('MANUAL_PAYMENT_ALLOWED_HOSTS', ''))
        )));
        if ($hosts === []) {
            return $this->fail('尚未配置允许的支付链接域名', 503);
        }

        foreach ($items as $item) {
            if (!is_array($item)) {
                return $this->fail('每条支付链接必须是对象', 422);
            }
            $url = (string) ($item['payment_url'] ?? '');
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            if ($host === '' || !in_array($host, $hosts, true)) {
                return $this->fail('支付链接域名不在允许列表中', 422);
            }
        }

        try {
            $count = $this->inventory->import($items, $adminId);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        return $this->success(['imported' => $count]);
    }

    /**
     * multipart/form-data: file(.xlsx), source_code, channel_id。
     */
    public function importXls(Request $request): Response
    {
        $adminId = $this->currentAdminId($request);
        if ($adminId <= 0) {
            return $this->fail('管理员未授权', 401);
        }
        $file = $request->file('file');
        if (!$file instanceof UploadFile || strtolower((string) $file->getUploadExtension()) !== 'xlsx') {
            return $this->fail('只允许上传 Excel 工作簿（.xlsx）', 422);
        }
        $path = $file->getPathname();
        if (!is_file($path) || filesize($path) > 2 * 1024 * 1024) {
            return $this->fail('XLSX 文件不能超过 2MB', 422);
        }
        $channelId = filter_var($request->input('channel_id'), FILTER_VALIDATE_INT);
        $sourceCode = trim((string) $request->input('source_code', ''));
        if ($channelId === false || $channelId === null || $channelId <= 0
            || $sourceCode === '' || strlen($sourceCode) > 64) {
            return $this->fail('请填写有效的通道 ID 和外部来源', 422);
        }
        try {
            $items = $this->xlsReader->read($path, $sourceCode, $channelId);
        } catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
            return $this->fail('XLSX 文件格式无效或无法解析', 422);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }

        // 复用原导入接口的域名白名单与库存事务逻辑。
        $hosts = array_values(array_filter(array_map(
            static fn (string $host): string => strtolower(trim($host)),
            explode(',', (string) env('MANUAL_PAYMENT_ALLOWED_HOSTS', ''))
        )));
        if ($hosts === []) {
            return $this->fail('尚未配置允许的支付链接域名', 503);
        }
        foreach ($items as $item) {
            $host = strtolower((string) parse_url($item['payment_url'], PHP_URL_HOST));
            if ($host === '' || !in_array($host, $hosts, true)) {
                return $this->fail('支付链接域名不在允许列表中', 422);
            }
        }
        try {
            return $this->success(['imported' => $this->inventory->import($items, $adminId)]);
        } catch (InvalidArgumentException $e) {
            return $this->fail($e->getMessage(), 422);
        }
    }

    public function index(Request $request): Response
    {
        if ($this->currentAdminId($request) <= 0) {
            return $this->fail('管理员未授权', 401);
        }

        $channelId = filter_var($request->input('channel_id'), FILTER_VALIDATE_INT);
        $amountCents = filter_var($request->input('amount_cents'), FILTER_VALIDATE_INT);
        if ($channelId === false || $channelId === null || $channelId <= 0
            || $amountCents === false || $amountCents === null || $amountCents <= 0) {
            return $this->fail('channel_id 和 amount_cents 必须为正整数', 422);
        }

        return $this->success($this->inventory->listAvailable(
            $channelId,
            $amountCents,
            max(1, min(100, (int) $request->input('limit', 50)))
        ));
    }
}
