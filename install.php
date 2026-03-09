<?php
// 检查是否已经安装
$configFile = __DIR__ . '/config.php';
$isInstalled = file_exists($configFile);

if ($isInstalled) {
    require_once $configFile;
    if (defined('SYSTEM_INSTALLED') && SYSTEM_INSTALLED) {
        header('Location: index.html');
        exit;
    }
}

// 处理安装请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 获取表单数据
    $dbHost = $_POST['db_host'] ?? 'localhost';
    $dbPort = $_POST['db_port'] ?? '3306';
    $dbUser = $_POST['db_user'] ?? '';
    $dbPass = $_POST['db_pass'] ?? '';
    $dbName = $_POST['db_name'] ?? 'ai_stock_analysis';

    $deepseekApiKey = $_POST['deepseek_api_key'] ?? '';
    $defaultModel = $_POST['default_model'] ?? 'deepseek-chat';

    $adminUsername = $_POST['admin_username'] ?? 'admin';
    $adminPassword = $_POST['admin_password'] ?? '';

    $newUserPoints = $_POST['new_user_points'] ?? 20;
    $analysisCost = $_POST['analysis_cost'] ?? 1;

    // 验证必要数据
    if (empty($dbUser) || empty($dbName) || empty($deepseekApiKey) || empty($adminPassword)) {
        $error = '请填写所有必要的字段';
    } else {
        try {
            // 连接数据库服务器
            $conn = new mysqli($dbHost, $dbUser, $dbPass, '', $dbPort);
            
            if ($conn->connect_error) {
                throw new Exception('连接数据库服务器失败: ' . $conn->connect_error);
            }
            
            // 创建数据库
            $sql = "CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
            if (!$conn->query($sql)) {
                throw new Exception('创建数据库失败: ' . $conn->error);
            }
            
            // 选择数据库
            $conn->select_db($dbName);
            
            // 创建用户表
            $sql = "CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                nickname VARCHAR(100) DEFAULT NULL,
                points INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL
            )";
            if (!$conn->query($sql)) {
                throw new Exception('创建用户表失败: ' . $conn->error);
            }
            
            // 创建后台管理员表
            $sql = "CREATE TABLE IF NOT EXISTS admin_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT NULL
            )";
            if (!$conn->query($sql)) {
                throw new Exception('创建后台管理员表失败: ' . $conn->error);
            }
            
            // 创建用户积分历史表
            $sql = "CREATE TABLE IF NOT EXISTS user_points_history (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                points INT NOT NULL,
                type ENUM('add', 'deduct') NOT NULL,
                reason VARCHAR(100) DEFAULT NULL,
                admin_id INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )";
            if (!$conn->query($sql)) {
                throw new Exception('创建用户积分历史表失败: ' . $conn->error);
            }
            
            // 创建股票分析记录表
            $sql = "CREATE TABLE IF NOT EXISTS stock_analyses (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                symbol VARCHAR(50) NOT NULL,
                shares INT DEFAULT 0,
                sellable_shares INT DEFAULT 0,
                cost DECIMAL(10,2) DEFAULT 0,
                cash DECIMAL(10,2) DEFAULT 0,
                model VARCHAR(50) DEFAULT 'deepseek-chat',
                market_data TEXT DEFAULT NULL,
                index_data TEXT DEFAULT NULL,
                news_data TEXT DEFAULT NULL,
                ai_content TEXT DEFAULT NULL,
                fund_director_content TEXT DEFAULT NULL COMMENT '操作决策内容',
                sector_data TEXT DEFAULT NULL COMMENT '板块数据',
                moneyflow_data TEXT DEFAULT NULL COMMENT '资金流向数据',
                technical_data TEXT DEFAULT NULL COMMENT '技术指标数据',
                review_data TEXT DEFAULT NULL COMMENT '复盘数据',
                minute_data TEXT DEFAULT NULL COMMENT '分时图数据',
                main_force_cost_data TEXT DEFAULT NULL COMMENT '主力成本区数据',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )";
            if (!$conn->query($sql)) {
                throw new Exception('创建股票分析记录表失败: ' . $conn->error);
            }
            
            // 创建系统设置表
            $sql = "CREATE TABLE IF NOT EXISTS system_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                key_name VARCHAR(50) UNIQUE NOT NULL,
                key_value TEXT DEFAULT NULL,
                updated_at DATETIME DEFAULT NULL
            )";
            if (!$conn->query($sql)) {
                throw new Exception('创建系统设置表失败: ' . $conn->error);
            }
            
            // 创建系统日志表
            $sql = "CREATE TABLE IF NOT EXISTS system_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(50) NOT NULL,
                content TEXT NOT NULL,
                user_id INT DEFAULT NULL,
                admin_id INT DEFAULT NULL,
                ip_address VARCHAR(50) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )";
            if (!$conn->query($sql)) {
                throw new Exception('创建系统日志表失败: ' . $conn->error);
            }
            
            // 创建龙头股数据表
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
            if (!$conn->query($sql)) {
                throw new Exception('创建龙头股数据表失败: ' . $conn->error);
            }
            
            // 创建龙头股数据缓存表
            $sql = "CREATE TABLE IF NOT EXISTS dragon_data_cache (
                id INT AUTO_INCREMENT PRIMARY KEY,
                cache_key VARCHAR(50) NOT NULL UNIQUE COMMENT '缓存键',
                cache_data LONGTEXT NOT NULL COMMENT '缓存数据(JSON)',
                last_update TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最后更新时间',
                is_expired TINYINT(1) DEFAULT 0 COMMENT '是否过期'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='龙头股数据缓存表'";
            if (!$conn->query($sql)) {
                throw new Exception('创建龙头股数据缓存表失败: ' . $conn->error);
            }
            
            // 插入系统设置
            $settings = [
                ['key_name' => 'deepseek_api_key', 'key_value' => $deepseekApiKey],
                ['key_name' => 'default_model', 'key_value' => $defaultModel],
                ['key_name' => 'new_user_points', 'key_value' => $newUserPoints],
                ['key_name' => 'analysis_cost', 'key_value' => $analysisCost],
                ['key_name' => 'recharge_qrcode', 'key_value' => ''],
                ['key_name' => 'recharge_notes', 'key_value' => '1. 请扫描二维码进行充值\n2. 充值后请联系管理员确认\n3. 充值金额将转换为相应的积分']
            ];
            
            foreach ($settings as $setting) {
                $keyName = $conn->real_escape_string($setting['key_name']);
                $keyValue = $conn->real_escape_string($setting['key_value']);
                
                $sql = "INSERT INTO system_settings (key_name, key_value) 
                        VALUES ('$keyName', '$keyValue') 
                        ON DUPLICATE KEY UPDATE key_value = '$keyValue'";
                if (!$conn->query($sql)) {
                    throw new Exception('插入系统设置失败: ' . $conn->error);
                }
            }
            
            // 创建管理员用户
            $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);
            $adminUsername = $conn->real_escape_string($adminUsername);
            
            $sql = "INSERT INTO admin_users (username, password) 
                    VALUES ('$adminUsername', '$hashedPassword') 
                    ON DUPLICATE KEY UPDATE password = '$hashedPassword'";
            if (!$conn->query($sql)) {
                throw new Exception('创建管理员用户失败: ' . $conn->error);
            }
            
            // 创建配置文件
            $configContent = <<<EOF
<?php
// 数据库配置
define('DB_HOST', '$dbHost');
define('DB_PORT', '$dbPort');
define('DB_USER', '$dbUser');
define('DB_PASS', '$dbPass');
define('DB_NAME', '$dbName');

// 系统配置
define('SYSTEM_INSTALLED', true);
EOF;
            
            file_put_contents($configFile, $configContent);
            
            // 自动添加新字段（如果不存在）
            $newColumns = [
                'sellable_shares' => "INT DEFAULT 0",
                'model' => "VARCHAR(50) DEFAULT 'deepseek-chat'",
                'fund_director_content' => "TEXT DEFAULT NULL",
                'sector_data' => "TEXT DEFAULT NULL",
                'moneyflow_data' => "TEXT DEFAULT NULL",
                'technical_data' => "TEXT DEFAULT NULL",
                'review_data' => "TEXT DEFAULT NULL",
                'minute_data' => "TEXT DEFAULT NULL",
                'main_force_cost_data' => "TEXT DEFAULT NULL"
            ];
            
            foreach ($newColumns as $column => $definition) {
                $checkColumn = $conn->query("SHOW COLUMNS FROM stock_analyses LIKE '$column'");
                if ($checkColumn->num_rows == 0) {
                    $conn->query("ALTER TABLE stock_analyses ADD COLUMN $column $definition");
                }
            }
            
            // 关闭连接
            $conn->close();
            
            // 显示安装成功页面
            showSuccessPage();
            exit;
            
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// 显示成功页面的函数
function showSuccessPage() {
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>安装成功</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --bg-color: #0d1117;
            --text-color: #c9d1d9;
            --card-bg: #161b22;
            --border-color: #30363d;
            --primary-color: #238636;
            --primary-hover: #2ea043;
        }
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            padding: 2rem;
        }
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 2rem;
        }
        .btn-primary {
            background: var(--primary-color);
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background: var(--primary-hover);
        }
    </style>
</head>
<body>
    <div class="max-w-4xl mx-auto">
        <div class="card text-center">
            <h1 class="text-2xl font-bold mb-4">安装成功！</h1>
            <p class="mb-6">系统已成功安装，您可以开始使用 AI 股票分析系统了。</p>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
                <div class="p-4 border border-gray-700 rounded">
                    <h3 class="font-bold mb-2">用户端</h3>
                    <p class="text-sm text-gray-400">用户可以通过账号密码登录系统</p>
                </div>
                <div class="p-4 border border-gray-700 rounded">
                    <h3 class="font-bold mb-2">后台管理</h3>
                    <p class="text-sm text-gray-400">管理员可以登录后台管理系统</p>
                </div>
            </div>
            <a href="index.html" class="btn-primary text-white font-bold py-2 px-6 rounded inline-block">进入系统</a>
        </div>
    </div>
</body>
</html>
<?php
}

// 如果不是POST请求或有错误，显示安装表单
if (!isset($error)) {
    $error = '';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI 股票分析系统 - 安装</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --bg-color: #0d1117;
            --text-color: #c9d1d9;
            --card-bg: #161b22;
            --border-color: #30363d;
            --input-bg: #0d1117;
            --hover-bg: #21262d;
            --gray-400: #8b949e;
            --gray-500: #6e7681;
            --primary-color: #238636;
            --primary-hover: #2ea043;
            --blue-400: #58a6ff;
            --red-400: #f85149;
            --green-400: #7ee787;
        }
        
        body {
            background-color: var(--bg-color);
            color: var(--text-color);
            transition: all 0.3s ease;
            padding: 1.5rem;
        }
        
        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            transition: all 0.3s ease;
        }
        
        .card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
        
        .btn-primary {
            background: var(--primary-color);
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: var(--primary-hover);
            transform: translateY(-1px);
        }
        
        input, select {
            background: var(--input-bg);
            border: 1px solid var(--border-color);
            color: var(--text-color);
            transition: all 0.3s ease;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: var(--blue-400);
            box-shadow: 0 0 0 2px rgba(88, 166, 255, 0.2);
        }
        
        .error-message {
            background-color: rgba(248, 81, 73, 0.1);
            border: 1px solid var(--red-400);
            color: var(--red-400);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        
        @media (max-width: 768px) {
            body {
                font-size: 16px;
                padding: 1rem;
            }
            
            .card {
                padding: 1.25rem;
            }
            
            input, select {
                padding: 0.875rem;
                min-height: 44px;
            }
            
            button {
                padding: 0.875rem 1.75rem;
                min-height: 44px;
            }
        }
    </style>
</head>
<body>
    <div class="max-w-4xl mx-auto">
        <div class="flex justify-between items-center mb-8">
            <h1 class="text-2xl font-bold flex items-center gap-2">📊 AI 股票分析系统 - 安装</h1>
        </div>
        
        <div class="card p-8">
            <?php if ($error): ?>
                <div class="error-message">
                    <strong>错误：</strong> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <h2 class="text-xl font-bold mb-6">系统安装</h2>
            <p class="text-gray-400 mb-6">请填写以下信息完成系统安装</p>
            
            <form id="installForm" method="POST">
                <!-- 数据库设置 -->
                <div class="mb-8">
                    <h3 class="text-lg font-bold mb-4">数据库设置</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">数据库主机</label>
                            <input type="text" name="db_host" value="localhost" required class="w-full bg-input-bg border border-border-color rounded p-2 outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">数据库端口</label>
                            <input type="text" name="db_port" value="3306" required class="w-full bg-input-bg border border-border-color rounded p-2 outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">数据库用户名</label>
                            <input type="text" name="db_user" required class="w-full bg-input-bg border border-border-color rounded p-2 outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">数据库密码</label>
                            <input type="password" name="db_pass" class="w-full bg-input-bg border border-border-color rounded p-2 outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">数据库名称</label>
                            <input type="text" name="db_name" value="ai_stock_analysis" required class="w-full bg-input-bg border border-border-color rounded p-2 outline-none focus:border-blue-500">
                        </div>
                    </div>
                </div>
                
                <!-- AI 设置 -->
                <div class="mb-8">
                    <h3 class="text-lg font-bold mb-4">AI 设置</h3>
                    <div class="grid grid-cols-1 gap-4">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">DeepSeek API Key</label>
                            <input type="password" name="deepseek_api_key" required class="w-full bg-input-bg border border-border-color rounded p-2 outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">默认 DeepSeek 模型</label>
                            <select name="default_model" class="w-full bg-input-bg border border-border-color rounded p-2 outline-none focus:border-blue-500">
                                <option value="deepseek-chat">deepseek-chat</option>
                                <option value="deepseek-reasoner">deepseek-reasoner (思考模式)</option>
                            </select>
                        </div>
                    </div>
                </div>
                
                <!-- 后台管理员设置 -->
                <div class="mb-8">
                    <h3 class="text-lg font-bold mb-4">后台管理员设置</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">管理员用户名</label>
                            <input type="text" name="admin_username" value="admin" required class="w-full bg-input-bg border border-border-color rounded p-2 outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">管理员密码</label>
                            <input type="password" name="admin_password" required class="w-full bg-input-bg border border-border-color rounded p-2 outline-none focus:border-blue-500">
                        </div>
                    </div>
                </div>
                
                <!-- 系统设置 -->
                <div class="mb-8">
                    <h3 class="text-lg font-bold mb-4">系统设置</h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">新用户注册赠送积分</label>
                            <input type="number" name="new_user_points" value="20" min="0" required class="w-full bg-input-bg border border-border-color rounded p-2 outline-none focus:border-blue-500">
                        </div>
                        <div>
                            <label class="block text-xs text-gray-500 mb-1">每次分析扣除积分</label>
                            <input type="number" name="analysis_cost" value="1" min="1" required class="w-full bg-input-bg border border-border-color rounded p-2 outline-none focus:border-blue-500">
                        </div>
                    </div>
                </div>
                
                <div class="pt-4">
                    <button type="submit" class="btn-primary text-white font-bold py-3 px-6 rounded w-full">开始安装</button>
                </div>
            </form>
        </div>
        
        <div class="mt-8 text-center text-gray-400 text-sm">
            &copy; 2026 AI 股票分析系统
        </div>
    </div>
</body>
</html>
