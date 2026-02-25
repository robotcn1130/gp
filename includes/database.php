<?php
// 数据库连接和工具类
class Database {
    private static $conn;
    
    // 获取数据库连接
    public static function getConnection() {
        if (!isset(self::$conn)) {
            try {
                self::$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
                if (self::$conn->connect_error) {
                    throw new Exception('数据库连接失败: ' . self::$conn->connect_error);
                }
                self::$conn->set_charset('utf8mb4');
            } catch (Exception $e) {
                die('数据库连接失败: ' . $e->getMessage());
            }
        }
        return self::$conn;
    }
    
    // 获取系统设置
    public static function getSystemSetting($key) {
        $conn = self::getConnection();
        $key = $conn->real_escape_string($key);
        
        $sql = "SELECT key_value FROM system_settings WHERE key_name = '$key'";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['key_value'];
        }
        return null;
    }
    
    // 更新系统设置
    public static function updateSystemSetting($key, $value) {
        $conn = self::getConnection();
        $key = $conn->real_escape_string($key);
        $value = $conn->real_escape_string($value);
        
        $sql = "INSERT INTO system_settings (key_name, key_value) 
                VALUES ('$key', '$value') 
                ON DUPLICATE KEY UPDATE key_value = '$value'";
        
        return $conn->query($sql);
    }
    
    // 获取用户信息通过用户名
    public static function getUserByUsername($username) {
        $conn = self::getConnection();
        $username = $conn->real_escape_string($username);
        
        $sql = "SELECT * FROM users WHERE username = '$username'";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        return null;
    }
    
    // 创建新用户
    public static function createUser($username, $password, $nickname) {
        $conn = self::getConnection();
        $username = $conn->real_escape_string($username);
        $password = password_hash($password, PASSWORD_DEFAULT);
        $nickname = $conn->real_escape_string($nickname);
        
        // 获取新用户赠送积分
        $newUserPoints = self::getSystemSetting('new_user_points') ?: 20;
        
        $sql = "INSERT INTO users (username, password, nickname, points) 
                VALUES ('$username', '$password', '$nickname', $newUserPoints)";
        
        if ($conn->query($sql)) {
            $userId = $conn->insert_id;
            
            // 记录积分历史
            self::addPointsHistory($userId, $newUserPoints, 'add', '新用户注册赠送');
            
            return $userId;
        }
        return false;
    }
    
    // 验证用户登录
    public static function verifyUserLogin($username, $password) {
        $conn = self::getConnection();
        $username = $conn->real_escape_string($username);
        
        $sql = "SELECT * FROM users WHERE username = '$username'";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            if (password_verify($password, $user['password'])) {
                return $user;
            }
        }
        return null;
    }
    
    // 更新用户信息
    public static function updateUser($userId, $data) {
        $conn = self::getConnection();
        $userId = (int)$userId;
        
        $setClause = '';
        foreach ($data as $key => $value) {
            if ($setClause) {
                $setClause .= ', ';
            }
            $key = $conn->real_escape_string($key);
            $value = $conn->real_escape_string($value);
            $setClause .= "$key = '$value'";
        }
        
        $sql = "UPDATE users SET $setClause WHERE id = $userId";
        return $conn->query($sql);
    }
    
    // 更新用户积分
    public static function updateUserPoints($userId, $points, $type, $reason, $adminId = null) {
        $logPrefix = 'Database::updateUserPoints - ';
        
        try {
            $conn = self::getConnection();
            $userId = (int)$userId;
            $points = (int)$points;
            $type = $conn->real_escape_string($type);
            $reason = $conn->real_escape_string($reason);
            
            // 先更新用户积分
            $operator = $type === 'add' ? '+' : '-';
            $sql = "UPDATE users SET points = points $operator $points WHERE id = $userId";
            
            if (!$conn->query($sql)) {
                self::addSystemLog('error', $logPrefix . '更新用户积分失败: ' . $conn->error, $userId);
                return false;
            }
            
            // 尝试记录积分历史，如果失败也不要影响主流程
            try {
                if ($adminId) {
                    $adminId = (int)$adminId;
                    $historySql = "INSERT INTO user_points_history (user_id, points, type, reason, admin_id) 
                                   VALUES ($userId, $points, '$type', '$reason', $adminId)";
                } else {
                    $historySql = "INSERT INTO user_points_history (user_id, points, type, reason) 
                                   VALUES ($userId, $points, '$type', '$reason')";
                }
                
                if (!$conn->query($historySql)) {
                    self::addSystemLog('warning', $logPrefix . '记录积分历史失败（不影响积分更新）: ' . $conn->error, $userId);
                }
            } catch (Exception $e) {
                self::addSystemLog('warning', $logPrefix . '记录积分历史异常（不影响积分更新）: ' . $e->getMessage(), $userId);
            }
            
            self::addSystemLog('info', $logPrefix . '积分更新成功: user_id=' . $userId . ', points=' . $points . ', type=' . $type, $userId);
            return true;
        } catch (Exception $e) {
            self::addSystemLog('error', $logPrefix . '异常: ' . $e->getMessage(), $userId);
            return false;
        }
    }
    
    // 添加积分历史
    public static function addPointsHistory($userId, $points, $type, $reason, $adminId = null) {
        $conn = self::getConnection();
        $userId = (int)$userId;
        $points = (int)$points;
        $type = $conn->real_escape_string($type);
        $reason = $conn->real_escape_string($reason);
        
        if ($adminId) {
            $adminId = (int)$adminId;
            $sql = "INSERT INTO user_points_history (user_id, points, type, reason, admin_id) 
                    VALUES ($userId, $points, '$type', '$reason', $adminId)";
        } else {
            $sql = "INSERT INTO user_points_history (user_id, points, type, reason) 
                    VALUES ($userId, $points, '$type', '$reason')";
        }
        
        return $conn->query($sql);
    }
    
    // 获取用户积分历史
    public static function getUserPointsHistory($userId, $limit = 50) {
        $conn = self::getConnection();
        $userId = (int)$userId;
        $limit = (int)$limit;
        
        $sql = "SELECT uph.*, au.username as admin_username 
                FROM user_points_history uph 
                LEFT JOIN admin_users au ON uph.admin_id = au.id 
                WHERE uph.user_id = $userId 
                ORDER BY uph.created_at DESC 
                LIMIT $limit";
        
        $result = $conn->query($sql);
        $history = [];
        
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $history[] = $row;
            }
        }
        return $history;
    }
    
    // 保存股票分析记录
    public static function saveStockAnalysis($userId, $symbol, $shares, $cost, $cash, $model, $marketData, $indexData, $newsData, $aiContent, $sectorData = null, $moneyFlowData = null, $technicalData = null, $reviewData = null) {
        $conn = self::getConnection();
        $userId = (int)$userId;
        $symbol = $conn->real_escape_string($symbol);
        $shares = (int)$shares;
        $cost = (float)$cost;
        $cash = (float)$cash;
        $model = $conn->real_escape_string($model);
        $marketData = $conn->real_escape_string($marketData);
        $indexData = $conn->real_escape_string($indexData);
        $newsData = $conn->real_escape_string($newsData);
        $aiContent = $conn->real_escape_string($aiContent);
        $sectorData = $sectorData ? $conn->real_escape_string($sectorData) : null;
        $moneyFlowData = $moneyFlowData ? $conn->real_escape_string($moneyFlowData) : null;
        $technicalData = $technicalData ? $conn->real_escape_string($technicalData) : null;
        $reviewData = $reviewData ? $conn->real_escape_string($reviewData) : null;
        
        // 检查新字段是否存在
        $hasSectorColumn = self::checkColumnExists('stock_analyses', 'sector_data');
        $hasMoneyFlowColumn = self::checkColumnExists('stock_analyses', 'moneyflow_data');
        $hasTechnicalColumn = self::checkColumnExists('stock_analyses', 'technical_data');
        $hasModelColumn = self::checkColumnExists('stock_analyses', 'model');
        $hasReviewColumn = self::checkColumnExists('stock_analyses', 'review_data');
        
        // 构建SQL
        $columns = ['user_id', 'symbol', 'shares', 'cost', 'cash', 'market_data', 'index_data', 'news_data', 'ai_content'];
        $values = [$userId, "'$symbol'", $shares, $cost, $cash, "'$marketData'", "'$indexData'", "'$newsData'", "'$aiContent'"];
        
        if ($hasModelColumn) {
            $columns[] = 'model';
            $values[] = "'$model'";
        }
        if ($hasSectorColumn && $sectorData) {
            $columns[] = 'sector_data';
            $values[] = "'$sectorData'";
        }
        if ($hasMoneyFlowColumn && $moneyFlowData) {
            $columns[] = 'moneyflow_data';
            $values[] = "'$moneyFlowData'";
        }
        if ($hasTechnicalColumn && $technicalData) {
            $columns[] = 'technical_data';
            $values[] = "'$technicalData'";
        }
        if ($hasReviewColumn && $reviewData) {
            $columns[] = 'review_data';
            $values[] = "'$reviewData'";
        }
        
        $sql = "INSERT INTO stock_analyses (" . implode(', ', $columns) . ") 
                VALUES (" . implode(', ', $values) . ")";
        
        return $conn->query($sql) ? $conn->insert_id : false;
    }
    
    private static function checkColumnExists($table, $column) {
        $conn = self::getConnection();
        $checkSql = "SHOW COLUMNS FROM $table LIKE '$column'";
        $result = $conn->query($checkSql);
        return $result && $result->num_rows > 0;
    }
    
    // 获取用户的股票分析历史
    public static function getUserStockAnalyses($userId, $limit = 50) {
        $conn = self::getConnection();
        $userId = (int)$userId;
        $limit = (int)$limit;
        
        $sql = "SELECT * FROM stock_analyses 
                WHERE user_id = $userId 
                ORDER BY created_at DESC 
                LIMIT $limit";
        
        $result = $conn->query($sql);
        $analyses = [];
        
        if ($result && $result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                // 如果没有model字段，添加默认值
                if (!isset($row['model'])) {
                    $row['model'] = 'deepseek-chat';
                }
                $analyses[] = $row;
            }
        }
        return $analyses;
    }
    
    // 删除股票分析历史记录
    public static function deleteStockAnalysis($userId, $analysisId) {
        $conn = self::getConnection();
        $userId = (int)$userId;
        $analysisId = (int)$analysisId;
        
        $sql = "DELETE FROM stock_analyses 
                WHERE id = $analysisId AND user_id = $userId";
        
        return $conn->query($sql);
    }
    
    // 获取所有用户
    public static function getAllUsers($limit = 100, $offset = 0) {
        $conn = self::getConnection();
        $limit = (int)$limit;
        $offset = (int)$offset;
        
        $sql = "SELECT * FROM users 
                ORDER BY created_at DESC 
                LIMIT $limit OFFSET $offset";
        
        $result = $conn->query($sql);
        $users = [];
        
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }
        }
        return $users;
    }
    
    // 获取用户数量
    public static function getUserCount() {
        $conn = self::getConnection();
        
        $sql = "SELECT COUNT(*) as count FROM users";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['count'];
        }
        return 0;
    }
    
    // 验证管理员登录
    public static function verifyAdminLogin($username, $password) {
        $conn = self::getConnection();
        $username = $conn->real_escape_string($username);
        
        $sql = "SELECT * FROM admin_users WHERE username = '$username'";
        $result = $conn->query($sql);
        
        if ($result->num_rows > 0) {
            $admin = $result->fetch_assoc();
            if (password_verify($password, $admin['password'])) {
                return $admin;
            }
        }
        return null;
    }
    
    // 记录系统日志
    public static function addSystemLog($type, $content, $userId = null, $adminId = null, $ipAddress = null) {
        $conn = self::getConnection();
        $type = $conn->real_escape_string($type);
        $content = $conn->real_escape_string($content);
        
        $userIdSql = $userId ? (int)$userId : 'NULL';
        $adminIdSql = $adminId ? (int)$adminId : 'NULL';
        $ipAddressSql = $ipAddress ? "'" . $conn->real_escape_string($ipAddress) . "'" : 'NULL';
        
        $sql = "INSERT INTO system_logs (type, content, user_id, admin_id, ip_address) 
                VALUES ('$type', '$content', $userIdSql, $adminIdSql, $ipAddressSql)";
        
        $result = $conn->query($sql);
        if (!$result) {
            error_log('添加系统日志失败: ' . $conn->error . ' SQL: ' . $sql);
        }
        return $result;
    }
    
    // 获取系统日志
    public static function getSystemLogs($limit = 100) {
        $conn = self::getConnection();
        $limit = (int)$limit;
        
        $sql = "SELECT * FROM system_logs 
                ORDER BY created_at DESC 
                LIMIT $limit";
        
        $result = $conn->query($sql);
        $logs = [];
        
        if ($result->num_rows > 0) {
            while ($row = $result->fetch_assoc()) {
                $logs[] = $row;
            }
        }
        return $logs;
    }
}
?>