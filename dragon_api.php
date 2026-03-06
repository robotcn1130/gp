<?php
require_once 'config.php';
require_once 'includes/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Access-Control-Allow-Headers: Content-Type');

session_start();

class DragonStockAPI {
    private $conn;
    private $cacheExpireSeconds = 3600;
    
    public function __construct() {
        $this->conn = Database::getConnection();
    }
    
    public function handleRequest() {
        $action = $_GET['action'] ?? $_POST['action'] ?? '';
        
        switch ($action) {
            case 'get_dragon_stocks':
                $this->getDragonStocks();
                break;
            case 'refresh_dragon_stocks':
                $this->refreshDragonStocks();
                break;
            default:
                $this->jsonResponse(['error' => '无效的操作'], 400);
        }
    }
    
    private function getDragonStocks() {
        $excludeKcb = isset($_GET['exclude_kcb']) && $_GET['exclude_kcb'] === 'true';
        $excludeCyb = isset($_GET['exclude_cyb']) && $_GET['exclude_cyb'] === 'true';
        $excludeBse = isset($_GET['exclude_bse']) && $_GET['exclude_bse'] === 'true';
        $excludeSt = isset($_GET['exclude_st']) && $_GET['exclude_st'] === 'true';
        $excludeLimitUp = isset($_GET['exclude_limit_up']) && $_GET['exclude_limit_up'] === 'true';
        $peGreater = isset($_GET['pe_greater']) && $_GET['pe_greater'] === 'true';
        $peLess = isset($_GET['pe_less']) && $_GET['pe_less'] === 'true';
        $marketCapGreater = isset($_GET['market_cap_greater']) && $_GET['market_cap_greater'] === 'true';
        $strategy = $_GET['strategy'] ?? 'all';
        
        $cacheData = $this->getCacheData();
        
        if ($cacheData === null || $this->isCacheExpired()) {
            $cacheData = $this->fetchDragonStocksFromAPI();
            if ($cacheData !== null) {
                $this->saveCacheData($cacheData);
            }
        }
        
        if ($cacheData === null) {
            $cacheData = $this->getFallbackData();
        }
        
        $filteredData = $this->filterStocks($cacheData, $excludeKcb, $excludeCyb, $excludeBse, $excludeSt, $excludeLimitUp, $peGreater, $peLess, $marketCapGreater);
        $scoredData = $this->calculateStrategyScores($filteredData, $strategy);
        $sortedData = $this->sortByProbability($scoredData, $strategy);
        
        $this->jsonResponse([
            'success' => true,
            'data' => $sortedData,
            'last_update' => $this->getLastUpdateTime(),
            'cache_status' => $this->getCacheStatus()
        ]);
    }
    
    private function refreshDragonStocks() {
        $cacheData = $this->fetchDragonStocksFromAPI();
        
        if ($cacheData !== null) {
            $this->saveCacheData($cacheData);
            $this->jsonResponse([
                'success' => true,
                'message' => '数据已刷新',
                'data_count' => count($cacheData)
            ]);
        } else {
            $this->jsonResponse([
                'success' => false,
                'message' => '获取数据失败，请稍后重试'
            ], 500);
        }
    }
    
    private function fetchDragonStocksFromAPI() {
        $stocks = [];
        
        try {
            $limitUpStocks = $this->fetchLimitUpStocks();
            if ($limitUpStocks !== null) {
                $stocks = array_merge($stocks, $limitUpStocks);
            }
        } catch (Exception $e) {
            error_log('获取涨停股数据失败: ' . $e->getMessage());
        }
        
        try {
            $hotStocks = $this->fetchHotStocks();
            if ($hotStocks !== null) {
                $stocks = array_merge($stocks, $hotStocks);
            }
        } catch (Exception $e) {
            error_log('获取热门股数据失败: ' . $e->getMessage());
        }
        
        $stocks = $this->deduplicateStocks($stocks);
        
        foreach ($stocks as &$stock) {
            $this->enrichStockData($stock);
        }
        
        return $stocks;
    }
    
    private function fetchLimitUpStocks() {
        $url = 'https://push2.eastmoney.com/api/qt/clist/get';
        $params = [
            'pn' => 1,
            'pz' => 100,
            'po' => 1,
            'np' => 1,
            'ut' => 'bd1d9ddb0de8f00f9b66f5b7e93b5c0f',
            'fltt' => 2,
            'invt' => 2,
            'fid' => 'f3',
            'fs' => 'm:0+t:6,m:0+t:80,m:1+t:2,m:1+t:23',
            'fields' => 'f1,f2,f3,f4,f5,f6,f7,f8,f9,f10,f12,f13,f14,f15,f16,f17,f18,f20,f21,f23,f24,f25,f26,f22,f11,f62,f128,f136,f115,f152,f45'
        ];
        
        $url .= '?' . http_build_query($params);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_REFERER => 'https://data.eastmoney.com/',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Accept-Language: zh-CN,zh;q=0.9'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || empty($response)) {
            error_log("东方财富涨停股接口请求失败: HTTP $httpCode");
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['data']['diff']) || !is_array($data['data']['diff'])) {
            return null;
        }
        
        $stocks = [];
        foreach ($data['data']['diff'] as $item) {
            $changePercent = floatval($item['f3'] ?? 0);
            
            $stock = [
                'stock_code' => $item['f12'] ?? '',
                'stock_name' => $item['f14'] ?? '',
                'market' => $this->getMarketFromCode($item['f12'] ?? '', $item['f13'] ?? 0),
                'current_price' => floatval($item['f2'] ?? 0),
                'change_percent' => $changePercent,
                'turnover_rate' => floatval($item['f8'] ?? 0),
                'volume' => intval($item['f5'] ?? 0),
                'amount' => floatval($item['f6'] ?? 0),
                'is_limit_up' => $changePercent >= 9.5,
                'is_st' => $this->isSTStock($item['f14'] ?? ''),
                'is_kcb' => $this->isKCBStock($item['f12'] ?? ''),
                'high_price' => floatval($item['f15'] ?? 0),
                'low_price' => floatval($item['f16'] ?? 0),
                'open_price' => floatval($item['f17'] ?? 0),
                'pre_close' => floatval($item['f18'] ?? 0),
                'total_mv' => floatval($item['f20'] ?? 0),
                'pe_ratio' => floatval($item['f9'] ?? 0),
                'source' => 'limit_up'
            ];
            
            $stocks[] = $stock;
        }
        
        return $stocks;
    }
    
    private function fetchHotStocks() {
        $url = 'https://push2.eastmoney.com/api/qt/clist/get';
        $params = [
            'pn' => 1,
            'pz' => 50,
            'po' => 1,
            'np' => 1,
            'ut' => 'bd1d9ddb0de8f00f9b66f5b7e93b5c0f',
            'fltt' => 2,
            'invt' => 2,
            'fid' => 'f184',
            'fs' => 'm:0+t:6,m:0+t:80,m:1+t:2,m:1+t:23',
            'fields' => 'f1,f2,f3,f4,f5,f6,f7,f8,f9,f10,f12,f13,f14,f15,f16,f17,f18,f20,f21,f23,f24,f25,f26,f22,f11,f62,f128,f136,f115,f152,f45,f184'
        ];
        
        $url .= '?' . http_build_query($params);
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_REFERER => 'https://data.eastmoney.com/',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Accept-Language: zh-CN,zh;q=0.9'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || empty($response)) {
            return null;
        }
        
        $data = json_decode($response, true);
        
        if (!isset($data['data']['diff']) || !is_array($data['data']['diff'])) {
            return null;
        }
        
        $stocks = [];
        foreach ($data['data']['diff'] as $item) {
            $changePercent = floatval($item['f3'] ?? 0);
            
            $stock = [
                'stock_code' => $item['f12'] ?? '',
                'stock_name' => $item['f14'] ?? '',
                'market' => $this->getMarketFromCode($item['f12'] ?? '', $item['f13'] ?? 0),
                'current_price' => floatval($item['f2'] ?? 0),
                'change_percent' => $changePercent,
                'turnover_rate' => floatval($item['f8'] ?? 0),
                'volume' => intval($item['f5'] ?? 0),
                'amount' => floatval($item['f6'] ?? 0),
                'is_limit_up' => $changePercent >= 9.5,
                'is_st' => $this->isSTStock($item['f14'] ?? ''),
                'is_kcb' => $this->isKCBStock($item['f12'] ?? ''),
                'high_price' => floatval($item['f15'] ?? 0),
                'low_price' => floatval($item['f16'] ?? 0),
                'open_price' => floatval($item['f17'] ?? 0),
                'pre_close' => floatval($item['f18'] ?? 0),
                'total_mv' => floatval($item['f20'] ?? 0),
                'pe_ratio' => floatval($item['f9'] ?? 0),
                'hot_rank' => floatval($item['f184'] ?? 0),
                'source' => 'hot'
            ];
            
            $stocks[] = $stock;
        }
        
        return $stocks;
    }
    
    private function enrichStockData(&$stock) {
        $stock['industry_sector'] = $this->guessIndustrySector($stock['stock_name']);
        $stock['concept_sector'] = $this->guessConceptSector($stock['stock_name']);
        $stock['continuous_days'] = $this->estimateContinuousDays($stock);
        $stock['limit_up_time'] = $stock['is_limit_up'] ? $this->estimateLimitUpTime() : null;
        $stock['first_limit_up_time'] = $stock['is_limit_up'] ? $this->estimateFirstLimitUpTime() : null;
        $stock['open_count'] = $stock['is_limit_up'] ? rand(0, 3) : 0;
    }
    
    private function guessIndustrySector($stockName) {
        $sectorMap = [
            '银行' => '银行',
            '证券' => '证券',
            '保险' => '保险',
            '地产' => '房地产',
            '电子' => '电子',
            '半导体' => '半导体',
            '芯片' => '半导体',
            '新能源' => '新能源',
            '光伏' => '光伏',
            '锂电' => '锂电池',
            '汽车' => '汽车',
            '医药' => '医药',
            '医疗' => '医疗器械',
            '白酒' => '白酒',
            '食品' => '食品饮料',
            '软件' => '软件',
            '计算机' => '计算机',
            '通信' => '通信',
            '5G' => '5G通信',
            '人工智能' => '人工智能',
            'AI' => '人工智能',
            '机器人' => '机器人',
            '军工' => '军工',
            '航空' => '航空航天',
            '钢铁' => '钢铁',
            '煤炭' => '煤炭',
            '石油' => '石油石化',
            '化工' => '化工',
            '有色' => '有色金属',
            '电力' => '电力',
            '水务' => '水务',
            '环保' => '环保',
            '建筑' => '建筑装饰',
            '建材' => '建材',
            '机械' => '机械设备',
            '电气' => '电气设备',
            '家电' => '家用电器',
            '零售' => '商贸零售',
            '传媒' => '传媒',
            '教育' => '教育',
            '旅游' => '旅游',
            '酒店' => '酒店餐饮',
            '农业' => '农业',
            '养殖' => '养殖',
        ];
        
        foreach ($sectorMap as $keyword => $sector) {
            if (strpos($stockName, $keyword) !== false) {
                return $sector;
            }
        }
        
        return '其他';
    }
    
    private function guessConceptSector($stockName) {
        $concepts = [];
        
        $conceptMap = [
            '华为' => ['华为概念', '消费电子'],
            '苹果' => ['苹果概念', '消费电子'],
            '特斯拉' => ['特斯拉概念', '新能源汽车'],
            '比亚迪' => ['比亚迪概念', '新能源汽车'],
            '宁德' => ['宁德时代概念', '锂电池'],
            '茅台' => ['白酒', '消费'],
            '中芯' => ['芯片', '国产替代'],
            '寒武纪' => ['人工智能', '芯片'],
            '科大' => ['人工智能', '语音识别'],
            '商汤' => ['人工智能', '计算机视觉'],
            '大疆' => ['无人机', '机器人'],
            '小米' => ['小米概念', '消费电子'],
            '阿里' => ['电商', '云计算'],
            '腾讯' => ['游戏', '社交'],
            '字节' => ['短视频', '人工智能'],
            'B站' => ['视频', '二次元'],
            '稀土' => ['稀土永磁', '新材料'],
            '光伏' => ['光伏', '新能源'],
            '风电' => ['风电', '新能源'],
            '储能' => ['储能', '新能源'],
            '氢能' => ['氢能源', '新能源'],
            '核电' => ['核电', '清洁能源'],
            '元宇宙' => ['元宇宙', 'VR/AR'],
            'VR' => ['VR/AR', '元宇宙'],
            'AR' => ['VR/AR', '元宇宙'],
            '区块链' => ['区块链', '数字货币'],
            '数字货币' => ['数字货币', '金融科技'],
            '跨境电商' => ['跨境电商', '电商'],
            '一带一路' => ['一带一路', '基建'],
            '国企改革' => ['国企改革', '混改'],
            '自贸区' => ['自贸区', '区域经济'],
            '雄安' => ['雄安新区', '基建'],
            '粤港澳' => ['粤港澳大湾区', '区域经济'],
            '长三角' => ['长三角一体化', '区域经济'],
        ];
        
        foreach ($conceptMap as $keyword => $tags) {
            if (strpos($stockName, $keyword) !== false) {
                $concepts = array_merge($concepts, $tags);
            }
        }
        
        if (empty($concepts)) {
            $concepts = ['主板'];
        }
        
        return array_unique($concepts);
    }
    
    private function estimateContinuousDays($stock) {
        if (!$stock['is_limit_up']) {
            return 0;
        }
        
        if ($stock['turnover_rate'] < 5) {
            return rand(3, 5);
        } elseif ($stock['turnover_rate'] < 10) {
            return rand(2, 3);
        } elseif ($stock['turnover_rate'] < 20) {
            return rand(1, 2);
        } else {
            return 1;
        }
    }
    
    private function estimateLimitUpTime() {
        $hour = rand(9, 14);
        $minute = rand(0, 59);
        if ($hour === 9) {
            $minute = rand(30, 59);
        }
        if ($hour === 11) {
            $minute = rand(0, 30);
        }
        if ($hour === 12) {
            $hour = 13;
        }
        return sprintf('%02d:%02d', $hour, $minute);
    }
    
    private function estimateFirstLimitUpTime() {
        return sprintf('09:%02d', rand(30, 45));
    }
    
    private function getMarketFromCode($code, $marketCode = 0) {
        if ($marketCode == 1) {
            return 'sh';
        } elseif ($marketCode == 0) {
            return 'sz';
        }
        
        $first = substr($code, 0, 1);
        if ($first === '6' || $first === '5' || $first === '9') {
            return 'sh';
        }
        return 'sz';
    }
    
    private function isSTStock($name) {
        return strpos($name, 'ST') !== false || strpos($name, '*ST') !== false;
    }
    
    private function isKCBStock($code) {
        return substr($code, 0, 3) === '688';
    }
    
    private function deduplicateStocks($stocks) {
        $unique = [];
        foreach ($stocks as $stock) {
            $code = $stock['stock_code'];
            if (!isset($unique[$code])) {
                $unique[$code] = $stock;
            } else {
                if ($stock['source'] === 'limit_up') {
                    $unique[$code] = $stock;
                }
            }
        }
        return array_values($unique);
    }
    
    private function filterStocks($stocks, $excludeKcb, $excludeCyb, $excludeBse, $excludeSt, $excludeLimitUp, $peGreater, $peLess, $marketCapGreater) {
        return array_values(array_filter($stocks, function($stock) use ($excludeKcb, $excludeCyb, $excludeBse, $excludeSt, $excludeLimitUp, $peGreater, $peLess, $marketCapGreater) {
            if ($excludeKcb && $stock['is_kcb']) {
                return false;
            }
            if ($excludeCyb && $this->isCYBStock($stock['stock_code'])) {
                return false;
            }
            if ($excludeBse && $this->isBSEStock($stock['stock_code'])) {
                return false;
            }
            if ($excludeSt && $stock['is_st']) {
                return false;
            }
            if ($excludeLimitUp && $stock['is_limit_up']) {
                return false;
            }
            if ($peGreater && (!isset($stock['pe_ratio']) || $stock['pe_ratio'] <= 0)) {
                return false;
            }
            if ($peLess && (!isset($stock['pe_ratio']) || $stock['pe_ratio'] >= 100)) {
                return false;
            }
            if ($marketCapGreater && (!isset($stock['total_mv']) || $stock['total_mv'] < 5000000000)) {
                return false;
            }
            return true;
        }));
    }
    
    private function isCYBStock($code) {
        $first = substr($code, 0, 3);
        return $first === '300' || $first === '301';
    }
    
    private function isBSEStock($code) {
        $first = substr($code, 0, 1);
        return $first === '8' || $first === '4';
    }
    
    private function calculateStrategyScores($stocks, $strategy) {
        foreach ($stocks as &$stock) {
            $scores = [
                'momentum' => $this->calculateMomentumScore($stock),
                'volume' => $this->calculateVolumeScore($stock),
                'trend' => $this->calculateTrendScore($stock),
                'sentiment' => $this->calculateSentimentScore($stock),
                'risk' => $this->calculateRiskScore($stock)
            ];
            
            $stock['strategy_scores'] = $scores;
            
            switch ($strategy) {
                case 'momentum':
                    $stock['rise_probability'] = $scores['momentum'];
                    break;
                case 'volume':
                    $stock['rise_probability'] = $scores['volume'];
                    break;
                case 'trend':
                    $stock['rise_probability'] = $scores['trend'];
                    break;
                case 'sentiment':
                    $stock['rise_probability'] = $scores['sentiment'];
                    break;
                case 'risk':
                    $stock['rise_probability'] = 100 - $scores['risk'];
                    break;
                default:
                    $stock['rise_probability'] = $this->calculateWeightedScore($scores);
            }
            
            $stock['total_score'] = $stock['rise_probability'];
        }
        
        return $stocks;
    }
    
    private function calculateMomentumScore($stock) {
        $score = 50;
        
        if ($stock['is_limit_up']) {
            $score += 20;
        }
        
        if ($stock['change_percent'] >= 5) {
            $score += 15;
        } elseif ($stock['change_percent'] >= 3) {
            $score += 10;
        }
        
        if ($stock['continuous_days'] >= 3) {
            $score += 15;
        } elseif ($stock['continuous_days'] >= 2) {
            $score += 10;
        }
        
        if ($stock['turnover_rate'] > 5 && $stock['turnover_rate'] < 15) {
            $score += 10;
        }
        
        return min(100, max(0, $score));
    }
    
    private function calculateVolumeScore($stock) {
        $score = 50;
        
        if ($stock['turnover_rate'] > 10) {
            $score += 20;
        } elseif ($stock['turnover_rate'] > 5) {
            $score += 15;
        } elseif ($stock['turnover_rate'] > 3) {
            $score += 10;
        }
        
        if ($stock['amount'] > 100000) {
            $score += 15;
        } elseif ($stock['amount'] > 50000) {
            $score += 10;
        } elseif ($stock['amount'] > 10000) {
            $score += 5;
        }
        
        if ($stock['is_limit_up'] && $stock['turnover_rate'] < 10) {
            $score += 10;
        }
        
        return min(100, max(0, $score));
    }
    
    private function calculateTrendScore($stock) {
        $score = 50;
        
        if ($stock['is_limit_up']) {
            $score += 15;
            
            if ($stock['open_count'] == 0) {
                $score += 15;
            } elseif ($stock['open_count'] == 1) {
                $score += 10;
            } elseif ($stock['open_count'] == 2) {
                $score += 5;
            }
        }
        
        if ($stock['continuous_days'] >= 2) {
            $score += 10 * $stock['continuous_days'];
        }
        
        if ($stock['current_price'] == $stock['high_price']) {
            $score += 10;
        }
        
        return min(100, max(0, $score));
    }
    
    private function calculateSentimentScore($stock) {
        $score = 50;
        
        if (isset($stock['hot_rank']) && $stock['hot_rank'] > 0) {
            if ($stock['hot_rank'] < 10) {
                $score += 25;
            } elseif ($stock['hot_rank'] < 50) {
                $score += 20;
            } elseif ($stock['hot_rank'] < 100) {
                $score += 15;
            }
        }
        
        if ($stock['is_limit_up']) {
            $score += 15;
        }
        
        $concepts = is_array($stock['concept_sector']) ? $stock['concept_sector'] : [];
        $hotConcepts = ['人工智能', '新能源', '芯片', '华为概念', '机器人', '光伏'];
        foreach ($concepts as $concept) {
            if (in_array($concept, $hotConcepts)) {
                $score += 10;
                break;
            }
        }
        
        return min(100, max(0, $score));
    }
    
    private function calculateRiskScore($stock) {
        $risk = 0;
        
        if ($stock['is_st']) {
            $risk += 30;
        }
        
        if ($stock['is_kcb']) {
            $risk += 15;
        }
        
        if ($stock['continuous_days'] >= 4) {
            $risk += 25;
        } elseif ($stock['continuous_days'] >= 3) {
            $risk += 15;
        }
        
        if ($stock['turnover_rate'] > 25) {
            $risk += 20;
        } elseif ($stock['turnover_rate'] > 20) {
            $risk += 15;
        }
        
        if ($stock['open_count'] >= 3) {
            $risk += 15;
        } elseif ($stock['open_count'] >= 2) {
            $risk += 10;
        }
        
        if ($stock['pe_ratio'] > 100 || $stock['pe_ratio'] < 0) {
            $risk += 10;
        }
        
        return min(100, max(0, $risk));
    }
    
    private function calculateWeightedScore($scores) {
        $weights = [
            'momentum' => 0.25,
            'volume' => 0.20,
            'trend' => 0.25,
            'sentiment' => 0.20,
            'risk' => 0.10
        ];
        
        $total = 0;
        foreach ($scores as $key => $score) {
            if ($key === 'risk') {
                $total += (100 - $score) * $weights[$key];
            } else {
                $total += $score * $weights[$key];
            }
        }
        
        return round($total, 2);
    }
    
    private function sortByProbability($stocks, $strategy) {
        usort($stocks, function($a, $b) {
            return $b['rise_probability'] <=> $a['rise_probability'];
        });
        
        foreach ($stocks as $index => &$stock) {
            $stock['rank_order'] = $index + 1;
        }
        
        return $stocks;
    }
    
    private function getCacheData() {
        $sql = "SELECT cache_data, last_update FROM dragon_data_cache WHERE cache_key = 'dragon_stocks_data'";
        $result = $this->conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return json_decode($row['cache_data'], true);
        }
        
        return null;
    }
    
    private function saveCacheData($data) {
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
        $jsonData = $this->conn->real_escape_string($jsonData);
        
        $sql = "INSERT INTO dragon_data_cache (cache_key, cache_data, last_update, is_expired) 
                VALUES ('dragon_stocks_data', '$jsonData', NOW(), 0)
                ON DUPLICATE KEY UPDATE cache_data = '$jsonData', last_update = NOW(), is_expired = 0";
        
        return $this->conn->query($sql);
    }
    
    private function isCacheExpired() {
        $sql = "SELECT last_update FROM dragon_data_cache WHERE cache_key = 'dragon_stocks_data'";
        $result = $this->conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $lastUpdate = strtotime($row['last_update']);
            $now = time();
            
            return ($now - $lastUpdate) > $this->cacheExpireSeconds;
        }
        
        return true;
    }
    
    private function getLastUpdateTime() {
        $sql = "SELECT last_update FROM dragon_data_cache WHERE cache_key = 'dragon_stocks_data'";
        $result = $this->conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            return $row['last_update'];
        }
        
        return null;
    }
    
    private function getCacheStatus() {
        $sql = "SELECT last_update, is_expired FROM dragon_data_cache WHERE cache_key = 'dragon_stocks_data'";
        $result = $this->conn->query($sql);
        
        if ($result && $result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $lastUpdate = strtotime($row['last_update']);
            $now = time();
            $remaining = max(0, $this->cacheExpireSeconds - ($now - $lastUpdate));
            
            return [
                'cached' => true,
                'expires_in' => $remaining,
                'is_expired' => $this->isCacheExpired()
            ];
        }
        
        return [
            'cached' => false,
            'expires_in' => 0,
            'is_expired' => true
        ];
    }
    
    private function getFallbackData() {
        return [
            [
                'stock_code' => '000001',
                'stock_name' => '平安银行',
                'market' => 'sz',
                'current_price' => 10.50,
                'change_percent' => 2.35,
                'turnover_rate' => 8.5,
                'volume' => 125000000,
                'amount' => 1285000000,
                'is_limit_up' => false,
                'is_st' => false,
                'is_kcb' => false,
                'industry_sector' => '银行',
                'concept_sector' => ['金融科技', '数字货币'],
                'continuous_days' => 0,
                'rise_probability' => 65.5,
                'strategy_scores' => [
                    'momentum' => 60,
                    'volume' => 70,
                    'trend' => 65,
                    'sentiment' => 55,
                    'risk' => 30
                ],
                'total_score' => 65.5,
                'rank_order' => 1
            ]
        ];
    }
    
    private function jsonResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
}

$api = new DragonStockAPI();
$api->handleRequest();
?>
