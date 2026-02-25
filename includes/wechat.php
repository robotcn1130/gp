<?php
// 微信登录处理类
class Wechat {
    private static $appId;
    private static $appSecret;
    
    // 初始化
    public static function init() {
        if (!isset(self::$appId)) {
            self::$appId = Database::getSystemSetting('wechat_appid');
            self::$appSecret = Database::getSystemSetting('wechat_appsecret');
        }
    }
    
    // 获取微信扫码登录二维码URL
    public static function getQrCodeUrl($redirectUri) {
        self::init();
        $redirectUri = urlencode($redirectUri);
        $scope = 'snsapi_login';
        $state = uniqid();
        
        // 保存state到session
        session_start();
        $_SESSION['wechat_state'] = $state;
        
        $url = "https://open.weixin.qq.com/connect/qrconnect?appid=" . self::$appId . "&redirect_uri=$redirectUri&response_type=code&scope=$scope&state=$state#wechat_redirect";
        return $url;
    }
    
    // 获取微信内置浏览器登录URL
    public static function getMobileLoginUrl($redirectUri) {
        self::init();
        $redirectUri = urlencode($redirectUri);
        $scope = 'snsapi_base';
        $state = uniqid();
        
        // 保存state到session
        session_start();
        $_SESSION['wechat_state'] = $state;
        
        $url = "https://open.weixin.qq.com/connect/oauth2/authorize?appid=" . self::$appId . "&redirect_uri=$redirectUri&response_type=code&scope=$scope&state=$state#wechat_redirect";
        return $url;
    }
    
    // 通过code获取access_token
    public static function getAccessToken($code) {
        self::init();
        
        $url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid=" . self::$appId . "&secret=" . self::$appSecret . "&code=$code&grant_type=authorization_code";
        
        $response = self::httpGet($url);
        $result = json_decode($response, true);
        
        if (isset($result['access_token']) && isset($result['openid'])) {
            return $result;
        }
        return false;
    }
    
    // 获取用户信息
    public static function getUserInfo($accessToken, $openid) {
        $url = "https://api.weixin.qq.com/sns/userinfo?access_token=$accessToken&openid=$openid&lang=zh_CN";
        
        $response = self::httpGet($url);
        $result = json_decode($response, true);
        
        if (isset($result['openid'])) {
            return $result;
        }
        return false;
    }
    
    // 处理微信登录回调
    public static function handleCallback($code, $state) {
        // 验证state
        session_start();
        if (!isset($_SESSION['wechat_state']) || $_SESSION['wechat_state'] !== $state) {
            return false;
        }
        
        // 获取access_token
        $tokenInfo = self::getAccessToken($code);
        if (!$tokenInfo) {
            return false;
        }
        
        $openid = $tokenInfo['openid'];
        $userInfo = null;
        
        // 如果是snsapi_userinfo scope，获取用户信息
        if (isset($tokenInfo['scope']) && strpos($tokenInfo['scope'], 'snsapi_userinfo') !== false) {
            $userInfo = self::getUserInfo($tokenInfo['access_token'], $openid);
        }
        
        // 查找或创建用户
        $user = Database::getUserByOpenid($openid);
        if (!$user) {
            $nickname = $userInfo ? $userInfo['nickname'] : '微信用户';
            $avatar = $userInfo ? $userInfo['headimgurl'] : '';
            $userId = Database::createUser($openid, $nickname, $avatar);
            if ($userId) {
                $user = Database::getUserByOpenid($openid);
            }
        }
        
        return $user;
    }
    
    // HTTP GET请求
    private static function httpGet($url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return $response;
    }
    
    // 检查是否在微信内置浏览器中
    public static function isWechatBrowser() {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return strpos($userAgent, 'MicroMessenger') !== false;
    }
}
?>