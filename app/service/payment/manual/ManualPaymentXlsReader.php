<?php

namespace app\service\payment\manual;

use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

/**
 * WPS Excel (.xlsx) 固定金额支付链接导入解析。
 * 只读取首张工作表，第一行为固定中文表头。
 */
class ManualPaymentXlsReader
{
    private const HEADERS = ['订单编号', '线上订单号', '金额', '店铺ID', '端口', '支付链接'];

    /** @return array<int, array<string, mixed>> */
    public function read(string $path, string $sourceCode, int $channelId): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('上传的 XLSX 文件不可读取');
        }
        if (!class_exists(IOFactory::class)) {
            throw new InvalidArgumentException('服务器未安装 PhpSpreadsheet 依赖');
        }

        $reader = IOFactory::createReader('Xlsx');
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        try {
            $sheet = $book->getSheet(0);
            $lastRow = $sheet->getHighestDataRow();
            if ($lastRow < 2 || $lastRow > 501) {
                throw new InvalidArgumentException('每份 XLSX 必须包含 1 至 500 条数据');
            }

            foreach (self::HEADERS as $index => $header) {
                $value = trim((string) $sheet->getCell(Coordinate::stringFromColumnIndex($index + 1).'1')->getValue());
                if ($value !== $header) {
                    throw new InvalidArgumentException('XLSX 第一行表头不正确，应为：'.implode('、', self::HEADERS));
                }
            }

            $items = [];
            for ($row = 2; $row <= $lastRow; $row++) {
                $values = [];
                foreach (range(1, 6) as $column) {
                    $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($column).$row);
                    if ($cell->isFormula()) {
                        throw new InvalidArgumentException("第 {$row} 行不能包含公式");
                    }
                    $values[] = trim((string) $cell->getValue());
                }
                if (count(array_filter($values, static fn ($v): bool => $v !== '')) === 0) {
                    continue;
                }

                [$externalNo, $onlineNo, $amount, $shopId, $port, $url] = $values;
                if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $amount)) {
                    throw new InvalidArgumentException("第 {$row} 行金额必须为正数，最多两位小数");
                }
                [$yuan, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
                if (strlen($yuan) > 14) {
                    throw new InvalidArgumentException("第 {$row} 行金额超出允许范围");
                }
                $cents = ((int) $yuan) * 100 + (int) str_pad($fraction, 2, '0');
                if ($cents <= 0) {
                    throw new InvalidArgumentException("第 {$row} 行金额必须大于零");
                }

                $items[] = [
                    'source_code' => $sourceCode,
                    'channel_id' => $channelId,
                    'external_order_no' => $externalNo,
                    'online_order_no' => $onlineNo,
                    'amount_cents' => $cents,
                    'shop_id' => $shopId,
                    'device_port' => $port,
                    'payment_url' => $url,
                ];
            }
            if ($items === []) {
                throw new InvalidArgumentException('XLSX 没有可导入的数据');
            }
            return $items;
        } finally {
            $book->disconnectWorksheets();
        }
    }
}
