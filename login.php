<?php
// 登录处理文件
require_once 'config.php';
require_once 'includes/database.php';

// 记录系统日志的辅助函数
function logSystemAction($type, $content, $userId = null) {
    $adminId = null;
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    Database::addSystemLog($type, $content, $userId, $adminId, $ipAddress);
}

// 处理登录请求
if (isset($_GET['action'])) {
    $action = $_GET['action'];
    
    switch ($action) {
        case 'register':
            // 用户注册
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $username = $_POST['username'] ?? '';
                $password = $_POST['password'] ?? '';
                $nickname = $_POST['nickname'] ?? '';
                
                if (empty($username) || empty($password)) {
                    echo json_encode(['success' => false, 'message' => '用户名和密码不能为空']);
                    exit;
                }
                
                // 检查用户名是否已存在
                $conn = Database::getConnection();
                $username = $conn->real_escape_string($username);
                $sql = "SELECT * FROM users WHERE username = '$username'";
                $result = $conn->query($sql);
                
                if ($result->num_rows > 0) {
                    echo json_encode(['success' => false, 'message' => '用户名已存在']);
                    exit;
                }
                
                // 密码加密
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                $nickname = $conn->real_escape_string($nickname);
                
                // 获取新用户赠送积分
                $newUserPoints = Database::getSystemSetting('new_user_points') ?: 20;
                
                // 创建用户
                $sql = "INSERT INTO users (username, password, nickname, points) 
                        VALUES ('$username', '$hashedPassword', '$nickname', $newUserPoints)";
                
                if ($conn->query($sql)) {
                    $userId = $conn->insert_id;
                    
                    // 记录积分历史
                    Database::addPointsHistory($userId, $newUserPoints, 'add', '新用户注册赠送');
                    
                    // 记录系统日志
                    logSystemAction('user_register', "新用户注册成功：用户名 {$username}", $userId);
                    
                    // 获取用户信息
                    $sql = "SELECT * FROM users WHERE id = $userId";
                    $result = $conn->query($sql);
                    $user = $result->fetch_assoc();
                    
                    // 保存用户信息到session
                    session_start();
                    $_SESSION['user'] = $user;
                    
                    echo json_encode(['success' => true, 'message' => '注册成功']);
                } else {
                    echo json_encode(['success' => false, 'message' => '注册失败，请重试']);
                }
                exit;
            }
            break;
            
        case 'login':
            // 用户登录
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $username = $_POST['username'] ?? '';
                $password = $_POST['password'] ?? '';
                
                if (empty($username) || empty($password)) {
                    echo json_encode(['success' => false, 'message' => '用户名和密码不能为空']);
                    exit;
                }
                
                // 验证用户
                $conn = Database::getConnection();
                $username = $conn->real_escape_string($username);
                $sql = "SELECT * FROM users WHERE username = '$username'";
                $result = $conn->query($sql);
                
                if ($result->num_rows > 0) {
                    $user = $result->fetch_assoc();
                    if (password_verify($password, $user['password'])) {
                        // 保存用户信息到session
                        session_start();
                        $_SESSION['user'] = $user;
                        
                        // 记录系统日志
                        logSystemAction('user_login', "用户登录成功：用户名 {$username}", $user['id']);
                        
                        echo json_encode(['success' => true, 'message' => '登录成功']);
                    } else {
                        // 记录系统日志
                        logSystemAction('user_login_failed', "用户登录失败：用户名 {$username}，原因：密码错误");
                        
                        echo json_encode(['success' => false, 'message' => '密码错误']);
                    }
                } else {
                    // 记录系统日志
                    logSystemAction('user_login_failed', "用户登录失败：用户名 {$username}，原因：用户不存在");
                    
                    echo json_encode(['success' => false, 'message' => '用户不存在']);
                }
                exit;
            }
            break;
            
        case 'logout':
            // 退出登录
            session_start();
            $user = isset($_SESSION['user']) ? $_SESSION['user'] : null;
            if ($user) {
                logSystemAction('user_logout', "用户 {$user['username']} (ID: {$user['id']}) 退出登录", $user['id']);
            }
            unset($_SESSION['user']);
            session_destroy();
            header('Location: index.html');
            exit;
            
        case 'checklogin':
            // 检查登录状态
            session_start();
            if (isset($_SESSION['user'])) {
                echo json_encode([
                    'logged' => true,
                    'user' => $_SESSION['user']
                ]);
            } else {
                echo json_encode([
                    'logged' => false
                ]);
            }
            exit;
            
        case 'changepassword':
            // 修改密码
            session_start();
            if (!isset($_SESSION['user'])) {
                echo json_encode(['success' => false, 'message' => '请先登录']);
                exit;
            }
            
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $oldPassword = $_POST['old_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';
                
                if (empty($oldPassword) || empty($newPassword) || empty($confirmPassword)) {
                    echo json_encode(['success' => false, 'message' => '所有字段不能为空']);
                    exit;
                }
                
                if ($newPassword !== $confirmPassword) {
                    echo json_encode(['success' => false, 'message' => '两次输入的密码不一致']);
                    exit;
                }
                
                $user = $_SESSION['user'];
                $userId = $user['id'];
                
                // 验证旧密码
                $conn = Database::getConnection();
                $sql = "SELECT password FROM users WHERE id = $userId";
                $result = $conn->query($sql);
                $userData = $result->fetch_assoc();
                
                if (!password_verify($oldPassword, $userData['password'])) {
                    echo json_encode(['success' => false, 'message' => '原密码错误']);
                    exit;
                }
                
                // 更新密码
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $sql = "UPDATE users SET password = '$hashedPassword' WHERE id = $userId";
                
                if ($conn->query($sql)) {
                    logSystemAction('user_password_change', "用户 {$user['username']} (ID: {$user['id']}) 修改了密码", $user['id']);
                    echo json_encode(['success' => true, 'message' => '密码修改成功']);
                } else {
                    echo json_encode(['success' => false, 'message' => '密码修改失败，请重试']);
                }
                exit;
            }
            break;
            
        case 'updateuser':
            // 更新用户信息
            session_start();
            if (!isset($_SESSION['user'])) {
                echo json_encode(['success' => false, 'message' => '请先登录']);
                exit;
            }
            
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                $username = $_POST['username'] ?? '';
                $nickname = $_POST['nickname'] ?? '';
                $user = $_SESSION['user'];
                $userId = $user['id'];
                
                if (empty($username)) {
                    echo json_encode(['success' => false, 'message' => '用户名不能为空']);
                    exit;
                }
                
                if (empty($nickname)) {
                    echo json_encode(['success' => false, 'message' => '昵称不能为空']);
                    exit;
                }
                
                // 检查用户名是否已被其他用户使用
                $conn = Database::getConnection();
                $username = $conn->real_escape_string($username);
                $sql = "SELECT * FROM users WHERE username = '$username' AND id != $userId";
                $result = $conn->query($sql);
                
                if ($result->num_rows > 0) {
                    echo json_encode(['success' => false, 'message' => '用户名已存在']);
                    exit;
                }
                
                // 更新用户信息
                $nickname = $conn->real_escape_string($nickname);
                $sql = "UPDATE users SET username = '$username', nickname = '$nickname' WHERE id = $userId";
                
                if ($conn->query($sql)) {
                    // 更新session中的用户信息
                    $user['username'] = $username;
                    $user['nickname'] = $nickname;
                    $_SESSION['user'] = $user;
                    
                    // 记录系统日志
                    logSystemAction('user_info_update', "用户 {$user['username']} (ID: {$user['id']}) 更新了个人信息", $user['id']);
                    
                    echo json_encode(['success' => true, 'message' => '用户信息更新成功']);
                } else {
                    echo json_encode(['success' => false, 'message' => '用户信息更新失败，请重试']);
                }
                exit;
            }
            break;
            
        default:
            break;
    }
}

// 获取登录状态
session_start();
$user = isset($_SESSION['user']) ? $_SESSION['user'] : null;

if ($user) {
    echo '已登录用户: ' . $user['nickname'] . '<br>';
    echo '<a href="login.php?action=logout">退出登录</a>';
} else {
    echo '<h2>用户登录</h2>';
    echo '<form action="login.php?action=login" method="post">';
    echo '用户名: <input type="text" name="username"><br>';
    echo '密码: <input type="password" name="password"><br>';
    echo '<input type="submit" value="登录">';
    echo '</form>';
    echo '<br>';
    echo '<h2>用户注册</h2>';
    echo '<form action="login.php?action=register" method="post">';
    echo '用户名: <input type="text" name="username"><br>';
    echo '密码: <input type="password" name="password"><br>';
    echo '昵称: <input type="text" name="nickname"><br>';
    echo '<input type="submit" value="注册">';
    echo '</form>';
}
?>