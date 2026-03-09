<?php
require_once 'config.php';
require_once 'includes/database.php';

$conn = Database::getConnection();

$sql = "CREATE TABLE IF NOT EXISTS dragon_stocks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    stock_code VARCHAR(20) NOT NULL COMMENT '股票代码',
    stock_name VARCHAR(50) NOT NULL COMMENT '股票名称',
    market VARCHAR(10) DEFAULT NULL COMMENT '市场(sh/sz)',
    current_price DECIMAL(10,2) DEFAULT NULL COMMENT '当前价格',
    change_percent DECIMAL(10,2) DEFAULT NULL COMMENT '涨跌幅%',
    turnover_rate DECIMAL(10,2) DEFAULT NULL COMMENT '换手率%',
    volume BIGINT DEFAULT NULL COMMENT '成交量',
    amount DECIMAL(20,2) DEFAULT NULL COMMENT '成交额',
    is_limit_up TINYINT(1) DEFAULT 0 COMMENT '是否涨停',
    is_st TINYINT(1) DEFAULT 0 COMMENT '是否ST',
    is_kcb TINYINT(1) DEFAULT 0 COMMENT '是否科创板',
    limit_up_time TIME DEFAULT NULL COMMENT '涨停时间',
    first_limit_up_time TIME DEFAULT NULL COMMENT '首次涨停时间',
    open_count INT DEFAULT 0 COMMENT '开板次数',
    industry_sector VARCHAR(100) DEFAULT NULL COMMENT '所属行业板块',
    concept_sector TEXT DEFAULT NULL COMMENT '所属概念板块(JSON)',
    continuous_days INT DEFAULT 1 COMMENT '连板天数',
    rise_probability DECIMAL(5,2) DEFAULT NULL COMMENT '上涨概率评分',
    strategy_scores TEXT DEFAULT NULL COMMENT '各策略评分(JSON)',
    total_score DECIMAL(5,2) DEFAULT NULL COMMENT '综合评分',
    rank_order INT DEFAULT NULL COMMENT '排名',
    raw_data TEXT DEFAULT NULL COMMENT '原始数据(JSON)',
    data_date DATE DEFAULT NULL COMMENT '数据日期',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_stock_date (stock_code, data_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='龙头股推荐数据表'";

if ($conn->query($sql)) {
    echo "dragon_stocks 表创建成功！\n";
} else {
    echo "创建 dragon_stocks 表失败: " . $conn->error . "\n";
}

$sql2 = "CREATE TABLE IF NOT EXISTS dragon_data_cache (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cache_key VARCHAR(50) NOT NULL UNIQUE COMMENT '缓存键',
    cache_data LONGTEXT NOT NULL COMMENT '缓存数据(JSON)',
    last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最后更新时间',
    is_expired TINYINT(1) DEFAULT 0 COMMENT '是否过期'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='龙头股数据缓存表'";

if ($conn->query($sql2)) {
    echo "dragon_data_cache 表创建成功！\n";
} else {
    echo "创建 dragon_data_cache 表失败: " . $conn->error . "\n";
}

echo "数据库迁移完成！\n";
?>
