<?php

/**
 * 为已安装的 MPAY V2 注册固定金额手动链接插件。
 * 仅注册插件元信息，默认禁用；不自动创建/启用收款通道。
 */
return new class {
    public string $version = '202611110002';
    public string $name = 'register_manual_payment_link_plugin';

    public function up(\PDO $pdo): void
    {
        $statement = $pdo->prepare(
            'INSERT INTO `ma_payment_plugin` ' .
            '(`code`, `name`, `class_name`, `plugin_type`, `config_schema`, `pay_types`, `transfer_types`, ' .
            '`version`, `author`, `link`, `status`, `allow_merchant`, `remark`, `created_at`, `updated_at`) ' .
            'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?, NOW(), NOW()) ' .
            'ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `class_name` = VALUES(`class_name`), ' .
            '`plugin_type` = VALUES(`plugin_type`), `config_schema` = VALUES(`config_schema`), ' .
            '`pay_types` = VALUES(`pay_types`), `transfer_types` = VALUES(`transfer_types`), ' .
            '`updated_at` = NOW()'
        );
        $statement->execute([
            'manual_payment_link',
            '手动固定金额支付链接',
            'ManualPaymentLinkPayment',
            3,
            '[]',
            '["alipay","wxpay"]',
            '[]',
            '1.0.0',
            'MPAY Custom',
            '',
            '待完成收银台展示和 Aardio 付款确认后再启用',
        ]);
    }

    public function down(\PDO $pdo): void
    {
        // 不删除可能已经被渠道/订单引用的插件记录；回滚时禁用即可。
        $statement = $pdo->prepare('UPDATE `ma_payment_plugin` SET `status` = 0 WHERE `code` = ?');
        $statement->execute(['manual_payment_link']);
    }
};
