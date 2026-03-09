<?php
// 引入配置和数据库类
require_once 'config.php';
require_once 'includes/database.php';

// 启动会话
session_start();

// 股票搜索API端点
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'search_stock') {
    header('Content-Type: application/json');
    $stockName = $_GET['name'] ?? '';
    
    try {
        if (empty($stockName)) {
            echo json_encode(['error' => '请输入股票/基金名称']);
            exit;
        }
        
        // 使用东方财富搜索接口
        $url = 'https://searchapi.eastmoney.com/api/suggest/get?input=' . urlencode($stockName) . '&type=14&count=20';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_REFERER, 'https://www.eastmoney.com/');
        
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || empty($raw)) {
            echo json_encode(['error' => '网络请求失败']);
            exit;
        }
        
        // 解析东方财富接口返回的数据
        $stocks = [];
        $data = json_decode($raw, true);
        
        if ($data && isset($data['QuotationCodeTable']['Data'])) {
            foreach ($data['QuotationCodeTable']['Data'] as $item) {
                if (empty($item['Name']) || empty($item['Code'])) continue;
                
                // 确定市场前缀
                $market = 'sh';
                if (isset($item['Market'])) {
                    // 东方财富：1=上海，0=深圳
                    $market = $item['Market'] == 1 ? 'sh' : 'sz';
                } else {
                    // 回退：根据代码前缀判断
                    $first = substr($item['Code'], 0, 1);
                    $market = ($first === '5' || $first === '6' || $first === '9') ? 'sh' : 'sz';
                }
                
                $stocks[] = [
                    'name' => $item['Name'],
                    'code' => $item['Code'],
                    'market' => $market,
                    'fullCode' => $market . $item['Code']
                ];
            }
        }
        
        // 确保始终返回有效的JSON
        $response = ['stocks' => $stocks];
        echo json_encode($response);
    } catch (Exception $e) {
        // 捕获所有异常，确保返回错误JSON
        echo json_encode(['error' => '服务器内部错误: ' . $e->getMessage()]);
    }
    exit;
}

// 获取用户历史分析数据
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'history') {
    header('Content-Type: application/json');
    
    $user = isset($_SESSION['user']) ? $_SESSION['user'] : null;
    if (!$user) {
        echo json_encode(['error' => '请先登录']);
        exit;
    }
    
    $history = Database::getUserStockAnalyses($user['id']);
    echo json_encode($history);
    exit;
}

// 删除历史分析记录
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_history') {
    header('Content-Type: application/json');
    
    $user = isset($_SESSION['user']) ? $_SESSION['user'] : null;
    if (!$user) {
        echo json_encode(['success' => false, 'error' => '请先登录']);
        exit;
    }
    
    $analysisId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
    if (!$analysisId) {
        echo json_encode(['success' => false, 'error' => '无效的记录ID']);
        exit;
    }
    
    $result = Database::deleteStockAnalysis($user['id'], $analysisId);
    if ($result) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => '删除失败']);
    }
    exit;
}

// 获取用户积分历史
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'points_history') {
    header('Content-Type: application/json');
    
    $user = isset($_SESSION['user']) ? $_SESSION['user'] : null;
    if (!$user) {
        echo json_encode(['error' => '请先登录']);
        exit;
    }
    
    $history = Database::getUserPointsHistory($user['id']);
    echo json_encode($history);
    exit;
}

// 获取系统设置
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_system_settings') {
    header('Content-Type: application/json');
    
    $systemName = Database::getSystemSetting('system_name') ?: 'AI 股票穿透分析系统';
    $analysisCostChat = Database::getSystemSetting('analysis_cost_chat') ?: 1;
    $analysisCostReasoner = Database::getSystemSetting('analysis_cost_reasoner') ?: 2;
    
    echo json_encode([
        'system_name' => $systemName,
        'analysis_cost_chat' => $analysisCostChat,
        'analysis_cost_reasoner' => $analysisCostReasoner
    ]);
    exit;
}

// 获取充值信息
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_recharge_info') {
    header('Content-Type: application/json');
    
    $qrcode = Database::getSystemSetting('recharge_qrcode') ?: '';
    $notes = Database::getSystemSetting('recharge_notes') ?: "1. 请扫描二维码进行充值\n2. 充值后请联系管理员确认\n3. 充值金额将转换为相应的积分";
    
    echo json_encode([
        'qrcode' => $qrcode,
        'notes' => $notes
    ]);
    exit;
}

// 刷新用户信息
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'refresh_user') {
    header('Content-Type: application/json');
    
    $user = isset($_SESSION['user']) ? $_SESSION['user'] : null;
    if (!$user) {
        echo json_encode(['error' => '请先登录']);
        exit;
    }
    
    $conn = Database::getConnection();
    $userId = $user['id'];
    $sql = "SELECT * FROM users WHERE id = $userId";
    $result = $conn->query($sql);
    
    if ($result && $result->num_rows > 0) {
        $updatedUser = $result->fetch_assoc();
        $_SESSION['user'] = $updatedUser;
        echo json_encode(['success' => true, 'user' => $updatedUser]);
    } else {
        echo json_encode(['error' => '用户不存在']);
    }
    exit;
}

// 发送进度信息的函数
function sendProgress($percentage, $message) {
    echo "PROGRESS:" . json_encode(['percentage' => $percentage, 'message' => $message], JSON_UNESCAPED_UNICODE) . "\n";
    sseFlush();
}

// SSE刷新输出函数
function sseFlush() {
    @ob_flush();
    @flush();
    echo str_repeat(' ', 8192) . "\n";
    @ob_flush();
    @flush();
    usleep(10000);
}

// 基金总监分析接口
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'fund_director_analysis') {
    // 禁用输出压缩
    if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
    }
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');

    // 禁用Gzip压缩
    if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
        apache_setenv('dont-vary', '1');
    }

    // 设置SSE头信息
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('X-Accel-Buffering: no');
    header('Connection: keep-alive');
    header('Access-Control-Allow-Origin: *');

    // 关闭所有输出缓冲层
    while (ob_get_level()) {
        @ob_end_clean();
    }

    // 开启隐式刷新
    @ob_implicit_flush(true);

    // 增加PHP执行时间限制
    set_time_limit(120);



    // 检查用户登录状态
    $user = isset($_SESSION['user']) ? $_SESSION['user'] : null;
    if (!$user) {
        echo "TEXT:❌ 错误: 请先登录系统\n";
        exit;
    }

    // 获取分析成本（使用思考模式）
    $analysisCost = intval(Database::getSystemSetting('analysis_cost_reasoner') ?: 2);

    // 确保从数据库获取最新的用户积分
    $conn = Database::getConnection();
    $userId = $user['id'];
    $sql = "SELECT * FROM users WHERE id = $userId";
    $result = $conn->query($sql);
    if ($result && $result->num_rows > 0) {
        $user = $result->fetch_assoc();
        $_SESSION['user'] = $user;
    }

    // 检查用户积分
    if ($user['points'] < $analysisCost) {
        echo "TEXT:❌ 错误: 积分不足，请先充值\n";
        exit;
    }

    $apiKey = $_POST['apiKey'] ?? '';
    $symbol = trim($_POST['symbol'] ?? '');
    $shares = $_POST['shares'] ?? 0;
    $sellable_shares = $_POST['sellable_shares'] ?? 0;
    $cost   = $_POST['cost'] ?? 0;
    $cash   = $_POST['cash'] ?? 0;
    $marketData = $_POST['marketData'] ?? null;
    $aiResult = $_POST['aiResult'] ?? '';
    $analysisId = $_POST['analysisId'] ?? 0;

    // 解析市场数据
    if ($marketData) {
        $marketData = json_decode($marketData, true);
    }

    // 尝试从系统设置获取API Key
    if (empty($apiKey)) {
        $apiKey = Database::getSystemSetting('deepseek_api_key');
        if (empty($apiKey)) {
            echo "TEXT:❌ 错误: 请输入 DeepSeek API Key\n";
            exit;
        }
    }



    // 发送进度信息
    sendProgress(10, '正在启动操作决策分析...');

    // 构建操作决策的系统提示
    $fundDirectorSystemPrompt = "你是一位经验丰富的投资决策者，负责基于分析报告做出具体的投资操作决策。请直接返回HTML格式的分析报告内容，不要返回完整的HTML结构（不要包含<!DOCTYPE html>、<html>、<head>、<body>等标签），只返回内容部分的HTML代码。\n\nHTML输出要求：\n1. 只返回内容部分的HTML代码，使用清晰的结构，包含适当的标题、段落和表格\n2. 标题使用<h2>和<h3>标签，不要添加内联CSS样式\n3. 段落使用<p>标签，保持良好的行间距\n4. 表格使用<table>标签，不要添加内联CSS样式\n5. 重点内容使用<strong>标签加粗\n6. 不要使用任何<script>或<style>标签\n7. 包含以下部分：\n   - 操作决策表格（买入、卖出、持有、数量、价格区间、止损价、目标价）\n   - 操作说明（详细说明操作的具体执行方式）\n   - 决策理由（技术面、基本面和风险因素，逐条列举）\n   - 风险控制措施（逐条列举）\n   - 次日预测（如果当前时间已经休市）\n\n请参考提供的分析报告、用户数据和股票参数进行决策。";

    // 发送进度信息
    sendProgress(20, '正在构建分析参数...');

    // 构建操作决策的用户提示
    $currentDateTime = date('Y-m-d H:i:s');
    $fundDirectorUserPrompt = "分析时间：{$currentDateTime}\n";
    $fundDirectorUserPrompt .= "当前市场状态：" . (isMarketClosed() ? "休市" : "交易中") . "\n\n";
    $fundDirectorUserPrompt .= "分析报告：\n{$aiResult}\n\n";
    $fundDirectorUserPrompt .= "用户数据：\n";
    if (empty($shares) || empty($cost)) {
        $fundDirectorUserPrompt .= "- 持仓状态：空仓\n";
    } else {
        $fundDirectorUserPrompt .= "- 持仓数量：{$shares} 股/份\n";
        $fundDirectorUserPrompt .= "- 可卖出数量：{$sellable_shares} 股/份\n";
        $fundDirectorUserPrompt .= "- 持仓成本：{$cost} 元\n";
    }
    $fundDirectorUserPrompt .= "- 可用资金：{$cash} 元\n\n";
    $fundDirectorUserPrompt .= "股票参数：\n";
    $fundDirectorUserPrompt .= "- 股票代码：{$symbol}\n";
    if ($marketData) {
        $fundDirectorUserPrompt .= "- 股票名称：" . ($marketData['名称'] ?? '未知') . "\n";
        $fundDirectorUserPrompt .= "- 当前价格：" . ($marketData['价格'] ?? '未知') . " 元\n";
        $fundDirectorUserPrompt .= "- 涨跌幅：" . ($marketData['涨跌幅%'] ?? '未知') . "\n\n";
    } else {
        $fundDirectorUserPrompt .= "- 股票名称：未知\n";
        $fundDirectorUserPrompt .= "- 当前价格：未知 元\n";
        $fundDirectorUserPrompt .= "- 涨跌幅：未知\n\n";
    }
    $fundDirectorUserPrompt .= "决策要求：\n";
    $fundDirectorUserPrompt .= "1. 基于分析报告和当前市场状况，做出具体的投资决策\n";
    $fundDirectorUserPrompt .= "2. 明确操作方向（买入、卖出、持有）\n";
    $fundDirectorUserPrompt .= "3. 计算具体的买卖数量（考虑可卖出数量、可用资金和风险控制）\n";
    $fundDirectorUserPrompt .= "4. 详细说明决策理由，包括技术面、基本面和风险因素，每条理由单独一行\n";
    $fundDirectorUserPrompt .= "5. 提供风险控制措施和止损建议，每条措施单独一行\n";
    $fundDirectorUserPrompt .= "6. 如果当前时间已经休市，请提供次日的涨跌预测和操作建议\n";
    $fundDirectorUserPrompt .= "7. 重要：卖出数量绝对不能超过可卖出数量\n";
    if ($sellable_shares <= 0) {
        $fundDirectorUserPrompt .= "8. 重要：由于可卖出数量为0，绝对不能建议卖出操作，只能建议买入或持有，或者建议用户第二天操作\n";
    }

    // 发送进度信息
    sendProgress(30, '正在调用AI进行操作决策分析...');

    // 调用思考模式的AI进行操作决策分析
    $fundDirectorPostData = [
        "model" => "deepseek-chat",
        "messages" => [
            ["role" => "system", "content" => $fundDirectorSystemPrompt],
            ["role" => "user", "content" => $fundDirectorUserPrompt]
        ],
        "stream" => true
    ];

    $ch = curl_init("https://api.deepseek.com/chat/completions");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fundDirectorPostData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Authorization: Bearer " . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

    // 用于存储操作决策分析结果
    $fundDirectorResult = '';

    // 发送操作决策分析开始的标记
    echo "TEXT:<h2>操作决策</h2>\n";
    sseFlush();

    // 发送进度信息
    sendProgress(50, 'AI正在分析数据并生成决策...');

    // 设置curl回调函数以捕获操作决策分析结果
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$fundDirectorResult) {
        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            if (strpos($line, 'data: ') === 0) {
                $jsonStr = trim(substr($line, 6));
                if ($jsonStr === '[DONE]') break;
                $decoded = json_decode($jsonStr, true);
                $content = $decoded['choices'][0]['delta']['content'] ?? '';
                if ($content) {
                    $fundDirectorResult .= $content;
                    echo "TEXT:" . $content . "\n";
                    sseFlush();
                }
            }
        }
        return strlen($data);
    });

    curl_exec($ch);
    curl_close($ch);

    // 发送进度信息
    sendProgress(80, '正在处理分析结果...');

    // 记录操作决策分析日志
    logSystemAction('fund_director_analysis', "用户 {$user['username']} (ID: {$user['id']}) 启用了操作决策功能分析股票 {$symbol}", $user['id']);

    // 发送进度信息
    sendProgress(90, '正在更新用户积分...');

    // 扣除额外的积分（使用思考模式需要更多积分）
    $fundDirectorCost = intval(Database::getSystemSetting('analysis_cost_reasoner') ?: 2);
    logDebug('开始扣除操作决策分析积分: user_id=' . $user['id'] . ', points=' . $fundDirectorCost . ', type=deduct, reason=操作决策分析扣除', $user['id']);
    $deductResult = Database::updateUserPoints($user['id'], $fundDirectorCost, 'deduct', '操作决策分析扣除');
    logDebug('操作决策分析积分扣除结果: ' . ($deductResult ? '成功' : '失败'), $user['id']);

    // 保存操作决策分析记录到数据库（更新现有记录）
    logDebug('开始保存操作决策分析记录到数据库', $user['id']);
    $saveResult = Database::saveStockAnalysis(
        $user['id'], 
        $symbol, 
        $shares, 
        $sellable_shares, 
        $cost, 
        $cash, 
        'deepseek-reasoner', // 操作决策分析使用思考模式
        json_encode($marketData), 
        json_encode([]), // 上证指数数据
        json_encode([]), // 新闻数据
        $aiResult, // 原始分析内容
        json_encode([]), // 板块数据
        json_encode([]), // 资金流向数据
        json_encode([]), // 技术指标数据
        json_encode([]), // 复盘数据
        $fundDirectorResult, // 操作决策内容
        null, // minute_data
        $analysisId // 分析ID，用于更新现有记录
    );
    logDebug('保存操作决策分析记录结果: ' . ($saveResult ? '成功' : '失败'), $user['id']);

    // 更新用户会话信息
    $conn = Database::getConnection();
    $userId = $user['id'];
    $sql = "SELECT * FROM users WHERE id = $userId";
    $result = $conn->query($sql);
    $_SESSION['user'] = $result->fetch_assoc();

    // 发送进度信息：分析完成
    sendProgress(100, '操作决策分析完成！');

    // 隐藏进度条
    echo "PROGRESS:" . json_encode(['percentage' => 100, 'message' => '操作决策分析完成！', 'hide' => true], JSON_UNESCAPED_UNICODE) . "\n";
    sseFlush();
    exit;
}

// 原始的股票分析接口
// 禁用输出压缩
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
}
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');

// 禁用Gzip压缩
if (function_exists('apache_setenv')) {
    apache_setenv('no-gzip', '1');
    apache_setenv('dont-vary', '1');
}

// 设置SSE头信息
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');
header('Access-Control-Allow-Origin: *');

// 关闭所有输出缓冲层
while (ob_get_level()) {
    @ob_end_clean();
}

// 开启隐式刷新
@ob_implicit_flush(true);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;

// 增加PHP执行时间限制
set_time_limit(120);

// 检查用户登录状态
$user = isset($_SESSION['user']) ? $_SESSION['user'] : null;
if (!$user) {
    echo "TEXT:❌ 错误: 请先登录系统\n";
    exit;
}

// 确保从数据库获取最新的用户积分
$conn = Database::getConnection();
$userId = $user['id'];
$sql = "SELECT * FROM users WHERE id = $userId";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
}

// 释放session锁，允许同一用户并发请求
session_write_close();

// 获取分析成本
$model = $_POST['model'] ?? 'deepseek-chat';
if ($model === 'deepseek-reasoner') {
    $analysisCost = intval(Database::getSystemSetting('analysis_cost_reasoner') ?: 2);
} else {
    $analysisCost = intval(Database::getSystemSetting('analysis_cost_chat') ?: 1);
}

// 检查用户积分
if ($user['points'] < $analysisCost) {
    echo "TEXT:❌ 错误: 积分不足，请先充值\n";
    exit;
}

$apiKey = $_POST['apiKey'] ?? '';
$symbol = trim($_POST['symbol'] ?? '');
$shares = $_POST['shares'] ?? 0;
$sellable_shares = $_POST['sellable_shares'] ?? 0;
$cost   = $_POST['cost'] ?? 0;
$cash   = $_POST['cash'] ?? 0;

// 记录调试信息
logDebug("收到的可卖出数量: {$sellable_shares}", $user['id']);
logDebug("收到的持有数量: {$shares}", $user['id']);
$reviewData = $_POST['reviewData'] ?? null;
$fullContent = isset($_POST['fullContent']) ? true : false;
$fundDirector = isset($_POST['fundDirector']) ? true : false;
$strategies = $_POST['strategies'] ?? '[]';
$selectedStrategies = json_decode($strategies, true) ?? [];
$specialEvent = trim($_POST['specialEvent'] ?? '');
$tradingStyle = trim($_POST['tradingStyle'] ?? '普通');
$holdingStyle = trim($_POST['holdingStyle'] ?? '视情况而定');

// 解析复盘数据
$parsedReviewData = [];
if ($reviewData) {
    $parsedReviewData = json_decode($reviewData, true);
    if (!is_array($parsedReviewData)) {
        $parsedReviewData = [];
    }
}

// 记录系统日志的辅助函数
function logSystemAction($type, $content, $userId = null) {
    $adminId = null;
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    Database::addSystemLog($type, $content, $userId, $adminId, $ipAddress);
}

// 记录调试日志到system_logs表
function logDebug($content, $userId = null) {
    $adminId = null;
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    Database::addSystemLog('debug', $content, $userId, $adminId, $ipAddress);
}

// 检查是否休市时间（对于均线计算，交易日过了9点半就算开盘）
function isMarketClosed() {
    $now = new DateTime('Asia/Shanghai');
    $hour = (int)$now->format('H');
    $minute = (int)$now->format('i');
    $dayOfWeek = (int)$now->format('w');
    
    // 周末休市
    if ($dayOfWeek == 0 || $dayOfWeek == 6) {
        return true;
    }
    
    // 对于均线计算，交易日过了9点半就算开盘（即使在中午休市时间）
    $hasOpenedToday = ($hour > 9 || ($hour == 9 && $minute >= 30));
    
    return !$hasOpenedToday;
}

// 尝试从系统设置获取API Key
if (empty($apiKey)) {
    $apiKey = Database::getSystemSetting('deepseek_api_key');
    if (empty($apiKey)) {
        echo "TEXT:❌ 错误: 请输入 DeepSeek API Key\n";
        exit;
    }
}

// 判断是否是开放式基金（非ETF、非LOF、非股票）
function isOpenEndFund($code) {
    $cleanCode = preg_replace('/[a-z]/i', '', $code);
    if (strlen($cleanCode) !== 6) return false;
    
    $prefix = substr($cleanCode, 0, 2);
    $prefix3 = substr($cleanCode, 0, 3);
    
    $stockPrefixes = [
        '000', '001', '002', '003',
        '300', '301', '302', '303',
        '600', '601', '603', '605',
        '688', '689',
        '430', '830', '831', '832', '833', '834', '835', '836', '837', '838', '839', '840', '841', '842', '843', '844', '845', '846', '847', '848', '849', '850', '851', '852', '853', '854', '855', '856', '857', '858', '859', '860', '861', '862', '863', '864', '865', '866', '867', '868', '869', '870', '871', '872', '873', '878', '889', '900'
    ];
    
    foreach ($stockPrefixes as $sp) {
        if (strpos($cleanCode, $sp) === 0) {
            return false;
        }
    }
    
    $etfLofPrefixes = [
        '50', '51', '52', '56', '58',
        '15', '16', '17', '18'
    ];
    if (in_array($prefix, $etfLofPrefixes)) {
        return false;
    }
    
    $openFundPrefixes = [
        '00', '01', '02', '03', '04', '05', '06', '07', '08', '09',
        '10', '11', '12', '13', '14', '19',
        '20', '21', '22', '23', '24', '25', '26', '27', '28', '29',
        '30', '31', '32', '33', '34', '35', '36', '37', '38', '39',
        '40', '41', '42', '43', '44', '45', '46', '47', '48', '49',
        '53', '54', '55', '57', '59',
        '60', '61', '62', '63', '64', '65', '66', '67', '68', '69',
        '70', '71', '72', '73', '74', '75', '76', '77', '78', '79',
        '80', '81', '82', '83', '84', '85', '86', '87', '88', '89',
        '90', '91', '92', '93', '94', '95', '96', '97', '98', '99'
    ];
    
    return in_array($prefix, $openFundPrefixes);
}

// 获取资讯详情的函数
function getNewsDetail($url, $fullContent = false) {
    try {
        if (empty($url)) {
            return '';
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // 模拟 Header
        $headers = [
            "Referer: https://finance.sina.com.cn/",
            "Upgrade-Insecure-Requests: 1",
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8",
            "Accept-Language: zh-CN,zh;q=0.9",
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36");

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); // 较短的超时时间，避免影响整体性能

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($html === false || $httpCode !== 200) {
            error_log('获取资讯详情失败: 请求失败，状态码: ' . $httpCode);
            return '';
        }

        // 智能检测并转换编码
        $encoding = mb_detect_encoding($html, array('UTF-8', 'GBK', 'GB2312', 'ASCII'));
        if ($encoding && $encoding != 'UTF-8') {
            $html = mb_convert_encoding($html, 'UTF-8', $encoding);
        } elseif (!$encoding) {
            // 如果无法检测编码，尝试多种编码转换
            $encodings = array('GBK', 'GB2312', 'UTF-8', 'ASCII');
            foreach ($encodings as $enc) {
                try {
                    $converted = mb_convert_encoding($html, 'UTF-8', $enc);
                    if ($converted !== false) {
                        $html = $converted;
                        break;
                    }
                } catch (Exception $e) {
                    // 忽略转换错误
                }
            }
        }

        // 尝试匹配不同的正文容器
        $content = '';
        
        // 新浪财经常见的正文容器
        $patterns = [
            // 新浪财经研报特殊容器
            '/<div class="research-report-content"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div class="report-content"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div class="研报内容"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div class="content-detail"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div class="article-body"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div class="article-content"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div id="article_content"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div class="article_content"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div id="content"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div class="content"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div id="main-content"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div class="main-content"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div id="artibody"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div class="artibody"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div id="article"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div class="article"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div id="news-content"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div class="news-content"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div id="new-content"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div class="new-content"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div id="content_article"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div class="content_article"[^>]*>([\s\S]*?)<\/div>/i',
            // 新增：新浪财经研报页面特殊结构
            '/<div class="blk_container"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div class="blkContainerSblk"[^>]*>([\s\S]*?)<\/div>/i',
            '/<div class="blkContainer"[^>]*>([\s\S]*?)<\/div>/i',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $html, $matches)) {
                // 合并所有匹配到的div内容
                foreach ($matches[1] as $match) {
                    $content .= $match;
                }
                // 如果已经获取到内容，继续尝试其他模式，而不是break
            }
        }
        
        // 如果没有匹配到任何容器，尝试提取所有段落
        if (empty($content)) {
            if (preg_match_all('/<p[^>]*>([\s\S]*?)<\/p>/i', $html, $matches)) {
                // 过滤掉太短的段落，通常是无关内容
                $validParagraphs = [];
                foreach ($matches[1] as $paragraph) {
                    $cleanPara = trim(strip_tags($paragraph));
                    if (mb_strlen($cleanPara) > 30) {
                        $validParagraphs[] = $cleanPara;
                    }
                }
                $content = implode('\n\n', $validParagraphs);
            }
        }
        
        // 如果仍然没有内容，尝试提取所有文本
        if (empty($content)) {
            // 移除脚本和样式
            $html = preg_replace('/<script[^>]*>[\s\S]*?<\/script>/i', '', $html);
            $html = preg_replace('/<style[^>]*>[\s\S]*?<\/style>/i', '', $html);
            // 提取所有文本
            $content = trim(strip_tags($html));
            // 清理多余空白
            $content = preg_replace('/\s+/', ' ', $content);
        }
        
        // 特别处理新浪财经研报页面的<br>标签和&nbsp;实体
        $content = str_replace(['<br>', '<br/>', '<br />'], '\n', $content);
        $content = str_replace('&nbsp;', ' ', $content);
        
        // 清理 HTML 标签
        $content = strip_tags($content);
        
        // 清理多余的空白
        $content = preg_replace('/\s+/', ' ', $content);
        $content = trim($content);
        
        // 只保留前500个字符，避免过长（除非用户选择获取全部内容）
        if (!$fullContent && mb_strlen($content) > 500) {
            $content = mb_substr($content, 0, 500) . '...';
        }
        
        // 如果内容太短，可能不是有效的正文
        if (mb_strlen($content) < 50) {
            return '';
        }
        
        return $content;
    } catch (Exception $e) {
        error_log('获取资讯详情异常: ' . $e->getMessage());
        return '';
    }
}

// 获取股票/基金最新资讯列表的函数
function getStockNews($code, $stockName = '', $fullContent = false) {
    // 判断是否是开放式基金，如果是则不获取资讯（没有合适的接口）
    if (isOpenEndFund($code)) {
        error_log('开放式基金不获取资讯: ' . $code);
        return [];
    }
    
    // 确保代码是小写，如 sh600519
    $code = strtolower($code);
    $cleanCode = preg_replace('/[a-z]/i', '', $code);
    $url = "https://vip.stock.finance.sina.com.cn/corp/go.php/vCB_AllNewsStock/symbol/{$code}.phtml";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    
    // 模拟 Header
    $headers = [
        "Referer: https://finance.sina.com.cn/",
        "Upgrade-Insecure-Requests: 1",
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8",
        "Accept-Language: zh-CN,zh;q=0.9",
    ];
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/110.0.0.0 Safari/537.36");

    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 增加超时时间，该页面内容较多

    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($html === false || $httpCode !== 200) {
        error_log('获取股票资讯失败: 请求失败，状态码: ' . $httpCode);
        return [];
    }

    // 1. 转换编码
    $html = mb_convert_encoding($html, 'UTF-8', 'GBK');

    // 2. 锁定新闻区块 (datelist)
    // 这一步非常重要，防止抓到侧边栏的干扰链接
    if (preg_match('/<div class="datelist">([\s\S]*?)<\/div>/i', $html, $matchesBlock)) {
        $html = $matchesBlock[1];
    }

    // 3. 修改后的精准正则表达式
    /**
     * 解析逻辑说明：
     * (\d{4}-\d{2}-\d{2}(?:&nbsp;|\s)+\d{2}:\d{2}) : 匹配 2026-02-13 20:22 这种格式
     * [\s&nbsp;]+ : 匹配中间的空格或 &nbsp;
     * <a[^>]+href=\'([^\']*)\' : 匹配 href='...' (注意新浪这里用的是单引号)
     * [^>]*>(.*?)<\/a> : 匹配标题
     */
    $pattern = '/(\d{4}-\d{2}-\d{2}(?:&nbsp;|\s)+\d{2}:\d{2})[\s&nbsp;]+<a[^>]+href=\'([^\']*)\'[^>]*>(.*?)<\/a>/i';
    
    preg_match_all($pattern, $html, $matches);

    $newsList = [];
    if (!empty($matches[3])) {
        foreach ($matches[3] as $index => $title) {
            $newsTime = trim(str_replace('&nbsp;', ' ', $matches[1][$index]));
            $newsUrl = $matches[2][$index];
            $newsTitle = trim(strip_tags($title));
            
            // 构建新闻信息
            $newsItem = [
                'time' => $newsTime,
                'url' => $newsUrl,
                'title' => $newsTitle
            ];
            
            // 判断是否是近两日的资讯
            $newsDate = new DateTime($newsTime);
            $today = new DateTime();
            $yesterday = new DateTime('-1 day');
            $isRecent = $newsDate >= $yesterday->setTime(0, 0, 0) && $newsDate <= $today->setTime(23, 59, 59);
            
            // 判断标题中是否包含股票名称或代码
            $hasStockInfo = (
                (!empty($stockName) && strpos($newsTitle, $stockName) !== false) ||
                strpos($newsTitle, $cleanCode) !== false
            );
            
            // 如果是近两日且包含股票信息，尝试获取详情
            if ($isRecent && $hasStockInfo) {
                $newsItem['content'] = getNewsDetail($newsUrl, $fullContent);
            }
            
            $newsList[] = $newsItem;
        }
    }

    return array_slice($newsList, 0, 20); // 返回前20条
}

/**
 * 获取股票所属板块信息
 */
function getStockSector($fullCode) {
    $sectorInfo = [];
    try {
        $code = preg_replace('/[a-z]/i', '', $fullCode);
        $url = "https://push2.eastmoney.com/api/qt/stock/get?secid=" . (strpos($fullCode, 'sh') !== false ? '1.' : '0.') . $code . "&fields=f127,f128,f129";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36");
        $raw = curl_exec($ch);
        curl_close($ch);
        
        if ($raw) {
            $data = json_decode($raw, true);
            if ($data && isset($data['data'])) {
                if (!empty($data['data']['f127'])) {
                    $sectorInfo['行业板块'] = $data['data']['f127'];
                }
                if (!empty($data['data']['f128'])) {
                    $sectorInfo['地区板块'] = $data['data']['f128'];
                }
                if (!empty($data['data']['f129'])) {
                    $sectorInfo['概念板块'] = $data['data']['f129'];
                }
            }
        }
    } catch (Exception $e) {
        error_log('获取板块信息失败: ' . $e->getMessage());
    }
    return $sectorInfo;
}

/**
 * 获取资金流向数据
 * 东方财富接口返回的单位是元，转换为万元存储
 * 字段映射：
 * f137: 主力净流入
 * f140: 超大单净流入
 * f138: 超大单流入
 * f139: 超大单流出
 * f143: 大单净流入
 * f141: 大单流入
 * f142: 大单流出
 * f146: 中单净流入
 * f144: 中单流入
 * f145: 中单流出
 * f149: 小单净流入
 * f147: 小单流入
 * f148: 小单流出
 */
function getMoneyFlow($fullCode) {
    $moneyFlow = [];
    try {
        $code = preg_replace('/[a-z]/i', '', $fullCode);
        $url = "https://push2.eastmoney.com/api/qt/stock/get?secid=" . (strpos($fullCode, 'sh') !== false ? '1.' : '0.') . $code . "&fields=f137,f138,f139,f140,f141,f142,f143,f144,f145,f146,f147,f148,f149";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36");
        $raw = curl_exec($ch);
        curl_close($ch);
        
        if ($raw) {
            $data = json_decode($raw, true);
            if ($data && isset($data['data'])) {
                $d = $data['data'];
                // 东方财富接口返回的是元，除以10000转为万元
                $moneyFlow = [
                    '主力净流入' => isset($d['f137']) ? ($d['f137'] / 10000) : '',
                    '超大单净流入' => isset($d['f140']) ? ($d['f140'] / 10000) : '',
                    '超大单流入' => isset($d['f138']) ? ($d['f138'] / 10000) : '',
                    '超大单流出' => isset($d['f139']) ? ($d['f139'] / 10000) : '',
                    '大单净流入' => isset($d['f143']) ? ($d['f143'] / 10000) : '',
                    '大单流入' => isset($d['f141']) ? ($d['f141'] / 10000) : '',
                    '大单流出' => isset($d['f142']) ? ($d['f142'] / 10000) : '',
                    '中单净流入' => isset($d['f146']) ? ($d['f146'] / 10000) : '',
                    '中单流入' => isset($d['f144']) ? ($d['f144'] / 10000) : '',
                    '中单流出' => isset($d['f145']) ? ($d['f145'] / 10000) : '',
                    '小单净流入' => isset($d['f149']) ? ($d['f149'] / 10000) : '',
                    '小单流入' => isset($d['f147']) ? ($d['f147'] / 10000) : '',
                    '小单流出' => isset($d['f148']) ? ($d['f148'] / 10000) : ''
                ];
            }
        }
    } catch (Exception $e) {
        error_log('获取资金流向失败: ' . $e->getMessage());
    }
    return $moneyFlow;
}

/**
 * 获取K线数据
 */
function getKLineData($fullCode, $count = 60) {
    $klineData = [];
    try {
        $code = preg_replace('/[a-z]/i', '', $fullCode);
        $url = "https://push2his.eastmoney.com/api/qt/stock/kline/get?secid=" . (strpos($fullCode, 'sh') !== false ? '1.' : '0.') . $code . "&fields1=f1,f2,f3,f4,f5,f6&fields2=f51,f52,f53,f54,f55,f56,f57,f58,f59,f60,f61&klt=101&fqt=1&beg=0&end=20500101";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $raw = curl_exec($ch);
        curl_close($ch);
        
        if ($raw) {
            $data = json_decode($raw, true);
            if ($data && isset($data['data']['klines'])) {
                $klines = array_slice($data['data']['klines'], -$count);
                foreach ($klines as $kline) {
                    $parts = explode(',', $kline);
                    if (count($parts) >= 7) {
                        $klineData[] = [
                            'date' => $parts[0],
                            'open' => floatval($parts[1]),
                            'close' => floatval($parts[2]),
                            'high' => floatval($parts[3]),
                            'low' => floatval($parts[4]),
                            'volume' => floatval($parts[5]),
                            'amount' => floatval($parts[6])
                        ];
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log('获取K线数据失败: ' . $e->getMessage());
    }
    return $klineData;
}

/**
 * 计算技术指标
 */
function calculateTechnicalIndicators($klineData, $currentPrice, $includeRealTime = true) {
    $indicators = [];
    
    if (empty($klineData)) return $indicators;
    
    $closes = array_column($klineData, 'close');
    $highs = array_column($klineData, 'high');
    $lows = array_column($klineData, 'low');
    $dates = array_column($klineData, 'date');
    $n = count($closes);
    
    // 保存K线日期
    $indicators['K线日期'] = $dates;
    
    // 检查今天是否开盘
    $isMarketOpen = !isMarketClosed();
    
    // EMA5, EMA10, EMA20, EMA30, EMA60
    $indicators['EMA5'] = calculateEMA($closes, 5, $currentPrice, $includeRealTime && $isMarketOpen);
    $indicators['EMA10'] = calculateEMA($closes, 10, $currentPrice, $includeRealTime && $isMarketOpen);
    $indicators['EMA20'] = calculateEMA($closes, 20, $currentPrice, $includeRealTime && $isMarketOpen);
    $indicators['EMA30'] = calculateEMA($closes, 30, $currentPrice, $includeRealTime && $isMarketOpen);
    $indicators['EMA60'] = calculateEMA($closes, 60, $currentPrice, $includeRealTime && $isMarketOpen);
    
    // RSI14
    $indicators['RSI14'] = calculateRSI($closes, 14);
    
    // KDJ
    $kdj = calculateKDJ($highs, $lows, $closes, 9, 3, 3);
    $indicators['K'] = $kdj['K'];
    $indicators['D'] = $kdj['D'];
    $indicators['J'] = $kdj['J'];
    
    // 布林带（20日）
    $bollinger = calculateBollingerBands($closes, 20);
    $indicators['布林带上轨'] = $bollinger['upper'];
    $indicators['布林带中轨'] = $bollinger['middle'];
    $indicators['布林带下轨'] = $bollinger['lower'];
    $indicators['布林带历史'] = $bollinger['history'];
    
    // 当前价格相对于布林带的位置
    if ($bollinger['upper'] && $bollinger['lower'] && $currentPrice) {
        $bandWidth = $bollinger['upper'] - $bollinger['lower'];
        if ($bandWidth > 0) {
            $indicators['价格位置'] = round(($currentPrice - $bollinger['lower']) / $bandWidth * 100, 2) . '%';
        }
    }
    
    // MACD指标
    $macd = calculateMACD($closes);
    $indicators['MACD_DIF'] = $macd['DIF'];
    $indicators['MACD_DEA'] = $macd['DEA'];
    $indicators['MACD柱'] = $macd['MACD'];
    $indicators['MACD历史'] = $macd['history'];
    
    // CCI指标
    $cci = calculateCCI($highs, $lows, $closes, 14);
    $indicators['CCI'] = $cci['value'];
    $indicators['CCI历史'] = $cci['history'];
    
    return $indicators;
}

/**
 * 计算EMA
 */
function calculateEMA($data, $period, $currentPrice = null, $includeRealTime = false) {
    $n = count($data);
    if ($n < $period) return null;
    
    $multiplier = 2 / ($period + 1);
    
    // 如果包含实时价格且当前价格有效
    if ($includeRealTime && $currentPrice) {
        // 前面的天数少取一天，加上当前价格
        $startIndex = 1; // 从第2个元素开始，少取一天
        $ema = array_sum(array_slice($data, $startIndex, $period - 1)) / ($period - 1);
        // 计算到倒数第二个元素
        for ($i = $startIndex + $period - 1; $i < $n; $i++) {
            $ema = ($data[$i] - $ema) * $multiplier + $ema;
        }
        // 最后加上当前价格
        $ema = ($currentPrice - $ema) * $multiplier + $ema;
    } else {
        // 原来的计算方式
        $ema = array_sum(array_slice($data, 0, $period)) / $period;
        for ($i = $period; $i < $n; $i++) {
            $ema = ($data[$i] - $ema) * $multiplier + $ema;
        }
    }
    
    return round($ema, 4);
}

/**
 * 计算RSI
 */
function calculateRSI($data, $period = 14) {
    $n = count($data);
    if ($n < $period + 1) return null;
    $gains = [];
    $losses = [];
    for ($i = 1; $i <= $period; $i++) {
        $change = $data[$i] - $data[$i - 1];
        if ($change > 0) {
            $gains[] = $change;
            $losses[] = 0;
        } else {
            $gains[] = 0;
            $losses[] = abs($change);
        }
    }
    $avgGain = array_sum($gains) / $period;
    $avgLoss = array_sum($losses) / $period;
    for ($i = $period + 1; $i < $n; $i++) {
        $change = $data[$i] - $data[$i - 1];
        $gain = $change > 0 ? $change : 0;
        $loss = $change < 0 ? abs($change) : 0;
        $avgGain = ($avgGain * ($period - 1) + $gain) / $period;
        $avgLoss = ($avgLoss * ($period - 1) + $loss) / $period;
    }
    if ($avgLoss == 0) return 100;
    $rs = $avgGain / $avgLoss;
    return round(100 - (100 / (1 + $rs)), 2);
}

/**
 * 计算KDJ
 */
function calculateKDJ($highs, $lows, $closes, $n = 9, $m1 = 3, $m2 = 3) {
    $count = count($closes);
    if ($count < $n) return ['K' => null, 'D' => null, 'J' => null];
    $kValues = [];
    $dValues = [];
    $k = 50;
    $d = 50;
    for ($i = $n - 1; $i < $count; $i++) {
        $periodHigh = max(array_slice($highs, $i - $n + 1, $n));
        $periodLow = min(array_slice($lows, $i - $n + 1, $n));
        $close = $closes[$i];
        if ($periodHigh == $periodLow) {
            $rsv = 50;
        } else {
            $rsv = ($close - $periodLow) / ($periodHigh - $periodLow) * 100;
        }
        $k = ($k * ($m1 - 1) + $rsv) / $m1;
        $d = ($d * ($m2 - 1) + $k) / $m2;
        $kValues[] = $k;
        $dValues[] = $d;
    }
    $j = 3 * end($kValues) - 2 * end($dValues);
    return [
        'K' => round(end($kValues), 2),
        'D' => round(end($dValues), 2),
        'J' => round($j, 2)
    ];
}

/**
 * 计算布林带
 */
function calculateBollingerBands($data, $period = 20, $stdDev = 2) {
    $n = count($data);
    if ($n < $period) return ['upper' => null, 'middle' => null, 'lower' => null, 'history' => []];
    
    // 计算最新值
    $slicedData = array_slice($data, -$period);
    $middle = array_sum($slicedData) / $period;
    $variance = 0;
    foreach ($slicedData as $val) {
        $variance += pow($val - $middle, 2);
    }
    $std = sqrt($variance / $period);
    
    // 计算历史数据
    $history = [];
    for ($i = $period - 1; $i < $n; $i++) {
        $window = array_slice($data, $i - $period + 1, $period);
        $mid = array_sum($window) / $period;
        $var = 0;
        foreach ($window as $val) {
            $var += pow($val - $mid, 2);
        }
        $s = sqrt($var / $period);
        $history[] = [
            'upper' => round($mid + $stdDev * $s, 4),
            'middle' => round($mid, 4),
            'lower' => round($mid - $stdDev * $s, 4)
        ];
    }
    
    return [
        'upper' => round($middle + $stdDev * $std, 4),
        'middle' => round($middle, 4),
        'lower' => round($middle - $stdDev * $std, 4),
        'history' => $history
    ];
}

/**
 * 计算MACD指标
 */
function calculateMACD($data, $fastPeriod = 12, $slowPeriod = 26, $signalPeriod = 9) {
    $n = count($data);
    if ($n < $slowPeriod + $signalPeriod) return ['DIF' => null, 'DEA' => null, 'MACD' => null, 'history' => []];
    
    // 计算EMA的辅助函数（使用递推方法）
    function calculateEMASequential($data, $period) {
        $result = [];
        $n = count($data);
        if ($n < $period) return $result;
        
        $multiplier = 2 / ($period + 1);
        // 第一个EMA值使用简单平均值
        $ema = array_sum(array_slice($data, 0, $period)) / $period;
        $result[] = $ema;
        
        // 递推计算后续EMA值
        for ($i = $period; $i < $n; $i++) {
            $ema = ($data[$i] - $ema) * $multiplier + $ema;
            $result[] = $ema;
        }
        return $result;
    }
    
    // 计算12日EMA和26日EMA
    $ema12 = calculateEMASequential($data, $fastPeriod);
    $ema26 = calculateEMASequential($data, $slowPeriod);
    
    // 计算DIF (EMA12 - EMA26)
    $dif = [];
    $difStart = $slowPeriod - $fastPeriod; // EMA26比EMA12晚开始的天数
    for ($i = 0; $i < count($ema26); $i++) {
        $dif[] = $ema12[$i + $difStart] - $ema26[$i];
    }
    
    // 计算DEA (DIF的9日EMA)
    $dea = calculateEMASequential($dif, $signalPeriod);
    
    // 计算MACD柱
    $macd = [];
    $macdStart = $signalPeriod - 1;
    for ($i = 0; $i < count($dea); $i++) {
        $macd[] = ($dif[$i + $macdStart] - $dea[$i]) * 2;
    }
    
    // 计算历史MACD数据
    $history = [];
    $totalData = count($macd);
    $startIndex = max(0, $totalData - 60); // 最多保存60个数据点
    
    for ($i = $startIndex; $i < $totalData; $i++) {
        $history[] = [
            'DIF' => round($dif[$i + $macdStart], 4),
            'DEA' => round($dea[$i], 4),
            'MACD' => round($macd[$i], 4)
        ];
    }
    
    // 返回最新值
    $latestIndex = count($macd) - 1;
    return [
        'DIF' => round($dif[$latestIndex + $macdStart], 4),
        'DEA' => round($dea[$latestIndex], 4),
        'MACD' => round($macd[$latestIndex], 4),
        'history' => $history
    ];
}

/**
 * 计算CCI指标
 */
function calculateCCI($highs, $lows, $closes, $period = 14) {
    $n = count($closes);
    if ($n < $period) return ['value' => null, 'history' => []];
    
    $tp = []; // 典型价格
    for ($i = 0; $i < $n; $i++) {
        $tp[] = ($highs[$i] + $lows[$i] + $closes[$i]) / 3;
    }
    
    // 计算历史CCI数据
    $history = [];
    for ($i = $period - 1; $i < $n; $i++) {
        // 计算SMA(TP, period)
        $windowTp = array_slice($tp, $i - $period + 1, $period);
        $sma = array_sum($windowTp) / $period;
        
        // 计算平均绝对偏差(MAD)
        $mad = 0;
        foreach ($windowTp as $val) {
            $mad += abs($val - $sma);
        }
        $mad /= $period;
        
        // 计算CCI
        $cci = ($tp[$i] - $sma) / (0.015 * $mad);
        $history[] = round($cci, 2);
    }
    
    // 计算最新CCI值
    $latestSma = array_sum(array_slice($tp, -$period)) / $period;
    $latestMad = 0;
    for ($i = $n - $period; $i < $n; $i++) {
        $latestMad += abs($tp[$i] - $latestSma);
    }
    $latestMad /= $period;
    $latestCci = ($tp[$n - 1] - $latestSma) / (0.015 * $latestMad);
    
    return [
        'value' => round($latestCci, 2),
        'history' => $history
    ];
}

/**
 * 获取股票定增数据
 * 从东方财富获取定向增发数据，包括定增价格、解禁时间等
 * 使用股票名称搜索
 */
function getPrivatePlacementData($stockName) {
    $placementData = [];
    try {
        if (empty($stockName)) {
            return $placementData;
        }
        
        $encodedName = urlencode($stockName);
        $url = "https://datacenter-web.eastmoney.com/api/data/v1/get?callback=jQuery_callback&sortColumns=ISSUE_DATE&sortTypes=-1&pageSize=50&pageNumber=1&reportName=RPT_SEO_DETAIL&columns=ALL&quoteColumns=f2~01~SECURITY_CODE~NEW_PRICE&quoteType=0&source=WEB&client=WEB&filter=(SECURITY_NAME_ABBR+like+%22%25{$encodedName}%25%22)";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36");
        curl_setopt($ch, CURLOPT_REFERER, "https://data.eastmoney.com/");
        
        $raw = curl_exec($ch);
        curl_close($ch);
        
        if ($raw) {
            if (preg_match('/jQuery_callback\((.*)\)/', $raw, $matches)) {
                $jsonStr = $matches[1];
                $data = json_decode($jsonStr, true);
                if ($data && isset($data['result']['data'])) {
                    foreach ($data['result']['data'] as $item) {
                        $placementData[] = [
                            '定增价格' => $item['ISSUE_PRICE'] ?? null,
                            '发行数量' => $item['ISSUE_NUM'] ?? null,
                            '发行对象' => $item['ISSUE_OBJECT'] ?? null,
                            '发行日期' => $item['ISSUE_DATE'] ?? null,
                            '上市日期' => $item['ISSUE_LISTING_DATE'] ?? null,
                            '锁定期' => $item['LOCKIN_PERIOD'] ?? null,
                            '发行方式' => $item['ISSUE_WAY'] ?? null,
                            '增发类型' => $item['SEO_TYPE'] ?? null,
                            '募集资金总额' => $item['TOTAL_RAISE_FUNDS'] ?? null,
                            '净募集资金' => $item['NET_RAISE_FUNDS'] ?? null,
                            '发行前股本' => $item['ISSUE_SHARE_BEFORE'] ?? null,
                            '发行后股本' => $item['ISSUE_SHARE_AFTER'] ?? null,
                            '当前价格' => $item['NEW_PRICE'] ?? null,
                            '股票代码' => $item['SECURITY_CODE'] ?? null,
                            '股票名称' => $item['SECURITY_NAME_ABBR'] ?? null
                        ];
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log('获取定增数据失败: ' . $e->getMessage());
    }
    return $placementData;
}

/**
 * 计算筹码分布（基于K线数据）
 * 使用成交量加权方法估算筹码分布
 */
function calculateChipDistribution($klineData, $currentPrice) {
    if (empty($klineData) || !$currentPrice) {
        return [];
    }
    
    $chipDistribution = [];
    $priceRanges = [];
    
    $allPrices = [];
    foreach ($klineData as $kline) {
        $allPrices[] = $kline['low'];
        $allPrices[] = $kline['high'];
    }
    
    if (empty($allPrices)) return [];
    
    $minPrice = min($allPrices);
    $maxPrice = max($allPrices);
    
    if ($maxPrice <= $minPrice) return [];
    
    $priceStep = ($maxPrice - $minPrice) / 20;
    if ($priceStep <= 0) return [];
    
    for ($i = 0; $i <= 20; $i++) {
        $rangeStart = $minPrice + $i * $priceStep;
        $rangeEnd = $rangeStart + $priceStep;
        $priceRanges[round($rangeStart, 2) . '-' . round($rangeEnd, 2)] = [
            'start' => $rangeStart,
            'end' => $rangeEnd,
            'volume' => 0,
            'amount' => 0
        ];
    }
    
    foreach ($klineData as $kline) {
        $high = $kline['high'];
        $low = $kline['low'];
        $volume = $kline['volume'];
        $amount = $kline['amount'];
        
        if ($high <= $low) {
            $high = $low + 0.01;
        }
        
        foreach ($priceRanges as $key => &$range) {
            if ($low <= $range['end'] && $high >= $range['start']) {
                $overlapStart = max($low, $range['start']);
                $overlapEnd = min($high, $range['end']);
                $overlapRatio = ($overlapEnd - $overlapStart) / ($high - $low);
                
                $range['volume'] += $volume * $overlapRatio;
                $range['amount'] += $amount * $overlapRatio;
            }
        }
    }
    
    $totalVolume = array_sum(array_column($priceRanges, 'volume'));
    $totalAmount = array_sum(array_column($priceRanges, 'amount'));
    
    if ($totalVolume <= 0 || $totalAmount <= 0) return [];
    
    foreach ($priceRanges as $key => $range) {
        $avgPrice = $range['volume'] > 0 ? $range['amount'] / $range['volume'] : ($range['start'] + $range['end']) / 2;
        $chipDistribution[] = [
            '价格区间' => $key,
            '成交量占比' => round($range['volume'] / $totalVolume * 100, 2),
            '成交额占比' => round($range['amount'] / $totalAmount * 100, 2),
            '平均价格' => round($avgPrice, 2),
            '成交量' => round($range['volume'], 0),
            '成交额' => round($range['amount'], 0)
        ];
    }
    
    usort($chipDistribution, function($a, $b) {
        return $b['成交量占比'] <=> $a['成交量占比'];
    });
    
    return $chipDistribution;
}

/**
 * 推算主力成本区
 * 综合三种方法：定增成本、主升浪启动区、成交密集区
 */
function calculateMainForceCostZone($klineData, $currentPrice, $placementData = []) {
    $costAnalysis = [
        '定增成本区' => null,
        '主升浪启动区' => null,
        '成交密集区' => null,
        '综合主力成本' => null,
        '主力盈利比例' => null,
        '目标价推算' => null,
        '行情阶段判断' => null,
        '洗盘区间' => null
    ];
    
    if (empty($klineData) || !$currentPrice) {
        return $costAnalysis;
    }
    
    $closes = array_column($klineData, 'close');
    $highs = array_column($klineData, 'high');
    $lows = array_column($klineData, 'low');
    $volumes = array_column($klineData, 'volume');
    $n = count($closes);
    
    if ($n < 10) return $costAnalysis;
    
    $sixMonthsAgo = date('Y-m-d', strtotime('-6 months'));
    $validPlacements = [];
    $validPlacementPrices = [];
    
    if (!empty($placementData)) {
        foreach ($placementData as $p) {
            if (empty($p['定增价格']) || $p['定增价格'] <= 0) continue;
            
            $listingDate = $p['上市日期'] ?? $p['发行日期'] ?? null;
            if (empty($listingDate)) continue;
            
            $listingDateStr = is_numeric($listingDate) ? date('Y-m-d', ($listingDate - 25569) * 86400) : substr($listingDate, 0, 10);
            
            $lockPeriod = $p['锁定期'] ?? '';
            $maxLockMonths = 0;
            if (!empty($lockPeriod)) {
                if (preg_match('/(\d+(?:\.\d+)?)\s*年/', $lockPeriod, $matches)) {
                    $years = floatval(end($matches));
                    $maxLockMonths = max($maxLockMonths, $years * 12);
                }
                if (preg_match_all('/(\d+(?:\.\d+)?)/', $lockPeriod, $matches)) {
                    foreach ($matches[1] as $num) {
                        if (strpos($lockPeriod, '年') !== false) {
                            $maxLockMonths = max($maxLockMonths, floatval($num) * 12);
                        } elseif (strpos($lockPeriod, '月') !== false) {
                            $maxLockMonths = max($maxLockMonths, floatval($num));
                        }
                    }
                }
                if (strpos($lockPeriod, '年') !== false && $maxLockMonths == 0) {
                    if (preg_match('/(\d+(?:\.\d+)?)/', $lockPeriod, $m)) {
                        $maxLockMonths = floatval($m[1]) * 12;
                    }
                }
            }
            
            $unlockDate = date('Y-m-d', strtotime($listingDateStr . " +{$maxLockMonths} months"));
            
            if ($unlockDate >= $sixMonthsAgo) {
                $p['解禁日期'] = $unlockDate;
                $p['计算锁定期'] = $maxLockMonths . '个月';
                $validPlacements[] = $p;
                $validPlacementPrices[] = $p['定增价格'];
            }
        }
        
        if (!empty($validPlacements)) {
            $minPlacement = min($validPlacementPrices);
            $maxPlacement = max($validPlacementPrices);
            
            $costAnalysis['定增成本区'] = [
                '价格区间' => [$minPlacement, $maxPlacement],
                '平均成本' => round(array_sum($validPlacementPrices) / count($validPlacementPrices), 2),
                '定增次数' => count($validPlacements),
                '详情' => array_slice($validPlacements, 0, 3)
            ];
        }
    }
    
    $avgVolume = array_sum($volumes) / $n;
    $volumeThreshold = $avgVolume * 1.5;
    
    $breakoutPoints = [];
    for ($i = 5; $i < $n; $i++) {
        if ($volumes[$i] > $volumeThreshold) {
            $prevAvg = array_sum(array_slice($closes, $i - 5, 5)) / 5;
            if ($closes[$i] > $prevAvg * 1.03) {
                $breakoutPoints[] = [
                    'index' => $i,
                    'price' => $closes[$i],
                    'volume' => $volumes[$i]
                ];
            }
        }
    }
    
    if (!empty($breakoutPoints)) {
        $recentBreakouts = array_slice($breakoutPoints, -3);
        $breakoutPrices = array_column($recentBreakouts, 'price');
        $minBreakout = min($breakoutPrices);
        $maxBreakout = max($breakoutPrices);
        
        $costAnalysis['主升浪启动区'] = [
            '价格区间' => [round($minBreakout * 0.95, 2), round($maxBreakout * 1.05, 2)],
            '平均成本' => round(array_sum($breakoutPrices) / count($breakoutPrices), 2),
            '启动次数' => count($recentBreakouts)
        ];
    }
    
    $chipDistribution = calculateChipDistribution($klineData, $currentPrice);
    if (!empty($chipDistribution)) {
        $topChips = array_slice($chipDistribution, 0, 3);
        $denseZones = [];
        
        foreach ($topChips as $chip) {
            $priceRange = explode('-', $chip['价格区间']);
            if (count($priceRange) == 2) {
                $denseZones[] = [
                    '价格区间' => $chip['价格区间'],
                    '筹码占比' => $chip['成交量占比'],
                    '平均价格' => $chip['平均价格']
                ];
            }
        }
        
        $costAnalysis['成交密集区'] = $denseZones;
    }
    
    $costPoints = [];
    
    if (!empty($validPlacementPrices)) {
        $avgValidPlacement = array_sum($validPlacementPrices) / count($validPlacementPrices);
        $costPoints[] = $avgValidPlacement;
    }
    
    if (!empty($costAnalysis['主升浪启动区'])) {
        $costPoints[] = $costAnalysis['主升浪启动区']['平均成本'];
    }
    
    if (!empty($costAnalysis['成交密集区'])) {
        foreach ($costAnalysis['成交密集区'] as $zone) {
            if ($zone['平均价格'] < $currentPrice * 0.9) {
                $costPoints[] = $zone['平均价格'];
            }
        }
    }
    
    if (!empty($costPoints)) {
        $avgCost = array_sum($costPoints) / count($costPoints);
        $minCost = min($costPoints);
        $maxCost = max($costPoints);
        
        $costBasis = [];
        if (!empty($validPlacementPrices)) $costBasis[] = '近6月定增';
        if (!empty($costAnalysis['主升浪启动区'])) $costBasis[] = '主升浪启动';
        if (!empty($costAnalysis['成交密集区'])) $costBasis[] = '筹码峰';
        
        $costAnalysis['综合主力成本'] = [
            '成本区间' => [round($minCost, 2), round($maxCost, 2)],
            '平均成本' => round($avgCost, 2),
            '计算依据' => count($costPoints) . '个成本点（' . implode('+', $costBasis) . '）'
        ];
        
        if ($avgCost > 0) {
            $profitRatio = ($currentPrice - $avgCost) / $avgCost * 100;
            $costAnalysis['主力盈利比例'] = [
                '盈利比例' => round($profitRatio, 2) . '%',
                '当前价格' => $currentPrice,
                '主力成本' => round($avgCost, 2)
            ];
            
            $costAnalysis['目标价推算'] = [
                '普通行情' => round($avgCost * 1.5, 2),
                '强势行情' => round($avgCost * 2.0, 2),
                '超级行情' => round($avgCost * 3.0, 2)
            ];
            
            if ($profitRatio < 30) {
                $stage = '底部吸筹/建仓期';
                $stageDesc = '主力利润较低，处于建仓或吸筹阶段';
            } elseif ($profitRatio < 50) {
                $stage = '主升浪初期';
                $stageDesc = '主力利润适中，刚进入主升浪阶段';
            } elseif ($profitRatio < 80) {
                $stage = '主升浪中期';
                $stageDesc = '主力利润较高，处于主升浪中段';
            } elseif ($profitRatio < 150) {
                $stage = '主升浪后期';
                $stageDesc = '主力利润很高，可能即将进入派发阶段';
            } else {
                $stage = '高位派发期';
                $stageDesc = '主力利润极高，需警惕派发风险';
            }
            
            $costAnalysis['行情阶段判断'] = [
                '阶段' => $stage,
                '描述' => $stageDesc,
                '主力利润' => round($profitRatio, 2) . '%'
            ];
            
            $washLow = round($currentPrice * 0.92, 2);
            $washHigh = round($currentPrice * 0.98, 2);
            $limitWash = round($avgCost * 1.1, 2);
            
            $costAnalysis['洗盘区间'] = [
                '正常洗盘' => [$washLow, $washHigh],
                '极限洗盘' => round($limitWash, 2),
                '止损参考' => round($avgCost * 1.05, 2),
                '说明' => '如果跌破极限洗盘位，行情可能结束'
            ];
        }
    }
    
    return $costAnalysis;
}

/**
 * 获取股票分时图数据
 */
function getMinuteData($fullCode) {
    $minuteData = [];
    try {
        $code = preg_replace('/[a-z]/i', '', $fullCode);
        $market = strpos($fullCode, 'sh') !== false ? '1' : '0';
        
        // 东方财富分时图接口
        $url = "https://push2.eastmoney.com/api/qt/stock/trends2/get?secid={$market}.{$code}&fields1=f1,f2,f3,f4,f5,f6,f7,f8,f9,f10,f11,f12,f13&fields2=f51,f52,f53,f54,f55,f56,f57,f58";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36");
        
        $raw = curl_exec($ch);
        curl_close($ch);
        
        if ($raw) {
            $data = json_decode($raw, true);
            if ($data && isset($data['data']['trends'])) {
                $trends = $data['data']['trends'];
                foreach ($trends as $trend) {
                    $parts = explode(',', $trend);
                    if (count($parts) >= 5) {
                        $minuteData[] = [
                            'time' => $parts[0],
                            'price' => floatval($parts[1]),
                            'volume' => floatval($parts[2]),
                            'amount' => floatval($parts[3]),
                            'avgPrice' => floatval($parts[4])
                        ];
                    }
                }
            }
        }
    } catch (Exception $e) {
        error_log('获取分时图数据失败: ' . $e->getMessage());
    }
    return $minuteData;
}

/**
 * 获取东方财富基金数据
 */
function getEastMoneyFundData($code) {
    try {
        // 尝试从东方财富基金详情页面获取数据
        $url = "https://fundgz.1234567.com.cn/js/" . $code . ".js?rt=" . time();
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36");
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_REFERER, "https://fund.eastmoney.com/");
        
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || empty($raw)) {
            return null;
        }
        
        // 解析返回的JSONP格式：jsonpgz({...})
        if (preg_match('/jsonpgz\((.*)\);/', $raw, $matches)) {
            $data = json_decode($matches[1], true);
            if ($data) {
                return [
                    "名称" => $data['name'] ?? '',
                    "价格" => $data['gsz'] ?? '',
                    "昨收" => $data['dwjz'] ?? '',
                    "涨跌幅%" => $data['gszzl'] ?? '',
                    "成交量(手)" => '-',
                    "成交额(万)" => '-',
                    "换手率%" => '-',
                    "振幅%" => '-',
                    "市盈率(PE)" => '-',
                    "最高" => '-',
                    "最低" => '-',
                    "量比" => '-',
                    "流通市值" => '-',
                    "时间" => $data['gztime'] ?? date('Y-m-d H:i:s')
                ];
            }
        }
        
        return null;
    } catch (Exception $e) {
        error_log('获取东方财富基金数据失败: ' . $e->getMessage());
        return null;
    }
}

/**
 * 腾讯财经接口全量解析
 */
function getTencentDepth($symbol) {
    // 1. 清理输入（处理全角数字和空格）
    $symbol = str_replace(['　', ' '], '', $symbol);
    $symbol = mb_convert_kana($symbol, "n", "UTF-8"); // 转半角数字

    // 2. 自动判定市场前缀
    $fullCode = $symbol;
    if (preg_match('/^\d{6}$/', $symbol)) {
        // A股/基金逻辑
        $first = substr($symbol, 0, 1);
        // 5/6/9开头的是上海市场（包括ETF基金）
        $prefix = ($first === '5' || $first === '6' || $first === '9') ? 'sh' : 'sz';
        $fullCode = $prefix . $symbol;
    }

    $url = "https://qt.gtimg.cn/q=" . $fullCode;
    
    // 3. 使用 cURL 发起高仿真请求
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    // 必须加上这个 Referer，否则腾讯会返回空数据或报错
    curl_setopt($ch, CURLOPT_REFERER, "https://gu.qq.com/"); 
    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36");
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || empty($raw)) {
        // 腾讯接口失败，尝试东方财富基金接口
        if (preg_match('/^\d{6}$/', $symbol)) {
            $fundData = getEastMoneyFundData($symbol);
            if ($fundData) {
                return $fundData;
            }
        }
        return ["error" => "网络请求失败，HTTP状态码: " . $httpCode];
    }

    // 4. 转换编码 (腾讯接口是 GBK)
    $res = mb_convert_encoding($raw, 'UTF-8', 'GBK');

    // 5. 解析字符串 v_sz000001="...~...~...";
    if (strpos($res, '"') === false) {
        // 腾讯接口返回无效数据，尝试东方财富基金接口
        if (preg_match('/^\d{6}$/', $symbol)) {
            $fundData = getEastMoneyFundData($symbol);
            if ($fundData) {
                return $fundData;
            }
        }
        return ["error" => "未找到股票信息，请确认代码是否正确"];
    }
    
    $content = explode('"', $res)[1];
    if (empty($content)) {
        // 腾讯接口返回空数据，尝试东方财富基金接口
        if (preg_match('/^\d{6}$/', $symbol)) {
            $fundData = getEastMoneyFundData($symbol);
            if ($fundData) {
                return $fundData;
            }
        }
        return ["error" => "接口返回数据为空"];
    }
    
    $d = explode('~', $content);
    if (count($d) < 40) {
        // 腾讯接口字段不足，尝试东方财富基金接口
        if (preg_match('/^\d{6}$/', $symbol)) {
            $fundData = getEastMoneyFundData($symbol);
            if ($fundData) {
                return $fundData;
            }
        }
        return ["error" => "解析字段长度不足，原始数据: " . substr($content, 0, 20)];
    }

    // 处理时间格式
    $timeStr = $d[30];
    $formattedTime = $timeStr;
    if (strlen($timeStr) == 14) {
        // 格式化为 Y-m-d H:i:s
        $formattedTime = substr($timeStr, 0, 4) . '-' . substr($timeStr, 4, 2) . '-' . substr($timeStr, 6, 2) . ' ' . 
                        substr($timeStr, 8, 2) . ':' . substr($timeStr, 10, 2) . ':' . substr($timeStr, 12, 2);
    }

    return [
        "名称" => $d[1],
        "价格" => $d[3],
        "昨收" => $d[4],
        "涨跌幅%" => $d[32],
        "成交量(手)" => $d[6],
        "成交额(万)" => $d[37],
        "换手率%" => $d[38],
        "振幅%" => $d[43],
        "市盈率(PE)" => $d[39],
        "最高" => $d[33],
        "最低" => $d[34],
        "量比" => $d[49] ?: '1.0',
        "流通市值" => $d[44],
        "时间" => $formattedTime
    ];
}

/**
 * 尝试获取股票对应的港股信息
 * 港股代码通常为5位数字，腾讯财经接口使用 hk 前缀
 */
function getHongKongStockInfo($stockName) {
    try {
        // 常见的港股代码前缀，用于尝试匹配
        $hkCodePrefixes = ['00700', '00998', '00388', '01299', '00883', '00939', '01398', '02318', '00288', '01810'];
        
        // 尝试每个可能的港股代码
        foreach ($hkCodePrefixes as $hkCode) {
            $fullHkCode = 'hk' . $hkCode;
            $hkData = getTencentDepth($fullHkCode);
            
            // 检查是否成功获取数据且名称匹配
            if (!isset($hkData['error']) && isset($hkData['名称'])) {
                // 简单的名称匹配逻辑：港股名称通常包含A股名称的关键字
                if (strpos($hkData['名称'], $stockName) !== false || strpos($stockName, $hkData['名称']) !== false) {
                    return $hkData;
                }
            }
        }
        
        // 尝试通过股票名称搜索港股
        // 使用东方财富的搜索接口尝试找到港股
        $url = 'https://searchapi.eastmoney.com/api/suggest/get?input=' . urlencode($stockName) . '&type=14&count=20';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_REFERER, 'https://www.eastmoney.com/');
        
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode === 200 && !empty($raw)) {
            $data = json_decode($raw, true);
            if ($data && isset($data['QuotationCodeTable']['Data'])) {
                foreach ($data['QuotationCodeTable']['Data'] as $item) {
                    if (empty($item['Name']) || empty($item['Code'])) continue;
                    
                    // 检查是否是港股（通常代码长度为5位）
                    if (strlen($item['Code']) == 5) {
                        $hkCode = 'hk' . $item['Code'];
                        $hkData = getTencentDepth($hkCode);
                        if (!isset($hkData['error'])) {
                            return $hkData;
                        }
                    }
                }
            }
        }
        
        return null;
    } catch (Exception $e) {
        error_log('获取港股信息失败: ' . $e->getMessage());
        return null;
    }
}

// 发送进度信息：开始获取股票信息
sendProgress(10, '正在获取股票信息...');

// 计算fullCode变量
$fullCode = $symbol;
if (preg_match('/^\d{6}$/', $symbol)) {
    // A股/基金逻辑
    $first = substr($symbol, 0, 1);
    // 5/6/9开头的是上海市场（包括ETF基金）
    $prefix = ($first === '5' || $first === '6' || $first === '9') ? 'sh' : 'sz';
    $fullCode = $prefix . $symbol;
}

$marketData = getTencentDepth($symbol);

// 如果有错误键名，直接输出并终止
if (isset($marketData['error'])) {
    echo "TEXT:❌ 错误: " . $marketData['error'] . "\n";
    exit;
}

// 尝试获取对应的港股信息
$hkData = null;
if (isset($marketData['名称'])) {
    $stockName = $marketData['名称'];
    $hkData = getHongKongStockInfo($stockName);
}

// 发送进度信息：股票信息获取完成
sendProgress(30, '股票信息获取完成，正在获取上证指数...');

// 获取上证指数信息
$shIndexData = getTencentDepth('sh000001');
if (isset($shIndexData['error'])) {
    error_log('获取上证指数失败: ' . $shIndexData['error']);
    $shIndexData = null;
}

// 发送进度信息：上证指数获取完成，正在获取板块信息...
sendProgress(35, '上证指数获取完成，正在获取板块信息...');

// 获取板块信息（可能失败，特别是对于开放式基金）
$sectorData = [];
try {
    $sectorData = getStockSector($fullCode);
} catch (Exception $e) {
    error_log('获取板块信息失败: ' . $e->getMessage());
    $sectorData = [];
}

// 发送进度信息：板块信息获取完成，正在获取资金流向...
sendProgress(40, '板块信息获取完成，正在获取资金流向...');

// 获取资金流向（可能失败）
$moneyFlowData = [];
try {
    $moneyFlowData = getMoneyFlow($fullCode);
} catch (Exception $e) {
    error_log('获取资金流向失败: ' . $e->getMessage());
    $moneyFlowData = [];
}

// 发送进度信息：资金流向获取完成，正在获取分时图数据...
sendProgress(45, '资金流向获取完成，正在获取分时图数据...');

// 获取分时图数据（可能失败）
$minuteData = [];
try {
    $minuteData = getMinuteData($fullCode);
} catch (Exception $e) {
    error_log('获取分时图数据失败: ' . $e->getMessage());
    $minuteData = [];
}

// 发送进度信息：分时图数据获取完成，正在获取K线数据...
sendProgress(47, '分时图数据获取完成，正在获取K线数据...');

// 获取K线数据（可能失败）
$klineData = [];
try {
    $klineData = getKLineData($fullCode, 60);
} catch (Exception $e) {
    error_log('获取K线数据失败: ' . $e->getMessage());
    $klineData = [];
}

// 计算技术指标（可能失败）
$technicalIndicators = [];
try {
    $currentPrice = floatval($marketData['价格'] ?? 0);
    // 获取用户设置：是否包含实时价格的均线
    $includeRealTime = isset($_POST['includeRealTime']) ? $_POST['includeRealTime'] === 'true' : true;
    $technicalIndicators = calculateTechnicalIndicators($klineData, $currentPrice, $includeRealTime);
} catch (Exception $e) {
    error_log('计算技术指标失败: ' . $e->getMessage());
    $technicalIndicators = [];
}

// 发送进度信息：技术指标计算完成，正在获取股票最新资讯...
sendProgress(50, '技术指标计算完成，正在获取主力成本区数据...');

// 获取主力成本区数据（需要用户勾选或只获取数据模式）
$mainForceCostData = [];
$mainForceCostDebug = '';
$enableMainForceCost = isset($_POST['enableMainForceCost']) && $_POST['enableMainForceCost'] === 'true';
$dataOnlyMode = isset($_POST['dataOnly']) && $_POST['dataOnly'] === 'true';

if (!$enableMainForceCost && !$dataOnlyMode) {
    $mainForceCostDebug = '未启用主力成本区分析（请勾选选项或使用只获取数据模式）';
} elseif (isOpenEndFund($fullCode)) {
    $mainForceCostDebug = '开放式基金不支持主力成本区分析';
} else {
    try {
        $stockNameForPlacement = $marketData['名称'] ?? '';
        if (empty($stockNameForPlacement)) {
            $mainForceCostDebug = '无法获取股票名称';
        } elseif (empty($klineData) || count($klineData) < 10) {
            $mainForceCostDebug = 'K线数据不足（需要至少10条数据，当前：' . count($klineData) . '条）';
        } elseif (empty($currentPrice)) {
            $mainForceCostDebug = '无法获取当前价格';
        } else {
            $placementData = getPrivatePlacementData($stockNameForPlacement);
            $mainForceCostData = calculateMainForceCostZone($klineData, $currentPrice, $placementData);
            
            if (empty($mainForceCostData['综合主力成本'])) {
                $debugParts = [];
                if (empty($mainForceCostData['主升浪启动区'])) $debugParts[] = '无主升浪启动点';
                if (empty($mainForceCostData['成交密集区'])) $debugParts[] = '无成交密集区';
                $mainForceCostDebug = '无法计算综合主力成本：' . implode('、', $debugParts) . '（需要至少一个有效成本点）';
            }
        }
    } catch (Exception $e) {
        error_log('获取主力成本区数据失败: ' . $e->getMessage());
        $mainForceCostDebug = '计算失败：' . $e->getMessage();
        $mainForceCostData = [];
    }
}

if (!empty($mainForceCostDebug)) {
    $mainForceCostData['_debug_info'] = $mainForceCostDebug;
}

// 发送进度信息：主力成本区数据获取完成，正在获取股票最新资讯...
sendProgress(52, '主力成本区数据获取完成，正在获取股票最新资讯...');

// 获取股票最新资讯列表（可能失败）
$stockNews = [];
try {
    $stockName = $marketData['名称'] ?? '';
    $stockNews = getStockNews($fullCode, $stockName, $fullContent);
} catch (Exception $e) {
    error_log('获取最新资讯失败: ' . $e->getMessage());
    $stockNews = [];
}

// 发送进度信息：资讯获取完成
sendProgress(55, '股票最新资讯获取完成，正在准备AI分析...');

// 整合所有数据
$enhancedMarketData = array_merge($marketData, [
    '板块信息' => $sectorData,
    '资金流向' => $moneyFlowData,
    '技术指标' => $technicalIndicators,
    '港股信息' => $hkData,
    '主力成本区' => $mainForceCostData
]);

// 1. 发送行情数据表格给前端
echo "DATA:" . json_encode(['stockData' => $enhancedMarketData, 'indexData' => $shIndexData, 'newsData' => $stockNews, 'hkData' => $hkData, 'mainForceCostData' => $mainForceCostData], JSON_UNESCAPED_UNICODE) . "\n";
sseFlush();

// 检查是否是"只获取数据"模式
$dataOnly = isset($_POST['dataOnly']) && $_POST['dataOnly'] === 'true';

if ($dataOnly) {
    // 只获取数据模式，不进行AI分析
    sendProgress(100, '数据获取完成！（已跳过AI分析）');
    echo "PROGRESS:" . json_encode(['percentage' => 100, 'message' => '数据获取完成！', 'hide' => true], JSON_UNESCAPED_UNICODE) . "\n";
    sseFlush();
    
    // 不扣除积分（只获取数据模式不消耗积分）
    // 不保存分析记录到数据库
    
    exit;
}

// 发送进度信息：开始AI分析
sendProgress(60, '正在发送请求给AI进行分析...');

// 2. 调用 AI 分析 (使用数据流模式)
$systemPrompt = "你是一位冷静的对冲基金经理，请直接返回HTML格式的分析报告内容，不要返回完整的HTML结构（不要包含<!DOCTYPE html>、<html>、<head>、<body>等标签），只返回内容部分的HTML代码。\n\nHTML输出要求：\n1. 只返回内容部分的HTML代码，使用清晰的结构，包含适当的标题、段落和表格\n2. 标题使用<h2>和<h3>标签，不要添加内联CSS样式\n3. 段落使用<p>标签，保持良好的行间距\n4. 表格使用<table>标签，不要添加内联CSS样式\n5. 重点内容使用<strong>标签加粗\n6. 不要使用任何<script>或<style>标签\n7. 包含以下部分：\n   - 分析过程（大盘、板块、走势、量价、技术指标）\n   - 结论\n   - 投资建议（表格形式）\n   - 决策理由（含风险控制）\n\n请参考提供的技术指标（EMA均线、RSI、KDJ、布林带）、资金流向数据进行更深入的分析。如果是ETF基金，请分析其跟踪标的的表现。\n\n重要提示：所有数据中出现的\"-\"符号表示该数据未能成功获取，请在分析时完全忽略这些数据，不要提及或试图解释\"-\"的含义。";

// 构建用户提示，包含股票/基金数据、上证指数数据、最新资讯和分析要求
$currentDateTime = date('Y-m-d H:i:s');
$userPrompt = "分析时间：{$currentDateTime}\n\n";
$userPrompt .= "数据源：\n";
$userPrompt .= "1. 实时盘口：" . json_encode($marketData, JSON_UNESCAPED_UNICODE) . "\n";
if ($hkData) {
    $userPrompt .= "2. 港股信息：" . json_encode($hkData, JSON_UNESCAPED_UNICODE) . "\n";
}
if ($shIndexData) {
    $userPrompt .= "3. 上证指数：" . json_encode($shIndexData, JSON_UNESCAPED_UNICODE) . "\n";
}
if (!empty($sectorData)) {
    $userPrompt .= "4. 所属板块信息：" . json_encode($sectorData, JSON_UNESCAPED_UNICODE) . "\n";
}
if (!empty($moneyFlowData)) {
    $userPrompt .= "5. 资金流向数据：" . json_encode($moneyFlowData, JSON_UNESCAPED_UNICODE) . "\n";
}
if (!empty($technicalIndicators)) {
    $userPrompt .= "6. 技术指标：" . json_encode($technicalIndicators, JSON_UNESCAPED_UNICODE) . "\n";
}
if (!empty($minuteData)) {
    $userPrompt .= "7. 分时图数据：" . json_encode($minuteData, JSON_UNESCAPED_UNICODE) . "\n";
}
// 构建最新资讯和详细资讯
$userPrompt .= "8. 最新资讯：" . json_encode($stockNews, JSON_UNESCAPED_UNICODE) . "\n";

// 添加主力成本区数据
if (!empty($mainForceCostData)) {
    $userPrompt .= "9. 主力成本区分析数据：" . json_encode($mainForceCostData, JSON_UNESCAPED_UNICODE) . "\n";
}

// 添加近两日包含股票名称或代码的资讯详情
$hasNewsContent = false;
foreach ($stockNews as $newsItem) {
    if (isset($newsItem['content']) && !empty($newsItem['content'])) {
        if (!$hasNewsContent) {
            $userPrompt .= "\n8. 重要资讯详情（近两日包含股票信息的资讯）：\n";
            $hasNewsContent = true;
        }
        $userPrompt .= "标题：{$newsItem['title']}\n";
        $userPrompt .= "时间：{$newsItem['time']}\n";
        $userPrompt .= "内容：{$newsItem['content']}\n\n";
    }
}

// 处理用户持仓信息
if (empty($shares) || empty($cost)) {
    $userPrompt .= "\n用户持仓：暂无持仓信息，为空仓用户提供分析。剩余资金 {$cash} 元。\n";
} else {
    $userPrompt .= "\n用户持仓：持有数量 {$shares} 股/份，可卖出数量 {$sellable_shares} 股/份，成本 {$cost} 元，剩余资金 {$cash} 元。\n";
}

// 处理用户风格设置
$userPrompt .= "交易风格：{$tradingStyle}\n";
$userPrompt .= "持仓风格：{$holdingStyle}\n\n";

// 处理复盘数据
if (!empty($parsedReviewData)) {
    $userPrompt .= "\n用户复盘数据：\n";
    foreach ($parsedReviewData as $index => $record) {
        $type = $record['type'] === 'buy' ? '买入' : '卖出';
        $price = $record['price'];
        $amount = isset($record['amount']) && !empty($record['amount']) ? $record['amount'] : '-';
        $time = $record['time'];
        $remaining = isset($record['remaining']) && !empty($record['remaining']) ? $record['remaining'] : '-';
        $userPrompt .= ($index + 1) . ". {$type}，价格：{$price} 元，数量：{$amount} 股/份，时间：{$time}，剩余数量：{$remaining}\n";
    }
    $userPrompt .= "\n";
}

// 处理特殊事件
if (!empty($specialEvent)) {
    $userPrompt .= "\n特殊事件说明：{$specialEvent}\n";
    $userPrompt .= "请结合此特殊事件对股票走势的影响进行分析。\n\n";
}

$userPrompt .= "分析要求：\n";
$userPrompt .= "1. 请考虑节假日因素对价格的影响\n";
$userPrompt .= "2. 请分析板块表现（如有）\n";
$userPrompt .= "3. 请分析资金流向（主力、超大单、大单、中单、小单）对价格的影响\n";
$userPrompt .= "4. 请深入分析技术指标（EMA均线系统、RSI强弱指标、KDJ随机指标、布林带通道），包括超买超卖、金叉死叉、价格位置等\n";
$userPrompt .= "5. 请分析最新资讯（如有）\n";
$userPrompt .= "6. 如果有港股信息，请分析港股表现与A股的关联性，包括价格差异、涨跌幅对比等\n";
$userPrompt .= "7. 在分析过程中综合考虑大盘走势、板块表现、走势、量价关系、技术指标、资金流向等因素\n";
$userPrompt .= "8. 提供清晰的结论和投资建议，包括操作方向、仓位建议、价格区间、目标价/止损价等\n";
$userPrompt .= "9. 如果是ETF基金，请分析其跟踪标的的市场表现\n";
$userPrompt .= "10. 重要：所有数据中出现的\"-\"符号表示该数据未能成功获取，请在分析时完全忽略这些数据，不要提及或试图解释\"-\"的含义\n";
$userPrompt .= "11. 请为当前股票打分，评分范围为-100到+100，+100表示极度看好，-100表示极度看空\n";
$userPrompt .= "12. 请给出一段简洁的评价，总结股票的当前状态和前景\n";
$userPrompt .= "13. 如果股票今日跌停或涨停，请分析第二日连板的概率及原因\n";
$userPrompt .= "14. 如果股票今日没有跌停或涨停，请给出第二日的涨跌预测\n";
$userPrompt .= "15. 请根据用户的交易风格（{$tradingStyle}）和持仓风格（{$holdingStyle}）调整分析建议的激进程度和持仓周期建议\n";

// 如果有主力成本区数据，添加主力成本区分析要求
if (!empty($mainForceCostData)) {
    $userPrompt .= "16. 请深入分析主力成本区数据，包括以下内容（创建独立的分析板块）：\n";
    $userPrompt .= "   a. 定增成本分析：如果有定增数据，分析机构定增成本与当前价格的差距，判断机构盈亏情况\n";
    $userPrompt .= "   b. 主升浪启动区分析：识别本轮行情的启动位置，推算主力建仓成本\n";
    $userPrompt .= "   c. 筹码分布分析：分析成交密集区（筹码峰），判断筹码集中度\n";
    $userPrompt .= "   d. 综合主力成本推算：结合三种方法估算机构真实成本区间\n";
    $userPrompt .= "   e. 主力盈利分析：计算当前主力盈利比例，判断行情所处阶段（建仓期/主升浪初期/中期/后期/派发期）\n";
    $userPrompt .= "   f. 目标价推算：基于主力成本推算普通行情、强势行情、超级行情的目标价\n";
    $userPrompt .= "   g. 洗盘区间分析：给出正常洗盘区间和极限洗盘位，以及止损参考\n";
    $userPrompt .= "   h. 投资建议：基于主力成本分析给出具体的操作建议\n";
}

// 如果有选择战法，添加战法分析要求
if (!empty($selectedStrategies)) {
    $strategyIndex = !empty($mainForceCostData) ? 17 : 16;
    $userPrompt .= $strategyIndex . ". 请针对以下选定的战法进行单独分析，每个战法分析一个板块：\n";
    foreach ($selectedStrategies as $index => $strategy) {
        $userPrompt .= ($index + 1) . ". {$strategy}\n";
    }
    $userPrompt .= "请为每个选定的战法创建一个独立的分析板块，包括：\n";
    $userPrompt .= "- 战法适用性评估\n";
    $userPrompt .= "- 当前行情下的战法信号\n";
    $userPrompt .= "- 基于该战法的具体操作建议\n";
    $userPrompt .= "- 风险提示\n";
}

// 如果有复盘数据，添加复盘分析要求
if (!empty($parsedReviewData)) {
    $reviewIndex = 17;
    if (!empty($mainForceCostData)) $reviewIndex++;
    if (!empty($selectedStrategies)) $reviewIndex++;
    $userPrompt .= $reviewIndex . ". 请根据用户提供的复盘数据，结合当日的分时图、成交量、MA5/MA10、RSI、MACD、板块涨跌幅和大盘走势等数据，进行全面分析：\n";
    $userPrompt .= "   a. 是否符合趋势结构\n";
    $userPrompt .= "   b. 是否属于情绪化操作\n";
    $userPrompt .= "   c. 盈亏比是否合理\n";
    $userPrompt .= "   d. 是否有更优执行方案\n";
    $userPrompt .= "   e. 给出纪律评分（0-100）\n";
    $userPrompt .= "   f. 提供详细的改进建议\n";
}

$postData = [
    "model" => $model,
    "messages" => [
        ["role" => "system", "content" => $systemPrompt],
        ["role" => "user", "content" => $userPrompt]
    ],
    "stream" => true
];

$ch = curl_init("https://api.deepseek.com/chat/completions");
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Content-Type: application/json",
    "Authorization: Bearer " . $apiKey
]);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
// 用于存储AI分析结果
$aiResult = '';

// 设置curl回调函数以捕获AI分析结果
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $data) use (&$aiResult) {
    $lines = explode("\n", $data);
    foreach ($lines as $line) {
        if (strpos($line, 'data: ') === 0) {
            $jsonStr = trim(substr($line, 6));
            if ($jsonStr === '[DONE]') break;
            $decoded = json_decode($jsonStr, true);
            $content = $decoded['choices'][0]['delta']['content'] ?? '';
            if ($content) {
                $aiResult .= $content;
                echo "TEXT:" . $content . "\n";
                sseFlush();
            }
        }
    }
    return strlen($data);
});

curl_exec($ch);
curl_close($ch);

// 再次从数据库获取最新的用户信息，确保积分正确
$conn = Database::getConnection();
$userId = $user['id'];
$sql = "SELECT * FROM users WHERE id = $userId";
$result = $conn->query($sql);
if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
    logDebug('扣除积分前用户积分: ' . $user['points'], $user['id']);
}

// 扣除用户积分
logDebug('开始扣除用户积分: user_id=' . $user['id'] . ', points=' . $analysisCost . ', type=deduct, reason=股票分析扣除', $user['id']);
$deductResult = Database::updateUserPoints($user['id'], $analysisCost, 'deduct', '股票分析扣除');
logDebug('积分扣除结果: ' . ($deductResult ? '成功' : '失败'), $user['id']);

// 记录系统日志
logSystemAction('stock_analysis', "用户 {$user['username']} (ID: {$user['id']}) 分析了股票 {$symbol}", $user['id']);

// 保存分析记录到数据库
logDebug('开始保存分析记录到数据库', $user['id']);
$analysisId = Database::saveStockAnalysis(
    $user['id'], 
    $symbol, 
    $shares, 
    $sellable_shares, 
    $cost, 
    $cash, 
    $model, 
    json_encode($marketData), 
    json_encode($shIndexData), 
    json_encode($stockNews), 
    $aiResult,
    json_encode($sectorData),
    json_encode($moneyFlowData),
    json_encode($technicalIndicators),
    json_encode($parsedReviewData),
    null, // fund_director_content
    json_encode($minuteData),
    json_encode($mainForceCostData)
);
logDebug('保存分析记录结果: ' . ($analysisId ? '成功，ID: ' . $analysisId : '失败'), $user['id']);

// 发送分析ID给前端
echo "ANALYSIS_ID:" . $analysisId . "\n";
sseFlush();



// 更新用户会话信息
session_start();
$conn = Database::getConnection();
$userId = $user['id'];
$sql = "SELECT * FROM users WHERE id = $userId";
$result = $conn->query($sql);
$_SESSION['user'] = $result->fetch_assoc();
session_write_close();

// 发送进度信息：分析完成
sendProgress(100, '分析完成！');

// 隐藏进度条
echo "PROGRESS:" . json_encode(['percentage' => 100, 'message' => '分析完成！', 'hide' => true], JSON_UNESCAPED_UNICODE) . "\n";
sseFlush();