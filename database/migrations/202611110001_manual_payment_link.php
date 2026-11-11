<?php

/**
 * 固定金额手动支付链接库存、一次性订单绑定及 Aardio 查询任务。
 * 仅新增独立表，不修改现有支付订单及支付状态机。
 */
return new class {
    public string $version = '202611110001';
    public string $name = 'manual_payment_link';

    public function up(\PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ma_manual_payment_link` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `source_code` varchar(64) NOT NULL COMMENT '外部平台或收款账户范围',
    `external_order_no` varchar(128) NOT NULL COMMENT '订单编号',
    `online_order_no` varchar(128) NOT NULL COMMENT '线上订单号',
    `amount_cents` bigint unsigned NOT NULL COMMENT '固定金额（分）',
    `shop_id` varchar(128) NOT NULL COMMENT '店铺ID',
    `device_port` varchar(64) NOT NULL COMMENT '脚本查询端口',
    `payment_url` text NOT NULL COMMENT '外部支付链接，应用层应加密',
    `channel_id` bigint unsigned NOT NULL DEFAULT 0 COMMENT 'MPAY支付通道ID',
    `status` varchar(24) NOT NULL DEFAULT 'available' COMMENT 'available/reserved/paid/expired/exception/disabled',
    `expires_at` datetime DEFAULT NULL,
    `created_by` bigint unsigned NOT NULL DEFAULT 0,
    `created_at` datetime DEFAULT NULL,
    `updated_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_source_online_order` (`source_code`, `online_order_no`),
    UNIQUE KEY `uk_source_external_order` (`source_code`, `external_order_no`),
    KEY `idx_allocate` (`channel_id`, `amount_cents`, `status`, `expires_at`, `id`),
    KEY `idx_shop_port` (`shop_id`, `device_port`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='固定金额手动支付链接库存'
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ma_manual_link_binding` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `link_id` bigint unsigned NOT NULL,
    `pay_no` varchar(64) NOT NULL,
    `bound_at` datetime NOT NULL,
    `confirmed_at` datetime DEFAULT NULL,
    `channel_trade_no` varchar(128) DEFAULT NULL,
    `created_at` datetime DEFAULT NULL,
    `updated_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_link_id` (`link_id`),
    UNIQUE KEY `uk_pay_no` (`pay_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='手动链接与系统支付单一对一绑定'
SQL);

        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `ma_manual_payment_query_task` (
    `id` bigint unsigned NOT NULL AUTO_INCREMENT,
    `pay_no` varchar(64) NOT NULL,
    `link_id` bigint unsigned NOT NULL,
    `status` varchar(24) NOT NULL DEFAULT 'pending' COMMENT 'pending/running/paid/unpaid/unknown/error',
    `attempt` int unsigned NOT NULL DEFAULT 0,
    `device_id` varchar(64) NOT NULL DEFAULT '',
    `lease_token` varchar(128) NOT NULL DEFAULT '',
    `lease_until` datetime DEFAULT NULL,
    `next_retry_at` datetime DEFAULT NULL,
    `result_payload` json DEFAULT NULL,
    `last_error` varchar(255) NOT NULL DEFAULT '',
    `created_at` datetime DEFAULT NULL,
    `updated_at` datetime DEFAULT NULL,
    `finished_at` datetime DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_pay_no` (`pay_no`),
    KEY `idx_claim` (`status`, `next_retry_at`, `id`),
    KEY `idx_lease` (`status`, `lease_until`, `id`),
    KEY `idx_link_id` (`link_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Aardio支付查询任务'
SQL);
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS `ma_manual_payment_query_task`');
        $pdo->exec('DROP TABLE IF EXISTS `ma_manual_link_binding`');
        $pdo->exec('DROP TABLE IF EXISTS `ma_manual_payment_link`');
    }
};
