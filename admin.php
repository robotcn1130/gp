<?php
// 后台管理处理文件
require_once 'config.php';
require_once 'includes/database.php';

// 启动会话
session_start();

// 设置响应头
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// 处理 OPTIONS 请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

// 获取请求数据
$data = $_SERVER['REQUEST_METHOD'] === 'POST' ? json_decode(file_get_contents('php://input'), true) : $_GET;
$action = $data['action'] ?? '';

// 处理不同的操作
switch ($action) {
    case 'login':
        handleAdminLogin($data);
        break;
        
    case 'checklogin':
        checkAdminLogin();
        break;
        
    case 'logout':
        handleAdminLogout();
        break;
        
    case 'getusers':
        getUsers($data);
        break;
        
    case 'addpoints':
        addUserPoints($data);
        break;
        
    case 'getsettings':
        getSystemSettings();
        break;
        
    case 'savesettings':
        saveSystemSettings($data);
        break;
        
    case 'getrechargesettings':
        getRechargeSettings();
        break;
        
    case 'saverechargesettings':
        saveRechargeSettings($data);
        break;
        
    case 'changepassword':
        changeAdminPassword($data);
        break;
        
    case 'getpointshistory':
        getPointsHistory($data);
        break;
        
    case 'getlogs':
        getSystemLogs();
        break;
        
    case 'getdeepseekbalance':
        getDeepSeekBalance();
        break;
        
    default:
        echo json_encode(['error' => '未知操作']);
        break;
}

// 处理管理员登录
function handleAdminLogin($data) {
    $username = $data['username'] ?? '';
    $password = $data['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        echo json_encode(['success' => false, 'error' => '请输入用户名和密码']);
        return;
    }
    
    $admin = Database::verifyAdminLogin($username, $password);
    if ($admin) {
        $_SESSION['admin'] = $admin;
        // 记录登录成功日志
        logSystemAction('admin_login', "管理员 {$username} 登录成功");
        echo json_encode(['success' => true, 'admin' => $admin]);
    } else {
        // 记录登录失败日志
        logSystemAction('admin_login_failed', "管理员 {$username} 登录失败");
        echo json_encode(['success' => false, 'error' => '用户名或密码错误']);
    }
}

// 检查管理员登录状态
function checkAdminLogin() {
    $admin = isset($_SESSION['admin']) ? $_SESSION['admin'] : null;
    if ($admin) {
        echo json_encode(['logged' => true, 'admin' => $admin]);
    } else {
        echo json_encode(['logged' => false]);
    }
}

// 处理管理员退出登录
function handleAdminLogout() {
    $admin = isset($_SESSION['admin']) ? $_SESSION['admin'] : null;
    if ($admin) {
        logSystemAction('admin_logout', "管理员 {$admin['username']} 退出登录");
    }
    unset($_SESSION['admin']);
    session_destroy();
    echo json_encode(['success' => true]);
}

// 获取用户列表
function getUsers($data) {
    // 检查管理员登录状态
    if (!isset($_SESSION['admin'])) {
        echo json_encode(['error' => '请先登录']);
        return;
    }
    
    $page = isset($data['page']) ? (int)$data['page'] : 1;
    $perpage = isset($data['perpage']) ? (int)$data['perpage'] : 10;
    $offset = ($page - 1) * $perpage;
    
    // 获取用户总数
    $total = Database::getUserCount();
    
    // 获取用户列表
    $users = Database::getAllUsers($perpage, $offset);
    
    echo json_encode(['users' => $users, 'total' => $total, 'page' => $page, 'perpage' => $perpage]);
}

// 给用户增加积分
function addUserPoints($data) {
    // 检查管理员登录状态
    if (!isset($_SESSION['admin'])) {
        echo json_encode(['success' => false, 'error' => '请先登录']);
        return;
    }
    
    $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    $points = isset($data['points']) ? (int)$data['points'] : 0;
    $reason = isset($data['reason']) ? $data['reason'] : '管理员增加积分';
    $adminId = $_SESSION['admin']['id'];
    
    if (!$userId || $points <= 0) {
        echo json_encode(['success' => false, 'error' => '请输入有效的用户ID和积分数量']);
        return;
    }
    
    $success = Database::updateUserPoints($userId, $points, 'add', $reason, $adminId);
    if ($success) {
        // 记录增加积分日志
        logSystemAction('user_points_add', "管理员给用户ID {$userId} 增加 {$points} 积分，原因: {$reason}");
        echo json_encode(['success' => true]);
    } else {
        // 记录增加积分失败日志
        logSystemAction('user_points_add_failed', "管理员给用户ID {$userId} 增加 {$points} 积分失败，原因: {$reason}");
        echo json_encode(['success' => false, 'error' => '增加积分失败']);
    }
}

// 获取系统设置
function getSystemSettings() {
    // 检查管理员登录状态
    if (!isset($_SESSION['admin'])) {
        echo json_encode(['error' => '请先登录']);
        return;
    }
    
    $settings = [
        'deepseek_api_key' => Database::getSystemSetting('deepseek_api_key'),
        'default_model' => Database::getSystemSetting('default_model'),
        'new_user_points' => Database::getSystemSetting('new_user_points'),
        'analysis_cost_chat' => Database::getSystemSetting('analysis_cost_chat'),
        'analysis_cost_reasoner' => Database::getSystemSetting('analysis_cost_reasoner'),
        'system_name' => Database::getSystemSetting('system_name')
    ];
    
    echo json_encode(['settings' => $settings]);
}

// 管理员修改密码
function changeAdminPassword($data) {
    // 检查管理员登录状态
    if (!isset($_SESSION['admin'])) {
        echo json_encode(['success' => false, 'error' => '请先登录']);
        return;
    }
    
    $oldPassword = $data['old_password'] ?? '';
    $newPassword = $data['new_password'] ?? '';
    $confirmPassword = $data['confirm_password'] ?? '';
    
    if (empty($oldPassword) || empty($newPassword) || empty($confirmPassword)) {
        echo json_encode(['success' => false, 'error' => '所有字段不能为空']);
        return;
    }
    
    if ($newPassword !== $confirmPassword) {
        echo json_encode(['success' => false, 'error' => '两次输入的密码不一致']);
        return;
    }
    
    $admin = $_SESSION['admin'];
    $adminId = $admin['id'];
    
    // 验证旧密码
    $conn = Database::getConnection();
    $sql = "SELECT password FROM admin_users WHERE id = $adminId";
    $result = $conn->query($sql);
    $adminData = $result->fetch_assoc();
    
    if (!password_verify($oldPassword, $adminData['password'])) {
        // 记录修改密码失败日志
        logSystemAction('admin_password_change_failed', "管理员 {$admin['username']} 修改密码失败，原因：原密码错误");
        echo json_encode(['success' => false, 'error' => '原密码错误']);
        return;
    }
    
    // 更新密码
    $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    $sql = "UPDATE admin_users SET password = '$hashedPassword' WHERE id = $adminId";
    
    if ($conn->query($sql)) {
        // 更新session中的管理员信息
        $admin['password'] = $hashedPassword;
        $_SESSION['admin'] = $admin;
        
        // 记录修改密码成功日志
        logSystemAction('admin_password_change', "管理员 {$admin['username']} 成功修改了密码");
        echo json_encode(['success' => true]);
    } else {
        // 记录修改密码失败日志
        logSystemAction('admin_password_change_failed', "管理员 {$admin['username']} 修改密码失败，原因：数据库错误");
        echo json_encode(['success' => false, 'error' => '密码修改失败，请重试']);
    }
}

// 保存系统设置
function saveSystemSettings($data) {
    // 检查管理员登录状态
    if (!isset($_SESSION['admin'])) {
        echo json_encode(['success' => false, 'error' => '请先登录']);
        return;
    }
    
    $settings = $data['settings'] ?? [];
    
    foreach ($settings as $key => $value) {
        Database::updateSystemSetting($key, $value);
    }
    
    // 记录系统日志
    logSystemAction('system_settings_save', "管理员修改了系统设置");
    
    echo json_encode(['success' => true]);
}

// 获取充值设置
function getRechargeSettings() {
    // 检查管理员登录状态
    if (!isset($_SESSION['admin'])) {
        echo json_encode(['error' => '请先登录']);
        return;
    }
    
    $qrcode = Database::getSystemSetting('recharge_qrcode');
    $notes = Database::getSystemSetting('recharge_notes');
    
    echo json_encode(['qrcode' => $qrcode, 'notes' => $notes]);
}

// 保存充值设置
function saveRechargeSettings($data) {
    // 检查管理员登录状态
    if (!isset($_SESSION['admin'])) {
        echo json_encode(['success' => false, 'error' => '请先登录']);
        return;
    }
    
    $qrcode = $data['qrcode'] ?? '';
    $notes = $data['notes'] ?? '';
    
    Database::updateSystemSetting('recharge_qrcode', $qrcode);
    Database::updateSystemSetting('recharge_notes', $notes);
    
    // 记录系统日志
    logSystemAction('recharge_settings_save', "管理员修改了充值设置");
    
    echo json_encode(['success' => true]);
}

// 获取用户积分历史
function getPointsHistory($data) {
    // 检查管理员登录状态
    if (!isset($_SESSION['admin'])) {
        echo json_encode(['success' => false, 'error' => '请先登录']);
        return;
    }
    
    $userId = isset($data['user_id']) ? (int)$data['user_id'] : 0;
    
    if (!$userId) {
        echo json_encode(['success' => false, 'error' => '请输入有效的用户ID']);
        return;
    }
    
    // 获取用户积分历史
    $history = Database::getUserPointsHistory($userId, 100);
    
    echo json_encode(['success' => true, 'history' => $history]);
}

// 获取系统日志
function getSystemLogs() {
    // 检查管理员登录状态
    if (!isset($_SESSION['admin'])) {
        echo json_encode(['success' => false, 'error' => '请先登录']);
        return;
    }
    
    // 获取系统日志
    $logs = Database::getSystemLogs(100);
    
    echo json_encode(['success' => true, 'logs' => $logs]);
}

// 获取DeepSeek账户余额
function getDeepSeekBalance() {
    // 检查管理员登录状态
    if (!isset($_SESSION['admin'])) {
        echo json_encode(['success' => false, 'error' => '请先登录']);
        return;
    }
    
    $apiKey = Database::getSystemSetting('deepseek_api_key');
    
    if (empty($apiKey)) {
        echo json_encode(['success' => false, 'error' => '请先配置DeepSeek API Key']);
        return;
    }
    
    $url = 'https://api.deepseek.com/user/balance';
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Authorization: Bearer ' . $apiKey
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        echo json_encode(['success' => false, 'error' => '请求失败: ' . $error]);
        return;
    }
    
    if ($httpCode !== 200) {
        echo json_encode(['success' => false, 'error' => 'API返回错误: HTTP ' . $httpCode . ' - ' . $response]);
        return;
    }
    
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        echo json_encode(['success' => false, 'error' => '解析响应失败']);
        return;
    }
    
    echo json_encode(['success' => true, 'balance' => $data]);
}

// 记录系统日志的辅助函数
function logSystemAction($type, $content, $userId = null) {
    $adminId = isset($_SESSION['admin']) ? $_SESSION['admin']['id'] : null;
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    Database::addSystemLog($type, $content, $userId, $adminId, $ipAddress);
}
?>