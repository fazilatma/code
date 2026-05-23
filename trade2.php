<?php
// ================================================================================
// بخش 1: تنظیمات و کانفیگ
// ================================================================================
error_reporting(E_ALL);
ini_set('display_errors', 0);
header('Content-Type: text/html; charset=utf-8');

// تنظیمات عمومی
define('VERSION', '6.9.0');
define('CONFIG_FILE', 'bot_config_unified.json');
define('LIVE_LOG_FILE', 'live_trades_log_unified.csv');
define('SIM_LOG_FILE', 'sim_trades_log_unified.csv');
define('LIVE_OPEN_LOG_FILE', 'live_open_steps_log_unified.csv');
define('LIVE_COMPLETED_LOG_FILE', 'live_completed_trades_log_unified.csv');

// تنظیمات ایمیل
define('EMAIL_SENDER', 'fazilat.ma@gmail.com'); // Default sender
define('EMAIL_PASSWORD', 'Mfn12592268');
define('EMAIL_RECIPIENT', 'fazilat.ma2@gmail.com'); // Recipient for live order notifications

// تنظیمات تلگرام
define('TELEGRAM_BOT_TOKEN', '1082931872:AAFdXeyMIagoS77J1Prtc-PRxCKpsYux3vM');
define('TELEGRAM_CHAT_ID', '-950362036');

// تنظیمات بله
define('BALE_BOT_TOKEN', '1365448887:LHwKjPNvZ_rFtAnVdG9eNO6WrjsR36JtRmM');
define('BALE_CHAT_ID', '6190331465'); // User's private server chat ID

// متغیرهای حالت معامله اتوماتیک
$auto_trading_enabled = false;
if (file_exists(CONFIG_FILE)) {
    $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
    if ($cfg) {
        $auto_trading_enabled = isset($cfg['auto_trading_enabled']) ? $cfg['auto_trading_enabled'] : false;
        $exchange = isset($cfg['exchange']) ? $cfg['exchange'] : 'nobitex';
    } else {
        $exchange = 'nobitex';
    }
} else {
    $exchange = 'nobitex';
}

// متغیرهای همگام‌سازی تایم‌فریم
$last_processed_timeframe = [];

// ================================================================================
// بخش 2: توابع اولیه‌سازی
// ================================================================================
function initialize_log_files() {
    // ایجاد فایل لاگ پله‌های باز لایو
    if (!file_exists(LIVE_OPEN_LOG_FILE)) {
        $fp = fopen(LIVE_OPEN_LOG_FILE, 'w');
        fputcsv($fp, ['Price', 'Timestamp', 'Type', 'Amount_Toman', 'Volume_USDT', 'Exchange']);
        fclose($fp);
    }
    // ایجاد فایل لاگ معاملات کامل شده لایو
    if (!file_exists(LIVE_COMPLETED_LOG_FILE)) {
        $fp = fopen(LIVE_COMPLETED_LOG_FILE, 'w');
        fputcsv($fp, ['Price', 'Timestamp', 'Type', 'Amount_Toman', 'Volume_USDT', 'Sell_Timestamp', 'Sell_Price', 'Sell_Amount_USDT', 'Sell_Amount_Toman', 'Exchange']);
        fclose($fp);
    }
    
    // ایجاد فایل حالت تایم فریم اگر وجود نداشته باشد
    $timeframe_storage_file = 'timeframe_state.json';
    if (!file_exists($timeframe_storage_file)) {
        file_put_contents($timeframe_storage_file, json_encode([]));
    }
}

// اجرای توابع اولیه‌سازی
initialize_log_files();

// ================================================================================
// بخش 3: توابع نوتیفیکیشن
// ================================================================================
function send_email_notification($subject, $body) {
    try {
        $headers = "From: " . EMAIL_SENDER . "\r\n";
        $headers .= "Reply-To: " . EMAIL_SENDER . "\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

        $mail_sent = mail(EMAIL_RECIPIENT, '=?UTF-8?B?'.base64_encode($subject).'?=', $body, $headers);

        if ($mail_sent) {
            error_log("Email sent successfully");
            return ['success' => true, 'error' => ''];
        } else {
            error_log("Failed to send email");
            return ['success' => false, 'error' => 'PHP mail() function failed.'];
        }
    } catch (Exception $e) {
        error_log("Error in send_email_notification: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function send_telegram_notification($message) {
    try {
        $url = "https://api.telegram.org/bot" . TELEGRAM_BOT_TOKEN . "/sendMessage";
        $data = [
            'chat_id' => TELEGRAM_CHAT_ID,
            'text' => $message
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['success' => false, 'error' => "Curl error: " . $error];
        }

        curl_close($ch);

        if ($http_code != 200) {
            error_log("Telegram API error: HTTP $http_code");
            return ['success' => false, 'error' => "HTTP $http_code"];
        }

        $res = json_decode($response, true);
        if (isset($res['ok']) && $res['ok']) {
            return ['success' => true, 'error' => ''];
        } else {
            $error_desc = isset($res['description']) ? $res['description'] : 'Unknown error';
            return ['success' => false, 'error' => $error_desc];
        }
    } catch (Exception $e) {
        error_log("Error in send_telegram_notification: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function send_bale_notification($message) {
    try {
        $url = "https://tapi.bale.ai/bot" . BALE_BOT_TOKEN . "/sendMessage";
        $data = [
            'chat_id' => BALE_CHAT_ID,
            'text' => $message
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['success' => false, 'error' => "Curl error: " . $error];
        }

        curl_close($ch);

        if ($http_code != 200) {
            error_log("Bale API error: HTTP $http_code");
            return ['success' => false, 'error' => "HTTP $http_code"];
        }

        $result = json_decode($response, true);
        if (isset($result['ok']) && $result['ok']) {
            return ['success' => true, 'error' => ''];
        } else {
            $error_desc = isset($result['description']) ? $result['description'] : 'Unknown error';
            return ['success' => false, 'error' => $error_desc];
        }
    } catch (Exception $e) {
        error_log("Error in send_bale_notification: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}


// ================================================================================
// بخش 4: توابع منطق اصلی
// ================================================================================
function get_exchange_config() {
    if (file_exists(CONFIG_FILE)) {
        $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
        if ($cfg) {
            // Ensure auto_trading_enabled is included in the response
            if (!isset($cfg['auto_trading_enabled'])) {
                $cfg['auto_trading_enabled'] = false;
            }
            return $cfg;
        }
    }
    return [
        'exchange' => 'nobitex',
        'nobitex_token' => '',
        'tabdeal_api_key' => '',
        'tabdeal_secret_key' => '',
        'tabdeal_trading_type' => 'spot',  // Default to spot trading
        'auto_trading_enabled' => false
    ];
}

function get_balance() {
    $config = get_exchange_config();
    $exchange = $config['exchange'] ?? 'nobitex';

    if ($exchange === 'nobitex') {
        return get_nobitex_balance($config['nobitex_token'] ?? '');
    } else {
        // Check the trading type for Tabdeal
        $trading_type = isset($config['tabdeal_trading_type']) ? $config['tabdeal_trading_type'] : 'spot';

        if ($trading_type === 'margin') {
            return get_tabdeal_margin_balance($config['tabdeal_api_key'] ?? '', $config['tabdeal_secret_key'] ?? '');
        } else {
            return get_tabdeal_balance($config['tabdeal_api_key'] ?? '', $config['tabdeal_secret_key'] ?? '');
        }
    }
}

function get_nobitex_balance($token) {
    try {
        $url = 'https://apiv2.nobitex.ir/users/wallets/list';
        $headers = [
            "Authorization: Token " . $token,
            "Content-Type: application/json"
        ];
        $data = json_encode(["type" => "spot"]);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return [0, 0, "Curl error: " . $error];
        }

        curl_close($ch);

        if ($http_code != 200) {
            return [0, 0, "HTTP Error: " . $http_code . " - Response: " . substr($response, 0, 200)];
        }

        $data = json_decode($response, true);
        if (isset($data['status']) && $data['status'] == 'ok') {
            $toman_balance = 0;
            $usdt_balance = 0;

            if (isset($data['wallets'])) {
                foreach ($data['wallets'] as $w) {
                    if ($w['currency'] == 'rls') {
                        $toman_balance = floatval($w['balance']) / 10; // Rial to Toman
                    } elseif ($w['currency'] == 'usdt') {
                        $usdt_balance = floatval($w['balance']);
                    }
                }
            }
            return [$toman_balance, $usdt_balance, null];
        } else {
            $error_msg = isset($data['message']) ? $data['message'] : (isset($data['detail']) ? $data['detail'] : 'خطا در دریافت موجودی');
            return [0, 0, $error_msg . " - Full response: " . json_encode($data)];
        }
    } catch (Exception $e) {
        return [0, 0, $e->getMessage()];
    }
}

function get_nobitex_closed_orders($token) {
    try {
        // Using GET request with query parameters as shown in the curl example
        // For USDT/IRT (Rls) trading pair
        $url = 'https://apiv2.nobitex.ir/market/orders/list?srcCurrency=usdt&dstCurrency=rls&details=1';
        $headers = [
            "Authorization: Token " . $token,
            "Content-Type: application/json"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ["error" => "Curl error: " . $error];
        }

        curl_close($ch);

        if ($http_code != 200) {
            return ["error" => "HTTP Error: " . $http_code . " - Response: " . substr($response, 0, 200)];
        }

        $data = json_decode($response, true);
        if (isset($data['status']) && $data['status'] == 'ok' && isset($data['orders'])) {
            return $data['orders'];
        } else {
            $error_msg = isset($data['message']) ? $data['message'] : (isset($data['detail']) ? $data['detail'] : 'خطا در دریافت سفارش‌های بسته شده');
            return ["error" => $error_msg . " - Full response: " . json_encode($data)];
        }
    } catch (Exception $e) {
        return ["error" => $e->getMessage()];
    }
}

function import_nobitex_orders_to_steps($token) {
    $orders = get_nobitex_closed_orders($token);
    
    if (isset($orders['error'])) {
        return ["error" => $orders['error']];
    }
    
    if (!is_array($orders)) {
        return ["error" => "No orders returned from API"];
    }
    
    $live_steps = [];
    
    foreach ($orders as $order) {
        // Check if this is a completed buy order for USDT/RLS pair
        if (isset($order['type']) && $order['type'] === 'buy' && 
            isset($order['status']) && $order['status'] === 'done' &&
            isset($order['avgPrice']) && isset($order['executedAmount'])) {
            
            // Calculate the price per unit in Tomans (Nobitex prices are typically in Rials, divide by 10 for Tomans)
            $price_per_unit = floatval($order['avgPrice']) / 10;  
            $executed_amount = floatval($order['executedAmount']);
            
            // Only add to steps if the executed amount is greater than 0
            if ($executed_amount > 0) {
                // Add to live steps - this represents a purchase at this price
                $live_steps[] = $price_per_unit;
            }
        }
    }
    
    // Save these steps to the live open log file
    if (!empty($live_steps)) {
        $exchange = 'nobitex';
        
        foreach ($live_steps as $step_price) {
            $fp = fopen(LIVE_OPEN_LOG_FILE, 'a');
            if ($fp) {
                // Calculate amount in Tomans and volume in USDT based on the executed amount
                $usd_rate = get_usd_to_irt_rate();
                
                // Calculate the amount in Tomans based on executed amount of RLS and the price
                $amount_toman = $step_price * 1; // Amount for 1 unit
                $volume_usdt = 1; // For 1 RLS unit bought
                
                fputcsv($fp, [
                    $step_price,
                    time(),
                    'BUY',
                    $amount_toman,
                    $volume_usdt,
                    $exchange
                ]);
                fclose($fp);
            }
        }
    }
    
    return [
        "status" => "ok", 
        "imported_count" => count($live_steps),
        "steps" => $live_steps
    ];
}

function get_tabdeal_balance($api_key, $secret_key) {
    try {
        // Get spot balance
        $url = 'https://api.tabdeal.org/api/v1/account/balance';
        $timestamp = time();
        $signature = generate_tabdeal_signature($api_key, $secret_key, $timestamp);

        $headers = [
            "X-API-KEY: " . $api_key,
            "X-TIMESTAMP: " . $timestamp,
            "X-SIGNATURE: " . $signature,
            "Content-Type: application/json"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return [0, 0, "Curl error: " . $error];
        }

        curl_close($ch);

        if ($http_code != 200) {
            return [0, 0, "HTTP Error: " . $http_code . " - Response: " . substr($response, 0, 200)];
        }

        $data = json_decode($response, true);
        if (isset($data['success']) && $data['success'] === true) {
            $toman_balance = 0;
            $usdt_balance = 0;

            if (isset($data['data']) && is_array($data['data'])) {
                foreach ($data['data'] as $balance) {
                    if (isset($balance['asset']) && isset($balance['free'])) {
                        $currency = strtolower($balance['asset']);
                        $free_balance = floatval($balance['free']);

                        if ($currency == 'irt' || $currency == 'tom') {
                            $toman_balance = $free_balance;
                        } elseif ($currency == 'usdt') {
                            $usdt_balance = $free_balance;
                        }
                    }
                }
            }
            return [$toman_balance, $usdt_balance, null];
        } else {
            $error_msg = isset($data['message']) ? $data['message'] : (isset($data['msg']) ? $data['msg'] : 'خطا در دریافت موجودی');
            return [0, 0, $error_msg . " - Full response: " . json_encode($data)];
        }
    } catch (Exception $e) {
        return [0, 0, $e->getMessage()];
    }
}

function generate_tabdeal_signature($api_key, $secret_key, $timestamp) {
    // Generate signature based on Tabdeal API requirements
    // Typically follows pattern: apiKey + timestamp + recvWindow (if used)
    $recv_window = 5000; // Default receive window
    $message = $api_key . $timestamp . $recv_window;
    return hash_hmac('sha256', $message, $secret_key);
}

// Leverage/Margin trading functions for Tabdeal
function get_tabdeal_margin_balance($api_key, $secret_key) {
    try {
        $url = 'https://api.tabdeal.org/api/v1/margin/account';
        $timestamp = time();
        $signature = generate_tabdeal_signature($api_key, $secret_key, $timestamp);

        $headers = [
            "X-API-KEY: " . $api_key,
            "X-TIMESTAMP: " . $timestamp,
            "X-SIGNATURE: " . $signature,
            "Content-Type: application/json"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return [0, 0, "Curl error: " . $error];
        }

        curl_close($ch);

        if ($http_code != 200) {
            return [0, 0, "HTTP Error: " . $http_code . " - Response: " . substr($response, 0, 200)];
        }

        $data = json_decode($response, true);
        if (isset($data['success']) && $data['success'] === true && isset($data['data'])) {
            $toman_balance = 0;
            $usdt_balance = 0;

            if (isset($data['data']['userAssets']) && is_array($data['data']['userAssets'])) {
                foreach ($data['data']['userAssets'] as $asset) {
                    if (isset($asset['asset']) && isset($asset['netAsset'])) {
                        $currency = strtolower($asset['asset']);
                        $net_balance = floatval($asset['netAsset']);

                        if ($currency == 'irt' || $currency == 'tom') {
                            $toman_balance = $net_balance;
                        } elseif ($currency == 'usdt') {
                            $usdt_balance = $net_balance;
                        }
                    }
                }
            }
            return [$toman_balance, $usdt_balance, null];
        } else {
            $error_msg = isset($data['message']) ? $data['message'] : (isset($data['msg']) ? $data['msg'] : 'خطا در دریافت موجودی مارجین');
            return [0, 0, $error_msg . " - Full response: " . json_encode($data)];
        }
    } catch (Exception $e) {
        return [0, 0, $e->getMessage()];
    }
}

function place_tabdeal_margin_order($side, $amount, $leverage, $api_key, $secret_key) {
    try {
        $url = 'https://api.tabdeal.org/api/v1/margin/order';
        $payload = [
            "symbol" => "USDTIRT",
            "side" => $side == 'buy' ? 'BUY' : 'SELL',
            "type" => "MARKET",
            "quantity" => strval($amount),
            "leverage" => strval($leverage), // Add leverage parameter
            "timestamp" => time()
        ];

        $signature = generate_tabdeal_signature($api_key, $secret_key, $payload['timestamp']);
        $payload['signature'] = $signature;

        $headers = [
            "X-API-KEY: " . $api_key,
            "X-TIMESTAMP: " . $payload['timestamp'],
            "X-SIGNATURE: " . $signature,
            "Content-Type: application/json"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ["error" => "Curl error: " . $error];
        }

        curl_close($ch);
        return json_decode($response, true);
    } catch (Exception $e) {
        return ["error" => $e->getMessage()];
    }
}

function get_usd_to_irt_rate() {
    $config = get_exchange_config();
    $exchange = $config['exchange'] ?? 'nobitex';
    
    if ($exchange === 'nobitex') {
        return get_nobitex_usd_to_irt_rate();
    } else {
        return get_tabdeal_usd_to_irt_rate($config['tabdeal_api_key'] ?? '');
    }
}

function get_nobitex_usd_to_irt_rate() {
    try {
        // Use the same historical data API to get current price for consistency
        $tf_sec = 60; // 1 minute
        $tf_map = [
            "60" => "1", "300" => "5", "900" => "15", "1800" => "30",
            "3600" => "60", "7200" => "120", "14400" => "240", "21600" => "360",
            "43200" => "720", "86400" => "1440"
        ];
        $tf_nobitex = isset($tf_map[strval($tf_sec)]) ? $tf_map[strval($tf_sec)] : "1";

        $now = time();
        $from = $now - (60 * 60); // Last 1 hour

        $url = "https://apiv2.nobitex.ir/market/udf/history?symbol=USDTIRT&resolution={$tf_nobitex}&from={$from}&to={$now}";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return 0; // or some default rate
        }

        curl_close($ch);

        if ($http_code == 200) {
            $res = json_decode($response, true);
            if (isset($res['s']) && $res['s'] == 'ok' && isset($res['c']) && count($res['c']) > 0) {
                // Return the last close price
                return floatval(end($res['c']));
            }
        }
        // Fallback to default
        return 53000;
    } catch (Exception $e) {
        return 53000;
    }
}

function get_tabdeal_usd_to_irt_rate($api_key) {
    try {
        // Use Tabdeal API to get current price for consistency
        $url = "https://api.tabdeal.org/api/v1/market/ticker?symbol=USDTIRT";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return 0; // or some default rate
        }

        curl_close($ch);

        if ($http_code == 200) {
            $res = json_decode($response, true);
            if (isset($res['success']) && $res['success'] === true && isset($res['data']['last'])) {
                // Return the last price
                return floatval($res['data']['last']);
            }
        }
        // Fallback to default
        return 53000;
    } catch (Exception $e) {
        return 53000;
    }
}

function place_order($side, $amount, $exchange_params = []) {
    $config = get_exchange_config();
    $exchange = $config['exchange'] ?? 'nobitex';

    if ($exchange === 'nobitex') {
        return place_nobitex_order($side, $amount, $config['nobitex_token'] ?? '');
    } else {
        // Check the trading type for Tabdeal
        $trading_type = isset($config['tabdeal_trading_type']) ? $config['tabdeal_trading_type'] : 'spot';
        $leverage = isset($config['tabdeal_leverage']) ? $config['tabdeal_leverage'] : 1; // Default to 1x leverage

        if ($trading_type === 'margin') {
            return place_tabdeal_margin_order($side, $amount, $leverage, $config['tabdeal_api_key'] ?? '', $config['tabdeal_secret_key'] ?? '');
        } else {
            return place_tabdeal_order($side, $amount, $config['tabdeal_api_key'] ?? '', $config['tabdeal_secret_key'] ?? '');
        }
    }
}

function place_nobitex_order($side, $amount_rls, $token) {
    try {
        $url = 'https://apiv2.nobitex.ir/market/orders/add';
        $payload = [
            "type" => $side,
            "srcCurrency" => "usdt",
            "dstCurrency" => "rls",
            "execution" => "market",
            "amount" => strval($amount_rls),
            "clientOrderId" => "bot" . time()
        ];

        $headers = [
            "Authorization: Token " . $token,
            "Content-Type: application/json"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ["error" => "Curl error: " . $error];
        }

        curl_close($ch);
        return json_decode($response, true);
    } catch (Exception $e) {
        return ["error" => $e->getMessage()];
    }
}

function place_tabdeal_order($side, $amount, $api_key, $secret_key) {
    try {
        // Determine if this is a spot or margin (leverage) order
        // For now, using spot trading API
        $url = 'https://api.tabdeal.org/api/v1/order';
        $payload = [
            "symbol" => "USDTIRT",
            "side" => $side == 'buy' ? 'BUY' : 'SELL',
            "type" => "MARKET",
            "quantity" => strval($amount),
            "timestamp" => time()
        ];

        $signature = generate_tabdeal_signature($api_key, $secret_key, $payload['timestamp']);
        $payload['signature'] = $signature;

        $headers = [
            "X-API-KEY: " . $api_key,
            "X-TIMESTAMP: " . $payload['timestamp'],
            "X-SIGNATURE: " . $signature,
            "Content-Type: application/json"
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);

        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ["error" => "Curl error: " . $error];
        }

        curl_close($ch);
        return json_decode($response, true);
    } catch (Exception $e) {
        return ["error" => $e->getMessage()];
    }
}

function calculate_st($df, $p, $m) {
    if (count($df) < $p) {
        foreach ($df as &$row) {
            $row['super_line'] = 0.0;
            $row['dir'] = 1;
        }
        return $df;
    }

    // محاسبه hl2
    foreach ($df as &$row) {
        $row['hl2'] = ($row['high'] + $row['low']) / 2;
    }

    // محاسبه True Range
    for ($i = 0; $i < count($df); $i++) {
        if ($i == 0) {
            $df[$i]['tr'] = $df[$i]['high'] - $df[$i]['low'];
        } else {
            $tr1 = $df[$i]['high'] - $df[$i]['low'];
            $tr2 = abs($df[$i]['high'] - $df[$i-1]['close']);
            $tr3 = abs($df[$i]['low'] - $df[$i-1]['close']);
            $df[$i]['tr'] = max($tr1, $tr2, $tr3);
        }
    }

    // محاسبه ATR
    $atr_values = [];
    for ($i = 0; $i < count($df); $i++) {
        if ($i < $p - 1) {
            $atr_values[] = 0;
        } else {
            $sum = 0;
            for ($j = $i - $p + 1; $j <= $i; $j++) {
                $sum += $df[$j]['tr'];
            }
            $atr_values[] = $sum / $p;
        }
    }

    // محاسبه Upper و Lower Band
    $up = [];
    $dn = [];
    for ($i = 0; $i < count($df); $i++) {
        $up[] = $df[$i]['hl2'] + ($m * $atr_values[$i]);
        $dn[] = $df[$i]['hl2'] - ($m * $atr_values[$i]);
    }

    // محاسبه SuperTrend
    $st = array_fill(0, count($df), null);
    $direction = array_fill(0, count($df), 1);
    $st[0] = $df[0]['close'] > $up[0] ? $dn[0] : $up[0];
    for ($i = 1; $i < count($df); $i++) {
        if ($df[$i]['close'] > $up[$i-1]) {
            $direction[$i] = 1;
        } elseif ($df[$i]['close'] < $dn[$i-1]) {
            $direction[$i] = -1;
        } else {
            $direction[$i] = $direction[$i-1];
        }

        if ($direction[$i] == 1 && $dn[$i] < $dn[$i-1]) {
            $dn[$i] = $dn[$i-1];
        }
        if ($direction[$i] == -1 && $up[$i] > $up[$i-1]) {
            $up[$i] = $up[$i-1];
        }

        $st[$i] = $direction[$i] == 1 ? $dn[$i] : $up[$i];
    }

    // ذخیره نتایج
    for ($i = 0; $i < count($df); $i++) {
        $df[$i]['super_line'] = $st[$i] !== null ? $st[$i] : 0;
        $df[$i]['dir'] = $direction[$i];
    }
    return $df;
}

function crossover($s1, $s2) {
    if (count($s1) < 2) return false;
    return ($s1[count($s1)-2] <= $s2[count($s2)-2]) && ($s1[count($s1)-1] > $s2[count($s2)-1]);
}

function crossunder($s1, $s2) {
    if (count($s1) < 2) return false;
    return ($s1[count($s1)-2] >= $s2[count($s2)-2]) && ($s1[count($s1)-1] < $s2[count($s2)-1]);
}

function calc_avg($steps) {
    return count($steps) > 0 ? array_sum($steps) / count($steps) : 0.0;
}


// ================================================================================
// بخش 5: توابع پردازش منطق
// ================================================================================
function process_logic($req) {
    global $last_processed_timeframe; // Declare global variable to track timeframes

    try {
        $mode = isset($req['mode']) ? $req['mode'] : 'sim';
        $log_file = $mode == "live" ? LIVE_LOG_FILE : SIM_LOG_FILE;

        $h = intval($req['h']);
        $p = intval($req['p']);
        $m = floatval($req['m']);
        $n_max = intval($req['n']);
        $gap_pct = floatval($req['g']) / 100.0;
        $tp_pct = floatval($req['t']) / 100.0;
        $tf_sec = intval($req['tf']);
        $code = isset($req['code']) ? $req['code'] : get_default_code();
        $exchange = isset($req['exchange']) ? $req['exchange'] : 'nobitex';

        // تبدیل tf به دقیقه برای صرافی انتخاب شده
        $tf_map = [
            "60" => "1", "300" => "5", "900" => "15", "1800" => "30",
            "3600" => "60", "7200" => "120", "14400" => "240", "21600" => "360",
            "43200" => "720", "86400" => "1440"
        ];
        $tf_value = isset($tf_map[strval($tf_sec)]) ? $tf_map[strval($tf_sec)] : "1";

        // دریافت داده‌ها
        $now = time();
        
        if ($exchange === 'nobitex') {
            $url = "https://apiv2.nobitex.ir/market/udf/history?symbol=USDTIRT&resolution={$tf_value}&from=" . ($now - ($h * 60)) . "&to={$now}";
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $res = json_decode($response, true);

            if (!$res) {
                return ["error" => "پاسخ نامعتبر از API دریافت شد"];
            }

            if (!isset($res['s']) || $res['s'] != 'ok') {
                $error_msg = isset($res['errmsg']) ? $res['errmsg'] : (isset($res['s']) ? "وضعیت: " . $res['s'] : "پاسخ نامعتبر");
                return ["error" => "داده‌های تاریخی دریافت نشدند: " . $error_msg];
            }

            // ساخت دیتافریم
            $df = [];
            for ($i = 0; $i < count($res['t']); $i++) {
                $df[] = [
                    'time' => intval($res['t'][$i]),
                    'high' => floatval($res['h'][$i]),
                    'low' => floatval($res['l'][$i]),
                    'close' => floatval($res['c'][$i])
                ];
            }
        } else { // Tabdeal
            $url = "https://api.tabdeal.org/api/v1/kline?symbol=USDTIRT&interval={$tf_value}m&limit=" . ($h/$tf_value);

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $res = json_decode($response, true);

            if (!$res) {
                return ["error" => "پاسخ نامعتبر از API دریافت شد"];
            }

            if (!isset($res['success']) || $res['success'] !== true) {
                $error_msg = isset($res['message']) ? $res['message'] : (isset($res['msg']) ? $res['msg'] : "پاسخ نامعتبر");
                return ["error" => "داده‌های تاریخی دریافت نشدند: " . $error_msg];
            }

            // ساخت دیتافریم
            $df = [];
            if (isset($res['data']) && is_array($res['data'])) {
                foreach ($res['data'] as $kline) {
                    $df[] = [
                        'time' => intval($kline[0]),
                        'high' => floatval($kline[2]),
                        'low' => floatval($kline[3]),
                        'close' => floatval($kline[4])
                    ];
                }
            }
        }

        // محاسبه SuperTrend
        $df = calculate_st($df, $p, $m);
        $last_p = $df[count($df)-1]['close'];

        // دریافت موجودی
        list($toman_bal, $usdt_bal, $bal_err) = get_balance();
        $usd_rate = get_usd_to_irt_rate();
        $total_balance_irt = $toman_bal + ($usdt_bal * $usd_rate);

        // محاسبه اندازه پله
        $step_toman = $total_balance_irt > 0 ? max(50000, $total_balance_irt / $n_max) : 0;

        // دریافت پله‌های فعلی
        $live_steps = [];
        if ($mode == "live") {
            if (!file_exists(LIVE_OPEN_LOG_FILE)) {
                $fp = fopen(LIVE_OPEN_LOG_FILE, 'w');
                fputcsv($fp, ['Price', 'Timestamp', 'Type', 'Amount_Toman', 'Volume_USDT', 'Exchange']);
                fclose($fp);
            }
            if (file_exists(LIVE_OPEN_LOG_FILE)) {
                if (($handle = fopen(LIVE_OPEN_LOG_FILE, 'r')) !== FALSE) {
                    $headers = fgetcsv($handle);
                    while (($data = fgetcsv($handle)) !== FALSE) {
                        // Check if this record belongs to the current exchange
                        if (isset($data[5]) && $data[5] === $exchange) {
                            $live_steps[] = floatval($data[0]); // Price
                        }
                    }
                    fclose($handle);
                }
            }
        } else {
            if (!file_exists($log_file)) {
                $fp = fopen($log_file, 'w');
                fputcsv($fp, ['Price', 'Timestamp', 'Type', 'Amount_Toman', 'Volume_USDT', 'Exchange']);
                fclose($fp);
            }
            if (file_exists($log_file)) {
                if (($handle = fopen($log_file, 'r')) !== FALSE) {
                    $headers = fgetcsv($handle);
                    while (($data = fgetcsv($handle)) !== FALSE) {
                        // Check if this record belongs to the current exchange
                        if (isset($data[5]) && $data[5] === $exchange) {
                            $live_steps[] = floatval($data[0]); // Price
                        }
                    }
                    fclose($handle);
                }
            }
        }

        $avg_l = calc_avg($live_steps);

        // آماد��‌سازی داده‌ها برای اجرای استراتژی
        $close = array_column($df, 'close');
        $super_line = array_column($df, 'super_line');
        $current_steps = $live_steps;

        // اجرای کد استراتژی
        $action = evaluate_strategy($close, $super_line, $current_steps, $n_max, $gap_pct, $tp_pct, $code);

        // شرایط اضافی برای فروش کل
        $last_p = $close[count($close)-1];
        $super_line_val = $super_line[count($super_line)-1];
        $has_current_steps = count($current_steps) > 0;
        $has_crossunder = crossunder($close, $super_line);
        $has_crossover = crossover($close, $super_line);

        // بررسی کراس آور در تایم فریم جدید
        $timeframe_storage_file = 'timeframe_state.json';
        $last_processed_timeframe = [];
        
        // Load previous timeframe state from file
        if (file_exists($timeframe_storage_file)) {
            $content = file_get_contents($timeframe_storage_file);
            if ($content !== false) {
                $last_processed_timeframe = json_decode($content, true);
                if ($last_processed_timeframe === null) {
                    $last_processed_timeframe = [];
                }
            }
        }
        
        $current_time = time();
        $current_timeframe = floor($current_time / $tf_sec); // Convert to timeframe periods

        // بررسی اینکه آیا در تایم فریم جدیدیم یا خیر
        $key = $tf_sec . "_sec";
        $is_new_timeframe = false;
        if (!isset($last_processed_timeframe[$key])) {
            $last_processed_timeframe[$key] = $current_timeframe - 1;
        }

        if ($last_processed_timeframe[$key] < $current_timeframe) {
            $is_new_timeframe = true;
            $last_processed_timeframe[$key] = $current_timeframe;
            
            // Save updated timeframe state to file
            file_put_contents($timeframe_storage_file, json_encode($last_processed_timeframe));
        }

        // اگر در تایم فریم جدیدیم و قیمت از سوپرترند بیشتر شده است، دستور خرید صادر شود
        // فقط اگر در تایم فریم قبلی قیمت از سوپرترند کمتر بوده باشد
        if ($is_new_timeframe) {
            // بررسی اینکه آیا قبل از این تایم فریم جدید، قیمت زیر سوپرترند بوده
            $previous_price = count($close) > 1 ? $close[count($close)-2] : $last_p;
            $previous_super = count($super_line) > 1 ? $super_line[count($super_line)-2] : $super_line_val;

            if ($previous_price < $previous_super && $last_p > $super_line_val && !$has_current_steps) {
                error_log("NEW TIMEFRAME CROSSOVER: Previous price {$previous_price} < SuperTrend {$previous_super}, Current price {$last_p} > SuperTrend {$super_line_val}");
                $action = 'BUY_FIRST_STEP';

                // ارسال نوتیفیکیشن برای کراس آور در تایم فریم جدید
                $notification_method = 'bale'; // default
                if (file_exists(CONFIG_FILE)) {
                    $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
                    if ($cfg && isset($cfg['notification_method'])) {
                        $notification_method = $cfg['notification_method'];
                    }
                }

                $message = "
                🔄 کراس آور تایم فریم جدید شناسایی شد - تریدر {$exchange}
                قیمت: " . number_format($last_p) . " تومان
                خط سوپرترند: " . number_format($super_line_val) . " تومان
                نوع عمل: خرید اولین پله
                تایم فریم: {$tf_sec} ثانیه
                زمان: " . date('Y-m-d H:i:s');

                if ($notification_method == 'bale') {
                    send_bale_notification($message);
                } elseif ($notification_method == 'telegram') {
                    send_telegram_notification($message);
                } elseif ($notification_method == 'email') {
                    $email_subject = "🔄 کراس آور تایم فریم جدید شناسایی شد - تریدر {$exchange}";
                    $email_body = $message;
                    send_email_notification($email_subject, $email_body);
                }
            }
        }

        // اگر پوزیشن داریم و قیمت زیر سوپرترند رفت، فروش کل
        if ($has_current_steps && $has_crossunder) {
            if ($action != 'SELL_ALL') {
                error_log("OVERRIDING: Crossunder detected - Price {$last_p} below SuperTrend {$super_line_val}");
                $action = 'SELL_ALL';
                
                // ارسال نوتیفیکیشن برای کراس آندر
                $notification_method = 'bale'; // default
                if (file_exists(CONFIG_FILE)) {
                    $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
                    if ($cfg && isset($cfg['notification_method'])) {
                        $notification_method = $cfg['notification_method'];
                    }
                }
                
                $message = "
                ⚠️ کراس آندر شناسایی شد - تریدر {$exchange}
                قیمت: " . number_format($last_p) . " تومان
                خط سوپرترند: " . number_format($super_line_val) . " تومان
                نوع عمل: فروش کل
                زمان: " . date('Y-m-d H:i:s');
                
                if ($notification_method == 'bale') {
                    send_bale_notification($message);
                } elseif ($notification_method == 'telegram') {
                    send_telegram_notification($message);
                } elseif ($notification_method == 'email') {
                    $email_subject = "⚠️ کراس آندر شناسایی شد - تریدر {$exchange}";
                    $email_body = $message;
                    send_email_notification($email_subject, $email_body);
                }
            }
        }
        // اگر پوزیشن نداریم و قیمت بالای سوپرترند رفت، خرید
        // این بخش باید زمانی اجرا شود که کراس آور اتفاق بیفتد و پله‌ای وجود نداشته باشد
        elseif (!$has_current_steps && $has_crossover) {
            error_log("CROSSOVER DETECTED: No current steps, triggering BUY_FIRST_STEP - Price {$last_p} above SuperTrend {$super_line_val}");
            $action = 'BUY_FIRST_STEP';

            // ارسال نوتیفیکیشن برای کراس آور
            $notification_method = 'bale'; // default
            if (file_exists(CONFIG_FILE)) {
                $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
                if ($cfg && isset($cfg['notification_method'])) {
                    $notification_method = $cfg['notification_method'];
                }
            }

            $message = "
            🔄 کراس آور شناسایی شد - تریدر {$exchange}
            قیمت: " . number_format($last_p) . " تومان
            خط سوپرترند: " . number_format($super_line_val) . " تومان
            نوع عمل: خرید اولین پله
            زمان: " . date('Y-m-d H:i:s');

            if ($notification_method == 'bale') {
                send_bale_notification($message);
            } elseif ($notification_method == 'telegram') {
                send_telegram_notification($message);
            } elseif ($notification_method == 'email') {
                $email_subject = "🔄 کراس آور شناسایی شد - تریدر {$exchange}";
                $email_body = $message;
                send_email_notification($email_subject, $email_body);
            }
        }
        // اگر پوزیشن داریم و شرایط فروش وجود دارد
        elseif ($has_current_steps && $has_crossover && count($current_steps) < $n_max) {
            $gap_condition = true;
            if (count($current_steps) > 0) {
                $gap_condition = $last_p <= $current_steps[count($current_steps)-1] * (1 - $gap_pct);
            }
            if ($gap_condition && $action != 'BUY' && $action != 'BUY_FIRST_STEP') {
                error_log("ADDITIONAL BUY: Crossover detected with gap condition met - Price {$last_p} above SuperTrend {$super_line_val}");
                $action = 'BUY';

                // ارسال نوتیفیکیشن برای خرید اضافی
                $notification_method = 'bale'; // default
                if (file_exists(CONFIG_FILE)) {
                    $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
                    if ($cfg && isset($cfg['notification_method'])) {
                        $notification_method = $cfg['notification_method'];
                    }
                }

                $message = "
                ➕ خرید اضافی شناسایی شد - تریدر {$exchange}
                قیمت: " . number_format($last_p) . " تومان
                خط سوپرترند: " . number_format($super_line_val) . " تومان
                نوع عمل: خرید پله اضافی
                زمان: " . date('Y-m-d H:i:s');

                if ($notification_method == 'bale') {
                    send_bale_notification($message);
                } elseif ($notification_method == 'telegram') {
                    send_telegram_notification($message);
                } elseif ($notification_method == 'email') {
                    $email_subject = "➕ خرید اضافی شناسایی شد - تریدر {$exchange}";
                    $email_body = $message;
                    send_email_notification($email_subject, $email_body);
                }
            }
        }


        return [
            'action' => $action,
            'last_price' => $last_p,
            'avg_price' => $avg_l,
            'step_toman' => $step_toman,
            'usd_rate' => $usd_rate,
            'exchange' => $exchange,
            'has_crossover' => $has_crossover,
            'has_crossunder' => $has_crossunder,
            'supertrend_value' => $super_line_val
        ];
    } catch (Exception $e) {
        return ["error" => $e->getMessage()];
    }
}

function evaluate_strategy($close, $super_line, $current_steps, $n_max, $gap_pct, $tp_pct, $code) {
    // محاسبه متغیرهای کمکی
    $avg_p = calc_avg($current_steps);
    $target_p = $avg_p > 0 ? $avg_p * (1 + $tp_pct) : 0;
    $last_p = $close[count($close)-1];

    // متغیرهای محلی برای اجرای کد
    $action = null;

    // اجرای منطق استراتژی
    // 1. خرید: در صورت crossover و زیر آستانه فاصله
    // اگر تعداد پله‌ها صفر باشد، با اولین کراس آور قیمت از سوپرترند، یک پله خریداری شود
    if (crossover($close, $super_line) && count($current_steps) < $n_max) {
        if (count($current_steps) == 0) {
            // اولین خرید - بدون نیاز به فاصله از خط نقطه چین
            $action = "BUY_FIRST_STEP";
        } else {
            // خریدهای بعدی - باید زیر خط فاصله خرید بعدی باشند
            $gap_threshold = $current_steps[count($current_steps)-1] * (1 - $gap_pct);
            if ($last_p <= $gap_threshold) {
                $action = "BUY";
            }
        }
    }
    // 2. فروش آخرین پله: در صورت crossunder و پر بودن ظرفیت
    elseif (count($current_steps) == $n_max && crossunder($close, $super_line)) {
        $action = "SELL_LAST";
    }
    // 3. فروش کل: در صورت crossunder و سود
    elseif (count($current_steps) > 0 && $last_p > $target_p && crossunder($close, $super_line)) {
        $action = "SELL_ALL";
    }

    return $action;
}

function get_default_code() {
    return <<<CODE
action = None
avg_p = calc_avg(current_steps)
target_p = avg_p * (1 + tp_pct) if avg_p > 0 else 0
last_p = close.iloc[-1]

# 1. BUY: on crossover & below gap threshold
# If there are zero steps, purchase a step when price crosses over SuperTrend
if crossover(close, super_line) and len(current_steps) < n_max:
    if not current_steps or last_p <= current_steps[-1] * (1 - gap_pct):
        action = "BUY"

# 2. SELL_LAST: on crossunder when at full capacity
elif len(current_steps) == n_max and crossunder(close, super_line):
    action = "SELL_LAST"

# 3. SELL_ALL: on crossunder when in profit
elif current_steps and last_p > target_p and crossunder(close, super_line):
    action = "SELL_ALL"
CODE;
}


// ================================================================================
// بخش 6: توابع اجرای سفارش
// ================================================================================
function execute_order_api($side, $amount, $mode, $send_notifications = true, $order_type = 'BUY') {
    try {
        if (!in_array($side, ['buy', 'sell'])) {
            return ["error" => "side must be 'buy' or 'sell'"];
        }

        $log_file = $mode == "live" ? LIVE_LOG_FILE : SIM_LOG_FILE;
        $notification_errors = [];

        if ($mode == "live") {
            // اجرای واقعی
            if ($side == 'buy') {
                list($toman_bal, $usdt_bal, $err) = get_balance();
                if ($err) {
                    return ["error" => "خطا در دریافت موجودی: " . $err];
                }
                $total_bal_irt = $toman_bal + ($usdt_bal * get_usd_to_irt_rate());

                if ($amount < 50000) {
                    return ["error" => "مبلغ خرید باید حداقل 50,000 تومان باشد."];
                }

                // تبدیل مبلغ از تومان به USDT مقدار
                $price_per_usdt = get_usd_to_irt_rate();
                $amount_usdt = $amount / $price_per_usdt;

                $order_data = place_order('buy', $amount_usdt);
            } else {
                // برای فروش، مقدار باید از تومان به دلار تبدیل شود
                $price_per_usdt = get_usd_to_irt_rate();
                $amount_usdt = $amount / $price_per_usdt;

                $order_data = place_order('sell', $amount_usdt);
            }

            if (isset($order_data['error'])) {
                // ارسال نوتیفیکیشن در مورد خطا
                if ($send_notifications) {
                    $error_message = $order_data['error'];
                    $notification_method = 'bale'; // default
                    if (file_exists(CONFIG_FILE)) {
                        $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
                        if ($cfg && isset($cfg['notification_method'])) {
                            $notification_method = $cfg['notification_method'];
                        }
                    }

                    $config = get_exchange_config();
                    $exchange = $config['exchange'] ?? 'nobitex';
                    
                    $message = "
                    ❌ خطا در اجرای سفارش لایو - تریدر {$exchange}
                    نوع سفارش: " . strtoupper($side) . "
                    مبلغ: " . number_format($amount) . " تومان
                    خطای رخ داده: " . $error_message . "
                    زمان: " . date('Y-m-d H:i:s');

                    if ($notification_method == 'bale') {
                        send_bale_notification($message);
                    } elseif ($notification_method == 'telegram') {
                        send_telegram_notification($message);
                    } elseif ($notification_method == 'email') {
                        $email_subject = "❌ خطا در اجرای سفارش - تریدر {$exchange}";
                        $email_body = $message;
                        send_email_notification($email_subject, $email_body);
                    }
                }

                return ["error" => $order_data['error']];
            }

            if ($side == 'buy') {
                // ثبت خرید در لاگ
                $current_price = get_usd_to_irt_rate();
                $volume_usdt = $amount / $current_price;

                $config = get_exchange_config();
                $exchange = $config['exchange'] ?? 'nobitex';

                $fp = fopen($log_file, 'a');
                fputcsv($fp, [
                    $current_price,
                    time(),
                    'BUY',  // Using 'BUY' for all buy orders, but we can distinguish in other ways if needed
                    $amount,
                    $volume_usdt,
                    $exchange
                ]);
                fclose($fp);

                // ارسال نوتیفیکیشن فقط اگر درخواست داده شده باشد
                if ($send_notifications) {
                    $notification_method = 'bale'; // default
                    if (file_exists(CONFIG_FILE)) {
                        $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
                        if ($cfg && isset($cfg['notification_method'])) {
                            $notification_method = $cfg['notification_method'];
                        }
                    }

                    $message = "
                    ✅ اجرای سفارش لایو - تریدر {$exchange}
                    نوع سفارش: خرید
                    مبلغ: " . number_format($amount) . " تومان
                    قیمت: " . number_format($current_price) . " تومان
                    حجم: " . number_format($volume_usdt, 4) . " دلار
                    زمان: " . date('Y-m-d H:i:s');

                    if ($notification_method == 'bale') {
                        send_bale_notification($message);
                    } elseif ($notification_method == 'telegram') {
                        send_telegram_notification($message);
                    } elseif ($notification_method == 'email') {
                        $email_subject = "✅ اجرای سفارش خرید - تریدر {$exchange}";
                        $email_body = $message;
                        send_email_notification($email_subject, $email_body);
                    }
                }
            } else {
                // برای فروش
                
                $config = get_exchange_config();
                $exchange = $config['exchange'] ?? 'nobitex';
                
                $current_price = get_usd_to_irt_rate();
                $volume_usdt = $amount / $current_price;

                // ارسال نوتیفیکیشن فقط اگر درخواست داده شده باشد
                if ($send_notifications) {
                    $notification_method = 'bale'; // default
                    if (file_exists(CONFIG_FILE)) {
                        $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
                        if ($cfg && isset($cfg['notification_method'])) {
                            $notification_method = $cfg['notification_method'];
                        }
                    }

                    $message = "
                    ✅ اجرای سفارش لایو - تریدر {$exchange}
                    نوع سفارش: فروش
                    مبلغ: " . number_format($amount) . " تومان
                    قیمت: " . number_format($current_price) . " تومان
                    حجم: " . number_format($volume_usdt, 4) . " دلار
                    زمان: " . date('Y-m-d H:i:s');

                    if ($notification_method == 'bale') {
                        send_bale_notification($message);
                    } elseif ($notification_method == 'telegram') {
                        send_telegram_notification($message);
                    } elseif ($notification_method == 'email') {
                        $email_subject = "✅ اجرای سفارش فروش - تریدر {$exchange}";
                        $email_body = $message;
                        send_email_notification($email_subject, $email_body);
                    }
                }
            }

            return ["status" => "ok", "data" => $order_data, "notification_errors" => $notification_errors];
        } else {
            // حالت شبیه‌سازی
            if ($side == 'buy') {
                $current_price = get_usd_to_irt_rate();
                $volume_usdt = $amount / $current_price;

                $config = get_exchange_config();
                $exchange = $config['exchange'] ?? 'nobitex';

                $fp = fopen($log_file, 'a');
                fputcsv($fp, [
                    $current_price,
                    time(),
                    $order_type,
                    $amount,
                    $volume_usdt,
                    $exchange
                ]);
                fclose($fp);
            } else {
                // برای فروش در حالت شبیه‌سازی
                $current_price = get_usd_to_irt_rate();
                $volume_usdt = $amount / $current_price;

                $config = get_exchange_config();
                $exchange = $config['exchange'] ?? 'nobitex';

                $fp = fopen($log_file, 'a');
                fputcsv($fp, [
                    $current_price,
                    time(),
                    $order_type,
                    $amount,
                    $volume_usdt,
                    $exchange
                ]);
                fclose($fp);
            }
            return ["status" => "ok", "data" => ["message" => "Order executed in simulation mode"], "notification_errors" => $notification_errors];
        }
    } catch (Exception $e) {
        // ارسال نوتیفیکیشن در مورد خطا
        if ($send_notifications) {
            $notification_method = 'bale'; // default
            if (file_exists(CONFIG_FILE)) {
                $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
                if ($cfg && isset($cfg['notification_method'])) {
                    $notification_method = $cfg['notification_method'];
                }
            }

            $config = get_exchange_config();
            $exchange = $config['exchange'] ?? 'nobitex';

            $message = "
            ❌ خطا در اجرای سفارش - تریدر {$exchange}
            نوع سفارش: " . strtoupper($side) . "
            مبلغ: " . number_format($amount) . " تومان
            خطای رخ داده: " . $e->getMessage() . "
            زمان: " . date('Y-m-d H:i:s');

            if ($notification_method == 'bale') {
                send_bale_notification($message);
            } elseif ($notification_method == 'telegram') {
                send_telegram_notification($message);
            } elseif ($notification_method == 'email') {
                $email_subject = "❌ خطا در اجرای سفارش - تریدر {$exchange}";
                $email_body = $message;
                send_email_notification($email_subject, $email_body);
            }
        }

        return ["error" => $e->getMessage()];
    }
}

function send_auto_trade_notification($action, $amount, $price) {
    try {
        // Get notification method from config
        $notification_method = 'bale'; // default
        if (file_exists(CONFIG_FILE)) {
            $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
            if ($cfg && isset($cfg['notification_method'])) {
                $notification_method = $cfg['notification_method'];
            }
        }

        $config = get_exchange_config();
        $exchange = $config['exchange'] ?? 'nobitex';
        
        $message = "
        🤖 AUTO-TRADE ({$exchange}):
        نوع: " . $action . "
        مبلغ: " . $amount . "
        قیمت: " . number_format($price) . " تومان
        زمان: " . date('Y-m-d H:i:s');

        $success = false;
        $error_details = '';

        // Send based on selected method
        if ($notification_method == 'bale') {
            $method_name = 'Bale';
            $result = send_bale_notification($message);
            if (is_array($result)) {
                $success = $result['success'];
                $error_details = $result['error'];
            } else {
                $success = $result;
            }
        } elseif ($notification_method == 'telegram') {
            $method_name = 'Telegram';
            $result = send_telegram_notification($message);
            if (is_array($result)) {
                $success = $result['success'];
                $error_details = $result['error'];
            } else {
                $success = $result;
            }
        } elseif ($notification_method == 'email') {
            $method_name = 'Email';
            $email_subject = "🤖 سفارش اتوما������یک اجرا شد - " . $action;
            $email_body = "
            اجرای سفارش اتوماتیک {$exchange}!
            نوع سفارش: " . $action . "
            مبلغ: " . $amount . "
            قیمت: " . number_format($price) . " تومان
            زمان: " . date('Y-m-d H:i:s') . "
            این پیام به صورت خودکار از سیستم تریدر اتوماتیک ارسال شده است.
            ";
            $result = send_email_notification($email_subject, $email_body);
            if (is_array($result)) {
                $success = $result['success'];
                $error_details = $result['error'];
            } else {
                $success = $result;
            }
        } else {
            echo json_encode(["error" => "روش نوتیفیکیشن نامعتبر: $notification_method"]);
            return;
        }

        if ($success) {
            echo json_encode(["status" => "ok", "message" => "نوتیفیکیشن تستی با موفقیت ارسال شد via $method_name."]);
        } else {
            $error_message = "خطا در ار��ال نوتیفیکیشن via $method_name";
            if (!empty($error_details)) {
                $error_message .= "\nجزئیات خطا: " . $error_details;
            } else {
                 $error_message .= "\nپاسخ کامل: " . json_encode($result, JSON_UNESCAPED_UNICODE);
            }
            echo json_encode(["error" => $error_message]);
        }
    } catch (Exception $e) {
        error_log("Error sending auto-trade notification: " . $e->getMessage());
        return false;
    }
}

function auto_trade() {
    // Always run in live mode
    $mode = 'live';

    // Load default config
    $cfg = [
        "h" => 720, "p" => 7, "m" => 3.0, "n" => 5, "g" => 1.0, "t" => 1.5,
        "tf" => "60", "fetch" => 10, "chartLib" => "tradingview", "code" => get_default_code(), "mode" => "live", "exchange" => "nobitex"
    ];

    if (file_exists(CONFIG_FILE)) {
        $loaded = json_decode(file_get_contents(CONFIG_FILE), true);
        if ($loaded) {
            $cfg = array_merge($cfg, $loaded);
        }
    }

    // Check both exchanges regardless of configuration
    $exchanges = ['nobitex', 'tabdeal'];

    $results = [];

    foreach ($exchanges as $exchange) {
        // Prepare request for this exchange
        $req = [
            'h' => isset($_GET['h']) ? intval($_GET['h']) : $cfg['h'],
            'p' => isset($_GET['p']) ? intval($_GET['p']) : $cfg['p'],
            'm' => isset($_GET['m']) ? floatval($_GET['m']) : $cfg['m'],
            'n' => isset($_GET['n']) ? intval($_GET['n']) : $cfg['n'],
            'g' => isset($_GET['g']) ? floatval($_GET['g']) : $cfg['g'],
            't' => isset($_GET['t']) ? floatval($_GET['t']) : $cfg['t'],
            'tf' => isset($_GET['tf']) ? intval($_GET['tf']) : intval($cfg['tf']),
            'code' => isset($_GET['code']) ? $_GET['code'] : $cfg['code'],
            'mode' => $mode,
            'exchange' => $exchange
        ];

        // Process the logic to determine action for this exchange
        $result = process_logic($req);

        if (isset($result['error'])) {
            $results[$exchange] = ['error' => $result['error']];
            continue;
        }

        $action = $result['action'];
        $step_toman = $result['step_toman'];
        $exchange_name = $result['exchange'];
        $supertrend_value = $result['supertrend_value'];

        // Handle crossover/crossunder notifications in auto_trade mode
        if (isset($result['has_crossover']) && $result['has_crossover']) {
            // Send notification for crossover
            $notification_method = 'bale'; // default
            if (file_exists(CONFIG_FILE)) {
                $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
                if ($cfg && isset($cfg['notification_method'])) {
                    $notification_method = $cfg['notification_method'];
                }
            }

            $message = "
            🔄 کراس آور شناسایی شد - تریدر {$exchange}
            قیمت: " . number_format($result['last_price']) . " تومان
            خط سوپرترند: " . number_format($supertrend_value) . " تومان
            نوع عمل: " . ($action ?: 'هیچ') . "
            زمان: " . date('Y-m-d H:i:s');

            if ($notification_method == 'bale') {
                send_bale_notification($message);
            } elseif ($notification_method == 'telegram') {
                send_telegram_notification($message);
            } elseif ($notification_method == 'email') {
                $email_subject = "🔄 کراس آور شناسایی شد - تریدر {$exchange}";
                $email_body = $message;
                send_email_notification($email_subject, $email_body);
            }
        }

        if (isset($result['has_crossunder']) && $result['has_crossunder']) {
            // Send notification for crossunder
            $notification_method = 'bale'; // default
            if (file_exists(CONFIG_FILE)) {
                $cfg = json_decode(file_get_contents(CONFIG_FILE), true);
                if ($cfg && isset($cfg['notification_method'])) {
                    $notification_method = $cfg['notification_method'];
                }
            }

            $message = "
            ⚠️ کراس آندر شناسایی شد - تریدر {$exchange}
            قیمت: " . number_format($result['last_price']) . " تومان
            خط سوپرترند: " . number_format($supertrend_value) . " تومان
            نوع عمل: " . ($action ?: 'هیچ') . "
            زمان: " . date('Y-m-d H:i:s');

            if ($notification_method == 'bale') {
                send_bale_notification($message);
            } elseif ($notification_method == 'telegram') {
                send_telegram_notification($message);
            } elseif ($notification_method == 'email') {
                $email_subject = "⚠️ کراس آندر شناسایی شد - تریدر {$exchange}";
                $email_body = $message;
                send_email_notification($email_subject, $email_body);
            }
        }

        // Only execute if there's a recommended action
        if ($action) {
            if ($action === 'BUY' || $action === 'BUY_FIRST_STEP') {
                // Execute buy order
                $order_type = ($action === 'BUY_FIRST_STEP') ? 'BUY_FIRST_STEP' : 'BUY';
                $order_result = execute_order_api('buy', $step_toman, $mode, true, $order_type);

                if (isset($order_result['error'])) {
                    $results[$exchange] = [
                        'status' => 'partial',
                        'action_taken' => $action,
                        'order_status' => 'failed',
                        'error' => $order_result['error'],
                        'analysis' => $result,
                        'supertrend_value' => $supertrend_value
                    ];
                } else {
                    $results[$exchange] = [
                        'status' => 'ok',
                        'action_taken' => $action,
                        'order_status' => 'executed',
                        'analysis' => $result,
                        'order_result' => $order_result,
                        'supertrend_value' => $supertrend_value
                    ];
                }
            } elseif ($action === 'SELL_ALL' || $action === 'SELL_LAST') {
                // For sell orders, we need to calculate the amount differently
                // Get current balance to determine sell amount
                list($toman_bal, $usdt_bal, $bal_err) = get_balance();

                if ($bal_err) {
                    $results[$exchange] = [
                        'status' => 'partial',
                        'action_taken' => $action,
                        'order_status' => 'failed',
                        'error' => 'Could not get balance: ' . $bal_err,
                        'analysis' => $result
                    ];
                    continue;
                }

                // Calculate sell amount based on USDT balance
                $usd_rate = get_usd_to_irt_rate();
                $amount_to_sell = $usdt_bal * $usd_rate; // Convert USDT to Tomans

                if ($amount_to_sell <= 0) {
                    $results[$exchange] = [
                        'status' => 'ok',
                        'action_taken' => $action,
                        'order_status' => 'skipped',
                        'message' => 'No USDT balance to sell',
                        'analysis' => $result,
                        'supertrend_value' => $supertrend_value
                    ];
                    continue;
                }

                // Execute sell order
                $order_result = execute_order_api('sell', $amount_to_sell, $mode, true);

                if (isset($order_result['error'])) {
                    $results[$exchange] = [
                        'status' => 'partial',
                        'action_taken' => $action,
                        'order_status' => 'failed',
                        'error' => $order_result['error'],
                        'analysis' => $result,
                        'supertrend_value' => $supertrend_value
                    ];
                } else {
                    $results[$exchange] = [
                        'status' => 'ok',
                        'action_taken' => $action,
                        'order_status' => 'executed',
                        'analysis' => $result,
                        'order_result' => $order_result,
                        'supertrend_value' => $supertrend_value
                    ];
                }
            } else {
                // No action needed
                $results[$exchange] = [
                    'status' => 'ok',
                    'action_recommended' => $action,
                    'analysis' => $result,
                    'supertrend_value' => $supertrend_value
                ];
            }
        } else {
            // No action recommended
            $results[$exchange] = [
                'status' => 'ok',
                'action_recommended' => $action,
                'analysis' => $result,
                'supertrend_value' => $supertrend_value
            ];
        }
    }

    // Return results for all exchanges
    echo json_encode([
        'status' => 'ok',
        'results' => $results,
        'timestamp' => time()
    ]);
}


// ================================================================================
// بخش 8: هندلر درخواست‌ها
// ================================================================================
$action = isset($_GET['action']) ? $_GET['action'] : (isset($_POST['action']) ? $_POST['action'] : '');

switch ($action) {
    case 'save_config':
        header('Content-Type: application/json; charset=utf-8');
        save_config();
        break;
    case 'reset':
        header('Content-Type: application/json; charset=utf-8');
        reset_logs();
        break;
    case 'process':
        header('Content-Type: application/json; charset=utf-8');
        process_request();
        break;
    case 'execute_order':
        header('Content-Type: application/json; charset=utf-8');
        execute_order();
        break;
    case 'auto_trade':
        header('Content-Type: application/json; charset=utf-8');
        auto_trade();
        break;
    case 'send_test_notifications':
        header('Content-Type: application/json; charset=utf-8');
        send_test_notifications();
        break;
    case 'get_exchange_config':
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(get_exchange_config());
        break;
    case 'import_nobitex_orders':
        header('Content-Type: application/json; charset=utf-8');
        import_nobitex_orders();
        break;
    case 'extract_previous_trades':
        header('Content-Type: application/json; charset=utf-8');
        extract_previous_trades();
        break;
    default:
        show_ui();
        break;
}

function save_config() {
    $new_data = json_decode(file_get_contents('php://input'), true);
    if ($new_data) {
        // Load existing config to preserve credentials
        $existing_data = [];
        if (file_exists(CONFIG_FILE)) {
            $existing_json = file_get_contents(CONFIG_FILE);
            if ($existing_json) {
                $existing_data = json_decode($existing_json, true);
                if (!$existing_data) {
                    $existing_data = [];
                }
            }
        }

        // Preserve existing credentials and other sensitive data
        $preserved_fields = ['nobitex_token', 'tabdeal_api_key', 'tabdeal_secret_key', 'exchange'];
        foreach ($preserved_fields as $field) {
            if (isset($existing_data[$field]) && !isset($new_data[$field])) {
                $new_data[$field] = $existing_data[$field];
            }
        }

        file_put_contents(CONFIG_FILE, json_encode($new_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo json_encode(["status" => "ok"]);
    } else {
        echo json_encode(["error" => "Invalid data"]);
    }
}

function reset_logs() {
    $mode = isset($_GET['mode']) ? $_GET['mode'] : 'sim';
    $file = $mode == "live" ? LIVE_LOG_FILE : SIM_LOG_FILE;
    if (file_exists($file)) {
        unlink($file);
    }
    echo json_encode(["status" => "ok"]);
}

function process_request() {
    // Try to get JSON input from POST body
    $input = file_get_contents('php://input');
    $req = json_decode($input, true);
    
    // If JSON decoding fails or returns empty, try to get parameters from GET or use defaults
    if (!$req || (is_array($req) && count($req) === 0)) {
        // Load default config from file
        $cfg = [
            "h" => 720, "p" => 7, "m" => 3.0, "n" => 5, "g" => 1.0, "t" => 1.5,
            "tf" => "60", "fetch" => 10, "chartLib" => "tradingview", "code" => get_default_code(), "mode" => "sim", "exchange" => "nobitex"
        ];
        
        if (file_exists(CONFIG_FILE)) {
            $loaded = json_decode(file_get_contents(CONFIG_FILE), true);
            if ($loaded) {
                $cfg = array_merge($cfg, $loaded);
            }
        }
        
        // Use GET parameters if available, otherwise use config values
        $req = [
            'h' => isset($_GET['h']) ? intval($_GET['h']) : $cfg['h'],
            'p' => isset($_GET['p']) ? intval($_GET['p']) : $cfg['p'],
            'm' => isset($_GET['m']) ? floatval($_GET['m']) : $cfg['m'],
            'n' => isset($_GET['n']) ? intval($_GET['n']) : $cfg['n'],
            'g' => isset($_GET['g']) ? floatval($_GET['g']) : $cfg['g'],
            't' => isset($_GET['t']) ? floatval($_GET['t']) : $cfg['t'],
            'tf' => isset($_GET['tf']) ? intval($_GET['tf']) : intval($cfg['tf']),
            'code' => isset($_GET['code']) ? $_GET['code'] : $cfg['code'],
            'mode' => isset($_GET['mode']) ? $_GET['mode'] : $cfg['mode'],
            'exchange' => isset($_GET['exchange']) ? $_GET['exchange'] : $cfg['exchange']
        ];
    }

    // Validate that we have the required parameters
    if (!isset($req['h']) || !isset($req['p']) || !isset($req['m']) || 
        !isset($req['n']) || !isset($req['g']) || !isset($req['t']) || !isset($req['tf'])) {
        echo json_encode(["error" => "Missing required parameters"]);
        return;
    }

    $mode = isset($req['mode']) ? $req['mode'] : 'sim';
    $log_file = $mode == "live" ? LIVE_LOG_FILE : SIM_LOG_FILE;
    $exchange = isset($req['exchange']) ? $req['exchange'] : 'nobitex';

    $h = intval($req['h']);
    $p = intval($req['p']);
    $m = floatval($req['m']);
    $n_max = intval($req['n']);
    $gap_pct = floatval($req['g']) / 100.0;
    $tp_pct = floatval($req['t']) / 100.0;
    $tf_sec = intval($req['tf']);

    // تبدیل tf به دقیقه
    $tf_map = [
        "60" => "1", "300" => "5", "900" => "15", "1800" => "30",
        "3600" => "60", "7200" => "120", "14400" => "240", "21600" => "360",
        "43200" => "720", "86400" => "1440"
    ];
    $tf_value = isset($tf_map[strval($tf_sec)]) ? $tf_map[strval($tf_sec)] : "1";

    // دریافت داده‌ها
    $now = time();
    
    if ($exchange === 'nobitex') {
        $url = "https://apiv2.nobitex.ir/market/udf/history?symbol=USDTIRT&resolution={$tf_value}&from=" . ($now - ($h * 60)) . "&to={$now}";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $res = json_decode($response, true);

        if (!$res) {
            echo json_encode(["error" => "پاسخ نامعتبر از API دریافت شد"]);
            return;
        }

        if (!isset($res['s']) || $res['s'] != 'ok') {
            $error_msg = isset($res['errmsg']) ? $res['errmsg'] : (isset($res['s']) ? "وضعیت: " . $res['s'] : "پاسخ نامعتبر");
            echo json_encode(["error" => "داده‌های تاریخی دریافت نشدند: " . $error_msg]);
            return;
        }

        // ساخت دیتافریم
        $df = [];
        for ($i = 0; $i < count($res['t']); $i++) {
            $df[] = [
                'time' => intval($res['t'][$i]),
                'high' => floatval($res['h'][$i]),
                'low' => floatval($res['l'][$i]),
                'close' => floatval($res['c'][$i])
            ];
        }
    } else { // Tabdeal
        $url = "https://api.tabdeal.org/api/v1/kline?symbol=USDTIRT&interval={$tf_value}m&limit=" . ($h/$tf_value);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $res = json_decode($response, true);

        if (!$res) {
            echo json_encode(["error" => "پاسخ نامعتبر از API دریافت شد"]);
            return;
        }

        if (!isset($res['success']) || $res['success'] !== true) {
            $error_msg = isset($res['message']) ? $res['message'] : (isset($res['msg']) ? $res['msg'] : "پاسخ نامعتبر");
            echo json_encode(["error" => "داده‌های تاریخی دریافت نشدند: " . $error_msg]);
            return;
        }

        // ساخت دیتافریم
        $df = [];
        if (isset($res['data']) && is_array($res['data'])) {
            foreach ($res['data'] as $kline) {
                $df[] = [
                    'time' => intval($kline[0]),
                    'high' => floatval($kline[2]),
                    'low' => floatval($kline[3]),
                    'close' => floatval($kline[4])
                ];
            }
        }
    }

    // محاسبه SuperTrend
    $df = calculate_st($df, $p, $m);
    $last_p = $df[count($df)-1]['close'];

    // دریافت موجودی
    list($toman_bal, $usdt_bal, $bal_err) = get_balance();
    $usd_rate = get_usd_to_irt_rate();
    $total_balance_irt = $toman_bal + ($usdt_bal * $usd_rate);

    if ($bal_err) {
        $bal_html = "<span style='color:#ff6b6b'>❌ " . htmlspecialchars($bal_err) . "</span>";
    } else {
        $bal_html = "
        <div style='text-align: right;'>
        <strong>موجودی تومانی:</strong> " . number_format($toman_bal) . " تومان<br>
        <strong>موجودی دلاری:</strong> " . number_format($usdt_bal, 4) . " دلار<br>
        <strong>نرخ دلار:</strong> " . number_format($usd_rate) . " تومان<br>
        <strong>موجودی کل:</strong> " . number_format($total_balance_irt) . " تومان
        </div>";
    }

    // محاسبه اندازه پله
    $step_toman = $total_balance_irt > 0 ? max(50000, $total_balance_irt / $n_max) : 0;
    $step_usd = $usd_rate > 0 ? $step_toman / $usd_rate : 0;

    // دریافت پله‌های لایو
    if (!file_exists($log_file)) {
        $fp = fopen($log_file, 'w');
        fputcsv($fp, ['Price', 'Timestamp', 'Type', 'Amount_Toman', 'Volume_USDT', 'Exchange']);
        fclose($fp);
    }
    $live_steps = [];
    if (($handle = fopen($log_file, 'r')) !== FALSE) {
        $headers = fgetcsv($handle);
        while (($data = fgetcsv($handle)) !== FALSE) {
            // Check if this record belongs to the current exchange
            if (isset($data[5]) && $data[5] === $exchange) {
                $live_steps[] = floatval($data[0]);
            }
        }
        fclose($handle);
    }
    $avg_l = calc_avg($live_steps);

    $l_pnl_t = $avg_l > 0 ? ($last_p - $avg_l) * (count($live_steps) * $step_toman / $avg_l) : 0;
    $l_pnl_p = $avg_l > 0 ? (($last_p / $avg_l) - 1) * 100 : 0;

    // بک‌تست
    $bt_steps = [];
    $bt_log = [];
    $bt_realized_pnl = 0;
    $buy_x = []; $buy_y = []; $sell_x = []; $sell_y = [];

    // Initialize bt_capital variable that was missing
    $bt_capital = $total_balance_irt; // Use total balance as initial capital

    for ($i = max($p, 2); $i < count($df); $i++) {
        $scope = array_slice($df, 0, $i + 1);
        $close = array_column($scope, 'close');
        $super_line = array_column($scope, 'super_line');
        $current_steps = $bt_steps;

        $avg_p = calc_avg($current_steps);
        $target_p = $avg_p > 0 ? $avg_p * (1 + $tp_pct) : 0;
        $last_p_bt = $close[count($close)-1];

        // منطق استراتژی
        $action = null;
        if (crossover($close, $super_line) && count($current_steps) < $n_max) {
            if (count($current_steps) == 0) {
                // اولین خرید - بدون نیاز به فاصله از خط نقطه چین
                $action = "BUY_FIRST_STEP";
            } else {
                // خریدهای بعدی - باید زیر خط فاصله خرید بعدی باشند
                $gap_threshold = $current_steps[count($current_steps)-1] * (1 - $gap_pct);
                if ($last_p_bt <= $gap_threshold) {
                    $action = "BUY";
                }
            }
        } elseif (count($current_steps) == $n_max && crossunder($close, $super_line)) {
            $action = "SELL_LAST";
        } elseif (count($current_steps) > 0 && $last_p_bt > $target_p && crossunder($close, $super_line)) {
            $action = "SELL_ALL";
        }

        // اجرای اکشن‌ها
        if (($action == "BUY" || $action == "BUY_FIRST_STEP") && count($bt_steps) < $n_max) {
            $bt_steps[] = $last_p_bt;
            $buy_x[] = $i;
            $buy_y[] = $last_p_bt;
        } elseif ($action == "SELL_ALL" && count($bt_steps) > 0) {
            $avg_bt = calc_avg($bt_steps);
            $qty = count($bt_steps) * ($bt_capital / $n_max) / $avg_bt;
            $pnl = ($last_p_bt - $avg_bt) * $qty;
            $bt_realized_pnl += $pnl;
            $bt_log[] = "✅ فروش کل در " . number_format($last_p_bt) . " | سود: " . number_format($pnl) . " تومان";
            $bt_steps = [];
            $sell_x[] = $i;
            $sell_y[] = $last_p_bt;
        } elseif ($action == "SELL_LAST" && count($bt_steps) > 0) {
            $bt_log[] = "⚠️ تخلیه پله آخر در " . number_format($last_p_bt);
            array_pop($bt_steps);
            $sell_x[] = $i;
            $sell_y[] = $last_p_bt;
        }
    }

    // PnL باز برای بک‌تست
    $bt_open_pnl_t = 0;
    $bt_open_pnl_p = 0;
    if (count($bt_steps) > 0) {
        $avg_bt = calc_avg($bt_steps);
        $qty = count($bt_steps) * ($bt_capital / $n_max) / $avg_bt;
        $bt_open_pnl_t = ($last_p - $avg_bt) * $qty;
        $bt_open_pnl_p = (($last_p / $avg_bt) - 1) * 100;
    }

    // دارایی نهایی
    $final_capital = $bt_capital + $bt_realized_pnl + $bt_open_pnl_t;
    $initial_capital = $bt_capital;

    // گزارش بک‌تست
    $total_trades = count($bt_log);
    $profit_trades = 0;
    foreach ($bt_log as $log) {
        if (strpos($log, 'سود:') !== false) {
            preg_match('/سود:\s*([\+\-]?[\d,]+)/', $log, $matches);
            if (isset($matches[1]) && intval(str_replace(',', '', $matches[1])) > 0) {
                $profit_trades++;
            }
        }
    }
    $loss_trades = $total_trades - $profit_trades;
    $avg_buy_price = count($bt_steps) > 0 ? calc_avg($bt_steps) : 0;
    $last_trade = count($bt_log) > 0 ? $bt_log[count($bt_log)-1] : '-';

    // داده‌های نمودار
    $chart_x = array_column($df, 'time');
    $chart_y = array_column($df, 'close');
    $st_vals = array_column($df, 'super_line');
    $dir_vals = array_column($df, 'dir');

    // خواندن معاملات واقعی از لاگ
    $actual_buy_x = []; $actual_buy_y = []; $actual_sell_x = []; $actual_sell_y = [];
    $read_log_file = $mode == "live" ? LIVE_OPEN_LOG_FILE : $log_file;
    if (file_exists($read_log_file)) {
        if (($handle = fopen($read_log_file, 'r')) !== FALSE) {
            $headers = fgetcsv($handle);
            while (($data = fgetcsv($handle)) !== FALSE) {
                // Check if this record belongs to the current exchange
                if (isset($data[5]) && $data[5] === $exchange) {
                    $price = floatval($data[0]);
                    $type = isset($data[2]) ? $data[2] : 'BUY'; // Default to BUY if not specified
                    $timestamp = isset($data[1]) ? intval($data[1]) : 0;
                    if ($timestamp > 0) {
                        $closest_idx = 0;
                        $min_diff = abs($chart_x[0] - $timestamp);
                        for ($i = 1; $i < count($chart_x); $i++) {
                            $diff = abs($chart_x[$i] - $timestamp);
                            if ($diff < $min_diff) {
                                $min_diff = $diff;
                                $closest_idx = $i;
                            }
                        }
                        
                        // Separate markers based on order type
                        if ($type === 'BUY' || $type === 'BUY_FIRST_STEP') {
                            $actual_buy_x[] = $closest_idx;
                            $actual_buy_y[] = $price;
                        } elseif ($type === 'SELL') {
                            $actual_sell_x[] = $closest_idx;
                            $actual_sell_y[] = $price;
                        }
                    }
                }
            }
            fclose($handle);
        }
    }

    // خطوط برای ��مودار - جداگانه برای هر حالت
    $lines = [
        "avg" => count($live_steps) > 0 && $avg_l > 0 ? $avg_l : 0,
        "target" => count($live_steps) > 0 && $avg_l > 0 ? $avg_l * (1 + $tp_pct) : 0,
        "gap" => count($live_steps) > 0 ? $live_steps[count($live_steps)-1] * (1 - $gap_pct) : 0
    ];

    // بررسی کراس آور و کراس آندر
    $close_prices = array_column($df, 'close');
    $super_lines = array_column($df, 'super_line');
    $has_crossover = crossover($close_prices, $super_lines);
    $has_crossunder = crossunder($close_prices, $super_lines);

    // لیست پله‌های باز
    $live_open_list = "";
    if ($mode == "live") {
        if (file_exists(LIVE_OPEN_LOG_FILE)) {
            if (($handle = fopen(LIVE_OPEN_LOG_FILE, 'r')) !== FALSE) {
                $headers = fgetcsv($handle);
                $idx = 1;
                while (($data = fgetcsv($handle)) !== FALSE) {
                    // Check if this record belongs to the current exchange
                    if (isset($data[5]) && $data[5] === $exchange) {
                        $price = isset($data[0]) ? intval($data[0]) : 0;
                        $amount = isset($data[3]) ? intval($data[3]) : 0;
                        $volume = isset($data[4]) ? floatval($data[4]) : 0;
                        $live_open_list .= "<li>پله {$idx}: قیمت " . number_format($price) . " تومان";
                        if ($amount > 0) $live_open_list .= ", مقدار " . number_format($amount) . " تومان";
                        if ($volume > 0) $live_open_list .= ", حجم " . number_format($volume, 4) . " دلار";
                        $live_open_list .= "</li>";
                        $idx++;
                    }
                }
                fclose($handle);
            }
        }
    } else {
        if (file_exists($log_file)) {
            if (($handle = fopen($log_file, 'r')) !== FALSE) {
                $headers = fgetcsv($handle);
                $idx = 1;
                while (($data = fgetcsv($handle)) !== FALSE) {
                    // Check if this record belongs to the current exchange
                    if (isset($data[5]) && $data[5] === $exchange) {
                        $price = isset($data[0]) ? intval($data[0]) : 0;
                        $amount = isset($data[3]) ? intval($data[3]) : 0;
                        $volume = isset($data[4]) ? floatval($data[4]) : 0;
                        $live_open_list .= "<li>پله {$idx}: قیمت " . number_format($price) . " تومان";
                        if ($amount > 0) $live_open_list .= ", مقدار " . number_format($amount) . " تومان";
                        if ($volume > 0) $live_open_list .= ", حجم " . number_format($volume, 4) . " دلار";
                        $live_open_list .= "</li>";
                        $idx++;
                    }
                }
                fclose($handle);
            }
        }
    }
    if (empty($live_open_list)) {
        $live_open_list = "پله بازی وجود ندارد";
    }

    // تاریخچه معاملات
    $live_history_content = "";
    if ($mode == "live") {
        if (file_exists(LIVE_COMPLETED_LOG_FILE)) {
            if (($handle = fopen(LIVE_COMPLETED_LOG_FILE, 'r')) !== FALSE) {
                $headers = fgetcsv($handle);
                $trades = [];
                while (($data = fgetcsv($handle)) !== FALSE) {
                    // Check if this record belongs to the current exchange
                    if (isset($data[9]) && $data[9] === $exchange) {
                        $trades[] = $data;
                    }
                }
                fclose($handle);

                // نمایش 10 معامله آخر
                $recent_trades = array_slice(array_reverse($trades), 0, 10);
                foreach ($recent_trades as $idx => $row) {
                    $price = isset($row[0]) ? intval($row[0]) : 0;
                    $amount = isset($row[3]) ? intval($row[3]) : 0;
                    $volume = isset($row[4]) ? floatval($row[4]) : 0;
                    $trade_info = "معامله " . ($idx+1) . ": قیمت " . number_format($price) . " تومان";
                    if ($amount > 0) $trade_info .= ", مقدار " . number_format($amount) . " تومان";
                    if ($volume > 0) $trade_info .= ", حجم " . number_format($volume, 4) . " دلار";
                    $live_history_content .= $trade_info . "<br>";
                }
            }
        }
    } else {
        if (file_exists($log_file)) {
            if (($handle = fopen($log_file, 'r')) !== FALSE) {
                $headers = fgetcsv($handle);
                $trades = [];
                while (($data = fgetcsv($handle)) !== FALSE) {
                    // Check if this record belongs to the current exchange
                    if (isset($data[5]) && $data[5] === $exchange) {
                        $trades[] = $data;
                    }
                }
                fclose($handle);

                $recent_trades = array_slice(array_reverse($trades), 0, 10);
                foreach ($recent_trades as $idx => $row) {
                    $price = isset($row[0]) ? intval($row[0]) : 0;
                    $amount = isset($row[3]) ? intval($row[3]) : 0;
                    $volume = isset($row[4]) ? floatval($row[4]) : 0;
                    $trade_info = "معامله " . ($idx+1) . ": قیمت " . number_format($price) . " تومان";
                    if ($amount > 0) $trade_info .= ", مقدار " . number_format($amount) . " تومان";
                    if ($volume > 0) $trade_info .= ", حجم " . number_format($volume, 4) . " دلار";
                    $live_history_content .= $trade_info . "<br>";
                }
            }
        }
    }
    if (empty($live_history_content)) {
        $live_history_content = "تاریخچه‌ای وجود ندارد";
    }

    // پاسخ نهایی
    $response = [
        "bal_html" => $bal_html,
        "usdt_balance" => $usdt_bal,
        "step_html" => number_format($step_usd, 4) . " $ (" . number_format($step_toman) . " تومان)",
        "live_steps" => count($live_steps) . " / " . $n_max,
        "pnl_html" => "<b style='color:" . ($l_pnl_t >= 0 ? '#2ecc71' : '#e74c3c') . "'>" . number_format($l_pnl_t) . " تومان</b> (" . number_format($l_pnl_p, 2) . "%)",
        "bt_report" => "سود/زیان معاملات بسته: " . number_format($bt_realized_pnl) . " تومان",
        "bt_open_info" => "PNL پله‌های باز: " . number_format($bt_open_pnl_t) . " تومان (" . number_format($bt_open_pnl_p, 2) . "%) | <b>تعداد پله باز: " . count($bt_steps) . "</b>",
        "bt_open_list" => implode("", array_map(function($idx, $p) {
            return "<li>پله " . ($idx+1) . ": " . number_format($p) . " تومان</li>";
        }, array_keys($bt_steps), $bt_steps)) ?: "پله بازی وجود ندارد",
        "bt_history" => implode("<br>", array_reverse($bt_log)) ?: "تاریخچه‌ای وجود ندارد",
        "bt_total_profit" => number_format($bt_realized_pnl) . " تومان",
        "bt_total_trades" => $total_trades,
        "bt_profit_trades" => $profit_trades,
        "bt_loss_trades" => $loss_trades,
        "bt_avg_buy_price" => $avg_buy_price > 0 ? number_format($avg_buy_price) : '-',
        "bt_last_trade" => $last_trade,
        "bt_initial_capital" => number_format($initial_capital) . " تومان",
        "bt_final_capital" => number_format($final_capital) . " تومان",
        "live_open_list" => $live_open_list,
        "live_history_content" => $live_history_content,
        "chart" => [
            "x" => $chart_x,
            "y" => $chart_y,
            "st" => $st_vals,
            "dir" => $dir_vals,
            "bx" => $buy_x,
            "by" => $buy_y,
            "sx" => $sell_x,
            "sy" => $sell_y,
            "abx" => $actual_buy_x,
            "aby" => $actual_buy_y,
            "asx" => $actual_sell_x,
            "asy" => $actual_sell_y
        ],
        "lines" => $lines,
        "exchange" => $exchange,
        "has_crossover" => $has_crossover ?? false
    ];

    header('Content-Type: application/json');
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
}

function execute_order() {
    $data = json_decode(file_get_contents('php://input'), true);
    if (!$data) {
        echo json_encode(["error" => "Invalid request"]);
        return;
    }

    $side = isset($data['side']) ? strtolower($data['side']) : '';
    $amount = isset($data['amount']) ? floatval($data['amount']) : 0;
    $mode = isset($data['mode']) ? $data['mode'] : 'sim';
    $order_type = isset($data['order_type']) ? $data['order_type'] : 'BUY';

    if (!in_array($side, ['buy', 'sell'])) {
        echo json_encode(["error" => "side must be 'buy' or 'sell'"]);
        return;
    }

    $result = execute_order_api($side, $amount, $mode, true, $order_type);
    echo json_encode($result);
}

function send_test_notifications() {
    $data = json_decode(file_get_contents('php://input'), true);
    $notification_method = isset($data['notification_method']) ? $data['notification_method'] : 'bale'; // default to bale if not provided

    $test_message = "🧪 تست نوتیفیکیشن - تریدر چند صرافی\nزمان: " . date('Y-m-d H:i:s');

    $success = false;
    $error_details = '';
    $method_name = '';

    if ($notification_method == 'bale') {
        $method_name = 'Bale';
        $result = send_bale_notification($test_message);
        if (is_array($result)) {
            $success = $result['success'];
            $error_details = $result['error'];
        } else {
            $success = $result;
        }
    } elseif ($notification_method == 'telegram') {
        $method_name = 'Telegram';
        $result = send_telegram_notification($test_message);
        if (is_array($result)) {
            $success = $result['success'];
            $error_details = $result['error'];
        } else {
            $success = $result;
        }
    } elseif ($notification_method == 'email') {
        $method_name = 'Email';
        $email_subject = "🧪 تست نوتیفیکیشن - تریدر چند صرافی";
        $email_body = "
        این یک پیام تستی است.
        سیستم نوتیفیکیشن ایمیلی به درستی کار می‌کند.
        زمان تست: " . date('Y-m-d H:i:s');
        $result = send_email_notification($email_subject, $email_body);
        if (is_array($result)) {
            $success = $result['success'];
            $error_details = $result['error'];
        } else {
            $success = $result;
        }
    } else {
         echo json_encode(["error" => "روش نوتیفیکیشن نامعتبر: $notification_method"]);
         return;
    }

    if ($success) {
        echo json_encode(["status" => "ok", "message" => "نوتیفیکیشن تستی با موفقیت ارسال شد via $method_name."]);
    } else {
        $error_message = "خطا در ارسال نوتیفیکیشن via $method_name";
        if (!empty($error_details)) {
            $error_message .= "\nجزئیات خطا: " . $error_details;
        } else {
             $error_message .= "\nپاسخ کامل: " . json_encode($result, JSON_UNESCAPED_UNICODE);
        }
        echo json_encode(["error" => $error_message]);
    }
}

function import_nobitex_orders() {
    $config = get_exchange_config();
    $token = $config['nobitex_token'] ?? '';

    if (empty($token)) {
        echo json_encode(["error" => "توکن نوبیتکس تنظیم نشده است"]);
        return;
    }

    $result = import_nobitex_orders_to_steps($token);
    echo json_encode($result);
}

function extract_previous_trades() {
    $mode = isset($_GET['mode']) ? $_GET['mode'] : 'sim';
    $log_file = $mode == "live" ? LIVE_LOG_FILE : SIM_LOG_FILE;
    
    $trade_count = 0;
    
    // Count the number of trades in the log file
    if (file_exists($log_file)) {
        if (($handle = fopen($log_file, 'r')) !== FALSE) {
            $headers = fgetcsv($handle);
            while (($data = fgetcsv($handle)) !== FALSE) {
                $trade_count++;
            }
            fclose($handle);
        }
    }
    
    echo json_encode([
        "status" => "ok", 
        "extracted_count" => $trade_count,
        "message" => "تعداد {$trade_count} معامله از تاریخچه استخراج شد"
    ]);
}


// ================================================================================
// بخش 9: رابط کاربری HTML
// ================================================================================
function show_ui() {
    $cfg = [
        "h" => 720, "p" => 7, "m" => 3.0, "n" => 5, "g" => 1.0, "t" => 1.5,
        "tf" => "60", "fetch" => 10, "chartLib" => "tradingview", "code" => get_default_code(), "mode" => "sim", "exchange" => "nobitex"
    ];
    if (file_exists(CONFIG_FILE)) {
        $loaded = json_decode(file_get_contents(CONFIG_FILE), true);
        if ($loaded) {
            $cfg = array_merge($cfg, $loaded);
        }
    }
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🤖 ترید هوشمند چند صرافی V<?php echo VERSION; ?></title>
    <script src="charting_library.standalone.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@400;700&display=swap" rel="stylesheet">
    <style>
        /* کپی کامل استایل‌ها از کد اصلی */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Vazirmatn', Tahoma, sans-serif;
            background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);
            color: #ecf0f1;
            height: 100vh;
            overflow: hidden;
            display: flex;
        }
        .sidebar {
            width: 400px;
            background: rgba(25, 42, 50, 0.95);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-right: 2px solid #1abc9c;
            overflow-y: auto;
            box-shadow: 0 0 20px rgba(0,0,0,0.5);
            display: flex;
            flex-direction: column;
        }
        .main-content {
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        .chart-container {
            flex-grow: 1;
            position: relative;
        }
        .settings-btn {
            position: absolute;
            bottom: 20px;
            left: 20px;
            z-index: 10;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(to right, #f39c12, #e67e22);
            color: white;
            font-size: 24px;
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 4px 10px rgba(0,0,0,0.5);
            display: none;
        }
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.9);
            z-index: 1000;
            display: none;
            flex-direction: column;
            overflow-y: auto;
        }
        .modal-header {
            padding: 20px;
            background: rgba(25, 42, 50, 0.95);
            border-bottom: 2px solid #1abc9c;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .modal-header h2 {
            margin: 0;
            color: #1abc9c;
        }
        .close-modal {
            background: none;
            border: none;
            color: #ecf0f1;
            font-size: 24px;
            cursor: pointer;
        }
        .modal-content {
            flex-grow: 1;
            padding: 20px;
            overflow-y: auto;
        }
        .section {
            background: rgba(30, 47, 60, 0.7);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid #1abc9c;
        }
        .section-title {
            color: #1abc9c;
            font-weight: bold;
            font-size: 22px;
            display: block;
            margin-bottom: 16px;
            text-align: center;
            background: rgba(26, 188, 156, 0.1);
            padding: 10px;
            border-radius: 8px;
        }
        label {
            display: flex;
            justify-content: space-between;
            font-size: 17px;
            margin-bottom: 10px;
            color: #bdc3c7;
        }
        input[type=range] {
            width: 100%;
            height: 26px;
            -webkit-appearance: none;
            background: #2c3e50;
            outline: none;
            border-radius: 10px;
            margin-top: 8px;
        }
        input[type=range]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 24px;
            height: 24px;
            background: #1abc9c;
            border-radius: 50%;
            cursor: pointer;
        }
        select {
            width: 100%;
            padding: 12px;
            background: #2c3e50;
            color: #ecf0f1;
            border: 1px solid #1abc9c;
            border-radius: 8px;
            font-size: 17px;
            margin-top: 8px;
        }
        .btn {
            width: 100%;
            padding: 14px;
            cursor: pointer;
            font-weight: bold;
            border-radius: 8px;
            border: none;
            margin-top: 15px;
            font-size: 17px;
            transition: all 0.3s ease;
        }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
        .btn-save { background: linear-gradient(to right, #2ecc71, #27ae60); color: white; }
        .btn-reset { background: linear-gradient(to right, #e74c3c, #c0392b); color: white; }
        .btn-trade { background: linear-gradient(to right, #9b59b6, #8e44ad); color: white; }
        .btn-mode { background: linear-gradient(to right, #f39c12, #e67e22); color: white; }
        .btn-test { background: linear-gradient(to right, #3498db, #2980b9); color: white; }
        .btn-exchange { background: linear-gradient(to right, #34495e, #2c3e50); color: white; }
        .collapse-box {
            display: none;
            background: rgba(20, 30, 40, 0.9);
            padding: 15px;
            font-size: 14px;
            margin-top: 10px;
            border: 1px solid #3498db;
            border-radius: 8px;
            max-height: 200px;
            overflow-y: auto;
            line-height: 1.6;
        }
        .stat-box {
            background: rgba(44, 62, 80, 0.8);
            padding: 15px;
            border-radius: 10px;
            margin: 10px 0;
            font-size: 18px;
            border: 1px dashed #1abc9c;
        }
        .highlight { color: #f1c40f; font-weight: bold; }
        .profit { color: #2ecc71; }
        .loss { color: #e74c3c; }
        .error { color: #ff6b6b; }
        .mode-indicator {
            text-align: center;
            padding: 10px;
            font-weight: bold;
            font-size: 18px;
            margin-bottom: 15px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .live-mode { background: rgba(231, 76, 60, 0.2); border: 2px solid #e74c3c; color: #e74c3c; }
        .sim-mode { background: rgba(52, 152, 219, 0.2); border: 2px solid #3498db; color: #3498db; }
        .live-mode:hover { background: rgba(231, 76, 60, 0.3); }
        .sim-mode:hover { background: rgba(52, 152, 219, 0.3); }

        /* استایل منوی جدید */
        .menu-item {
            background: rgba(30, 47, 60, 0.7);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid #1abc9c;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .menu-item:hover {
            background: rgba(40, 60, 80, 0.9);
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.3);
        }
        .menu-item.active {
            background: rgba(40, 60, 80, 0.9);
            border: 2px solid #1abc9c;
        }
        .menu-title {
            color: #1abc9c;
            font-weight: bold;
            font-size: 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .menu-content {
            display: none;
            padding: 15px 0;
            background: rgba(20, 30, 40, 0.5);
            border-radius: 8px;
            margin-top: 10px;
        }
        .menu-content.active {
            display: block;
        }
        .menu-icon {
            font-size: 18px;
        }

        @media screen and (max-width: 768px) {
            body {
                flex-direction: column;
                height: 100vh;
                overflow: hidden;
            }
            .sidebar {
                display: none;
            }
            .settings-btn {
                display: flex;
            }
            .main-content {
                flex-grow: 1;
            }
            .chart-container {
                height: 50vh;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="mode-indicator <?php echo $cfg['mode'] == 'live' ? 'live-mode' : 'sim-mode'; ?>" id="mode-indicator" onclick="toggleMode()">
            <?php echo $cfg['mode'] == 'live' ? '🚨 حالت لایو (ارسال واقعی سفارش)' : '🎮 حالت شبیه‌سازی (بدون ارسال سفارش)'; ?>
        </div>

        <!-- منوی انتخاب صرافی -->
        <div class="menu-item" id="menu-exchange">
            <div class="menu-title" onclick="toggleMenu('menu-exchange')">
                <span>💱 انتخاب صرافی</span>
                <span class="menu-icon">▼</span>
            </div>
            <div class="menu-content" id="menu-exchange-content">
                <label>انتخاب صرافی:</label>
                <select id="exchange-select" onchange="updateExchangeConfig()">
                    <option value="nobitex" <?php echo $cfg['exchange'] == 'nobitex' ? 'selected' : ''; ?>>نوبیتکس</option>
                    <option value="tabdeal" <?php echo $cfg['exchange'] == 'tabdeal' ? 'selected' : ''; ?>>تبدیل</option>
                </select>
                <button class="btn btn-exchange" onclick="openExchangeSettings()">🔧 تنظیمات صرافی</button>
            </div>
        </div>

        <!-- منوی اصلی -->
        <div class="menu-item active" id="menu-status">
            <div class="menu-title" onclick="toggleMenu('menu-status')">
                <span>💰 وضعیت مالی لحظه‌ای</span>
                <span class="menu-icon">▼</span>
            </div>
            <div class="menu-content active" id="menu-status-content">
                <div class="stat-box">
                    <span id="bal-val">در حال بارگذاری...</span><br>
                    <strong>اندازه هر پله:</strong> <span id="step-val">...</span><br>
                    <strong>پله‌های لایو:</strong> <span id="live-steps" class="highlight">0/0</span><br>
                    <strong>PNL کل:</strong> <span id="pnl-val">0</span>
                </div>
                <button class="btn" style="background:#3498db;" onclick="toggle('box-live-open')">📂 پله‌های باز</button>
                <div id="box-live-open" class="collapse-box"><ul id="live-open-list"></ul></div>
                <button class="btn" style="background:#9b59b6;" onclick="toggle('box-live-history')">📋 معاملات انجام شده</button>
                <div id="box-live-history" class="collapse-box" id="live-history-content"></div>
                <button class="btn btn-trade" onclick="manualBuy()">🛒 خرید دستی یک پله</button>
                <button class="btn btn-trade" onclick="manualSellAll()">📤 فروش همه دلارها</button>
                <button class="btn btn-test" onclick="extractPreviousTrades()">📋 استخراج معاملات قبلی</button>
            </div>
        </div>

        <div class="menu-item" id="menu-backtest">
            <div class="menu-title" onclick="toggleMenu('menu-backtest')">
                <span>📊 بک‌تست و تحلیل</span>
                <span class="menu-icon">▼</span>
            </div>
            <div class="menu-content" id="menu-backtest-content">
                <div class="stat-box">
                    <strong>دارایی اولیه:</strong> <span id="bt-initial-capital">1,000,000 تومان</span><br>
                    <strong>دارایی نهایی:</strong> <span id="bt-final-capital">1,000,000 تومان</span><br>
                    <strong>سود کل:</strong> <span id="bt-total-profit">0 تومان</span><br>
                    <strong>تعداد معاملات:</strong> <span id="bt-total-trades">0</span><br>
                    <strong>تعداد سودآور:</strong> <span id="bt-profit-trades">0</span> | <strong>ضررده:</strong> <span id="bt-loss-trades">0</span><br>
                    <strong>میانگین قیمت خرید:</strong> <span id="bt-avg-buy-price">-</span><br>
                    <strong>آخرین معامله:</strong> <span id="bt-last-trade">-</span>
                </div>
                <div id="bt-rep" style="color:#2ecc71; font-weight:bold; font-size:15px;"></div>
                <div id="bt-open-info" style="color:#f1c40f; font-size:14px; margin-top:8px;"></div>
                <button class="btn" style="background:#3498db;" onclick="toggle('box-open')">📂 پله‌های باز</button>
                <div id="box-open" class="collapse-box"><ul id="bt-open-list"></ul></div>
                <button class="btn" style="background:#9b59b6;" onclick="toggle('box-history')">📋 تاریخچه معاملات</button>
                <div id="box-history" class="collapse-box"></div>
            </div>
        </div>

        <div class="menu-item" id="menu-timing">
            <div class="menu-title" onclick="toggleMenu('menu-timing')">
                <span>⏱️ تنظیمات زمانی</span>
                <span class="menu-icon">▼</span>
            </div>
            <div class="menu-content" id="menu-timing-content">
                <label>تایم‌فریم:</label>
                <select id="tf">
                    <option value="60" <?php echo $cfg['tf']=="60" ? 'selected' : ''; ?>>1 دقیقه</option>
                    <option value="300" <?php echo $cfg['tf']=="300" ? 'selected' : ''; ?>>5 دقیقه</option>
                    <option value="900" <?php echo $cfg['tf']=="900" ? 'selected' : ''; ?>>15 دقیقه</option>
                    <option value="1800" <?php echo $cfg['tf']=="1800" ? 'selected' : ''; ?>>30 دقیقه</option>
                    <option value="3600" <?php echo $cfg['tf']=="3600" ? 'selected' : ''; ?>>1 ساعت</option>
                    <option value="7200" <?php echo $cfg['tf']=="7200" ? 'selected' : ''; ?>>2 ساعت</option>
                    <option value="14400" <?php echo $cfg['tf']=="14400" ? 'selected' : ''; ?>>4 ساعت</option>
                    <option value="21600" <?php echo $cfg['tf']=="21600" ? 'selected' : ''; ?>>6 ساعت</option>
                    <option value="43200" <?php echo $cfg['tf']=="43200" ? 'selected' : ''; ?>>12 ساعت</option>
                    <option value="86400" <?php echo $cfg['tf']=="86400" ? 'selected' : ''; ?>>1 روز</option>
                </select>
                <label>کتابخانه نمودار:</label>
                <select id="chartLib">
                    <option value="tradingview" <?php echo $cfg['chartLib']=="tradingview" ? 'selected' : ''; ?>>TradingView</option>
                </select>
                <label>تاریخچه (دقیقه): <span id="h-v"><?php echo $cfg['h']; ?></span></label>
                <input type="range" id="h" min="60" max="2880" value="<?php echo $cfg['h']; ?>">
                <label>فواصل واکشی (ثانیه): <span id="fetch-v"><?php echo $cfg['fetch']; ?></span></label>
                <input type="range" id="fetch" min="5" max="120" step="5" value="<?php echo $cfg['fetch']; ?>">
            </div>
        </div>

        <div class="menu-item" id="menu-notification">
            <div class="menu-title" onclick="toggleMenu('menu-notification')">
                <span>📢 تنظیمات نوتیفیکیشن</span>
                <span class="menu-icon">▼</span>
            </div>
            <div class="menu-content" id="menu-notification-content">
                <label>روش اعلان:</label>
                <select id="notification_method">
                    <option value="bale" <?php echo ($cfg['notification_method'] ?? 'bale') == "bale" ? 'selected' : ''; ?>>بله</option>
                    <option value="telegram" <?php echo ($cfg['notification_method'] ?? 'bale') == "telegram" ? 'selected' : ''; ?>>تلگرام</option>
                    <option value="email" <?php echo ($cfg['notification_method'] ?? 'bale') == "email" ? 'selected' : ''; ?>>ایمیل</option>
                </select>
                <button class="btn btn-test" onclick="sendTestNotifications()">🧪 تست نوتیفیکیشن‌ها</button>
            </div>
        </div>

        <div class="menu-item" id="menu-supertrend">
            <div class="menu-title" onclick="toggleMenu('menu-supertrend')">
                <span>📊 پارامترهای سوپرترند</span>
                <span class="menu-icon">▼</span>
            </div>
            <div class="menu-content" id="menu-supertrend-content">
                <label>دوره سوپرترند: <span id="p-v"><?php echo $cfg['p']; ?></span></label>
                <input type="range" id="p" min="1" max="50" value="<?php echo $cfg['p']; ?>">
                <label>ضریب سوپرترند: <span id="m-v"><?php echo $cfg['m']; ?></span></label>
                <input type="range" id="m" min="0.5" max="10" step="0.5" value="<?php echo $cfg['m']; ?>">
            </div>
        </div>

        <div class="menu-item" id="menu-capital">
            <div class="menu-title" onclick="toggleMenu('menu-capital')">
                <span>⚖️ مدیریت سرمایه</span>
                <span class="menu-icon">▼</span>
            </div>
            <div class="menu-content" id="menu-capital-content">
                <label>تعداد پله (n): <span id="n-v"><?php echo $cfg['n']; ?></span></label>
                <input type="range" id="n" min="1" max="25" value="<?php echo $cfg['n']; ?>">
                <label>فاصله خرید بعدی (%): <span id="g-v"><?php echo $cfg['g']; ?></span></label>
                <input type="range" id="g" min="0.1" max="10" step="0.1" value="<?php echo $cfg['g']; ?>">
                <label>سود فروش کل (%): <span id="t-v"><?php echo $cfg['t']; ?></span></label>
                <input type="range" id="t" min="0.1" max="15" step="0.1" value="<?php echo $cfg['t']; ?>">
                <button class="btn" style="background:#e67e22; margin-top:15px;" id="toggle-dotted-lines-btn" onclick="toggleDottedLines()">👁️ تاگل نمایش خطوط نقطه‌چین</button>
            </div>
        </div>

        <button class="btn btn-save" onclick="save()">💾 ذخیره تنظیمات</button>
        <button class="btn btn-reset" onclick="reset()">🔄 ریست پله‌ها</button>
    </div>

    <div class="main-content">
        <div class="chart-container">
            <div id="chart" style="width:100%; height:100%;"></div>
        </div>
        <button class="settings-btn" onclick="toggleSidebar()">⚙️</button>
    </div>

    <!-- مودال تنظیمات صرافی -->
    <div class="modal" id="exchange-settings-modal">
        <div class="modal-header">
            <h2>تنظیمات صرافی</h2>
            <button class="close-modal" onclick="closeExchangeSettings()">&times;</button>
        </div>
        <div class="modal-content">
            <div class="section">
                <h3 class="section-title">نوبیتکس</h3>
                <label>توکن نوبیتکس:</label>
                <input type="password" id="nobitex-token" style="width:100%; padding:10px; margin:10px 0; border-radius:5px; border:none;">
            </div>
            <div class="section">
                <h3 class="section-title">تبدیل</h3>
                <label>کلید API تبدیل:</label>
                <input type="text" id="tabdeal-api-key" style="width:100%; padding:10px; margin:10px 0; border-radius:5px; border:none;">
                <label>کلید مخفی تبدیل:</label>
                <input type="password" id="tabdeal-secret-key" style="width:100%; padding:10px; margin:10px 0; border-radius:5px; border:none;">
                <label>نوع معامله تبدیل:</label>
                <select id="tabdeal-trading-type" style="width:100%; padding:10px; margin:10px 0; border-radius:5px; border:none;">
                    <option value="spot">اسپات (Spot)</option>
                    <option value="margin">اهرم دار (Margin)</option>
                </select>
            </div>
            <button class="btn btn-save" onclick="saveExchangeSettings()" style="margin-top:20px;">💾 ذخیره تنظیمات صرافی</button>
            <button class="btn btn-test" onclick="importNobitexOrders()" style="margin-top:10px;">📥 وارد کردن خریدهای قبلی نوبیتکس</button>
        </div>
    </div>

    <script>
        // کپی کامل اسکریپت‌های جاوااسکریپت از کد اصلی
        let autoInterval = null;
        let currentMode = "<?php echo $cfg['mode']; ?>";
        let currentExchange = "<?php echo $cfg['exchange']; ?>";
        let showDottedLines = true;
        let tvChart = null;

        function toggle(id) {
            const e = document.getElementById(id);
            e.style.display = (e.style.display === 'block' || e.style.display === 'flex') ? 'none' : (id === 'code-wrap' ? 'flex' : 'block');
        }

        function toggleDottedLines() {
            showDottedLines = !showDottedLines;
            const btn = document.getElementById('toggle-dotted-lines-btn');
            btn.innerText = showDottedLines ? '🙈 مخفی کردن خطوط نقطه‌چین' : '👁️ نمایش خطوط نقطه‌چین';
            update();
        }

        function getParams() {
            return {
                h: document.getElementById('h').value,
                p: document.getElementById('p').value,
                m: document.getElementById('m').value,
                n: document.getElementById('n').value,
                g: document.getElementById('g').value,
                t: document.getElementById('t').value,
                tf: document.getElementById('tf').value,
                chartLib: document.getElementById('chartLib').value,
                fetch: document.getElementById('fetch').value,
                notification_method: document.getElementById('notification_method').value,
                code: '',
                mode: currentMode,
                exchange: currentExchange
            };
        }

        function toggleMode() {
            currentMode = currentMode === 'live' ? 'sim' : 'live';
            document.getElementById('mode-indicator').className = 'mode-indicator ' + (currentMode === 'live' ? 'live-mode' : 'sim-mode');
            document.getElementById('mode-indicator').innerText = currentMode === 'live' ? '🚨 حالت لای���� (ارسال واقعی سفارش)' : '🎮 حالت شبیه‌سازی (بدون ارسال سف��رش)';

            // ذخیره تغییرات در کانفیگ
            const params = getParams();
            fetch('?action=save_config', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(params)
            }).then(r => r.json()).then(d => {
                if(d.error) {
                    console.error('Error saving mode change:', d.error);
                } else {
                    // Restart the auto-update to apply the new mode setting
                    if (autoInterval) clearInterval(autoInterval);
                    startAutoUpdate();
                }
            });
        }

        function updateExchangeConfig() {
            currentExchange = document.getElementById('exchange-select').value;

            // ذخیره تغییرات در کانفیگ
            const params = getParams();
            fetch('?action=save_config', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(params)
            }).then(r => r.json()).then(d => {
                if(d.error) {
                    console.error('Error saving exchange change:', d.error);
                } else {
                    // Update the chart and data after successful exchange change
                    update();
                }
            });
        }

        function openExchangeSettings() {
            // Load current settings
            fetch('?action=get_exchange_config')
                .then(r => r.json())
                .then(config => {
                    document.getElementById('nobitex-token').value = config.nobitex_token || '';
                    document.getElementById('tabdeal-api-key').value = config.tabdeal_api_key || '';
                    document.getElementById('tabdeal-secret-key').value = config.tabdeal_secret_key || '';
                    // Set the trading type selection
                    const tradingType = config.tabdeal_trading_type || 'spot';
                    document.getElementById('tabdeal-trading-type').value = tradingType;
                });

            document.getElementById('exchange-settings-modal').style.display = 'flex';
        }

        function closeExchangeSettings() {
            document.getElementById('exchange-settings-modal').style.display = 'none';
        }

        function saveExchangeSettings() {
            const nobitexToken = document.getElementById('nobitex-token').value;
            const tabdealApiKey = document.getElementById('tabdeal-api-key').value;
            const tabdealSecretKey = document.getElementById('tabdeal-secret-key').value;
            const tabdealTradingType = document.getElementById('tabdeal-trading-type').value;

            // Load current config and update exchange settings
            fetch('?action=get_exchange_config')
                .then(r => r.json())
                .then(config => {
                    config.nobitex_token = nobitexToken;
                    config.tabdeal_api_key = tabdealApiKey;
                    config.tabdeal_secret_key = tabdealSecretKey;
                    config.tabdeal_trading_type = tabdealTradingType; // Save the trading type
                    config.exchange = currentExchange; // Make sure to preserve current exchange selection

                    fetch('?action=save_config', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify(config)
                    }).then(r => r.json()).then(d => {
                        if(d.error) {
                            alert('❌ خطا در ذخیره تنظیمات: ' + d.error);
                        } else {
                            alert('✅ تنظیمات صرافی ذخیره شد.');
                            closeExchangeSettings();
                        }
                    });
                });
        }

        function importNobitexOrders() {
            if (!confirm("⚠️ آیا مطمئن هستید که می‌خواهید خریدهای قبلی نوبیتکس را وارد کنید؟")) {
                return;
            }

            fetch('?action=import_nobitex_orders', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert('❌ خطا در وارد کردن خریدهای قبلی: ' + data.error);
                } else {
                    alert('✅ ' + data.imported_count + ' خرید از نوبیتکس با موفقیت وارد شد.');
                    // Refresh the UI to show the imported steps
                    update();
                }
            })
            .catch(error => {
                console.error('Error importing Nobitex orders:', error);
                alert('❌ خطای اتصال در هنگام وارد کردن خریدهای قبلی: ' + error.message);
            });
        }

        function save() {
            const params = getParams();
            fetch('?action=save_config', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(params)
            }).then(r => r.json()).then(d => {
                if(d.error) alert('❌ خطا: ' + d.error);
                else alert('✅ تنظیمات ذخیره شد.');
            });
        }

        function reset() {
            if(!confirm("⚠️ آیا مطمئن هستید؟ تمام پله‌های " + (currentMode === 'live' ? 'لای��' : 'شبیه‌سازی') + " حذف می‌شوند.")) return;
            fetch('?action=reset&mode=' + currentMode).then(() => update());
        }

        function manualBuy() {
            const p = getParams();
            fetch('?action=process', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({...p, h:1, tf:'60'})
            })
            .then(r => r.json())
            .then(data => {
                if(data.error) {
                    alert('❌ خطا: ' + data.error);
                    return;
                }
                // استخراج مقدار تومان از نمایش اندازه پله
                // فرض: قالب "X.XXXX $ (Y,YYY تومان)"
                const stepText = document.getElementById('step-val').innerText;
                let stepToman = 100000; // مقدار پیش‌فرض
                const parenStart = stepText.indexOf('(');
                if (parenStart !== -1) {
                    const parenEnd = stepText.indexOf('تومان', parenStart);
                    if (parenEnd !== -1) {
                        const numStr = stepText.substring(parenStart + 1, parenEnd).replace(/,/g, '');
                        stepToman = parseInt(numStr) || stepToman; // اگر تجزیه نشد، از مقدار پیش‌فرض استفاده کن
                    }
                }

                fetch('?action=execute_order', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({side: 'buy', amount: stepToman, mode: currentMode, exchange: currentExchange})
                })
                .then(r => r.json())
                .then(d => {
                    if(d.error) {
                        alert('❌ خطا در خرید: ' + d.error);
                    } else {
                        let message = '✅ سفارش خرید ارسال شد (' + (currentMode === 'live' ? 'لایو' : 'شبیه‌سازی') + ').';
                        // نمایش پاسخ صرافی اگر موجود باشد
                        if(d.data && typeof d.data === 'object' && Object.keys(d.data).length > 0) {
                             message += '\nپاسخ صرافی: ' + JSON.stringify(d.data, null, 2);
                        } else if(d.data) {
                             message += '\nپاسخ صرافی: ' + d.data;
                        }
                        alert(message);
                        update();
                    }
                })
                .catch(error => {
                    console.error('Error in manualBuy execute_order:', error);
                    alert('❌ خطای اتصال در هنگام خرید: ' + error.message);
                });
            })
            .catch(error => {
                console.error('Error in manualBuy process:', error);
                alert('❌ خطای اتصال در هنگام دریافت اطلاعات: ' + error.message);
            });
        }

        function manualSellAll() {
            const params = getParams();
            fetch('?action=process', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({...params, h:1, tf:'60', mode: currentMode, exchange: currentExchange})
            }).then(r => r.json()).then(data => {
                if(data.error) {
                    alert('❌ خطا: ' + data.error);
                    return;
                }
                let usdtBal = 0;
                if (data.usdt_balance !== undefined) {
                    usdtBal = parseFloat(data.usdt_balance);
                } else {
                    const regex = /موجودی دلاری: ([\d,]+\.?\d*)/;
                    const match = data.bal_html.match(regex);
                    if (match) {
                        usdtBal = parseFloat(match[1].replace(/,/g, ''));
                    }
                }
                if (usdtBal <= 0) {
                    alert('❌ موجودی دلاری کافی نیست.');
                    return;
                }
                const currentPrice = getChartLastClose(data);
                const volumeToman = Math.round(usdtBal * currentPrice);

                fetch('?action=execute_order', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({side: 'sell', amount: volumeToman, mode: currentMode, exchange: currentExchange})
                }).then(r => {
                    console.log('Received response from execute_order:', r);
                    if (!r.ok) {
                        console.error('HTTP error:', r.status);
                        throw new Error('HTTP error ' + r.status);
                    }
                    return r.json();
                }).then(d => {
                    console.log('Parsed response data:', d);
                    if(d.error) alert('❌ خطا در فروش: ' + d.error);
                    else {
                        let message = '✅ سفارش فروش همه دلارها ارسال شد (' + (currentMode === 'live' ? 'لایو' : 'شبیه‌سازی') + ').';
                        message += '\nحجم معامله: ' + volumeToman.toLocaleString() + ' تومان (' + usdtBal.toFixed(4) + ' دلار × ' + currentPrice.toLocaleString() + ' تومان)';
                        if(d.data) {
                            if(Object.keys(d.data).length > 0) {
                                message += '\nپاسخ صرافی:' + JSON.stringify(d.data, null, 2);
                            } else {
                                message += '\nپاسخ صرافی: درخواست با موفقیت ارسال شد';
                            }
                        }
                        alert(message);
                        update();
                    }
                }).catch(error => {
                    console.error('Error in manualSellAll:', error);
                    alert('❌ خطای اتصال در هنگام فروش: ' + error.message);
                });
            }).catch(err => {
                alert('❌ خطای اتصال: ' + err.message);
            });
        }

        function getChartLastClose(data) {
            if (data.chart && data.chart.y && data.chart.y.length > 0) {
                return data.chart.y[data.chart.y.length - 1];
            }
            return 50000; // Default fallback
        }

        function sendTestNotifications() {
            const notificationMethod = document.getElementById('notification_method').value;
            fetch('?action=send_test_notifications', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({notification_method: notificationMethod})
            }).then(r => r.json()).then(d => {
                if(d.error) {
                    alert('❌ خطا در ارسال نوتیفیکیشن‌های تستی: ' + d.error);
                } else {
                    alert('✅ ' + d.message);
                }
            }).catch(err => {
                alert('❌ خطای اتصال: ' + err.message);
            });
        }

        function extractPreviousTrades() {
            if (!confirm("⚠️ آیا مطمئن هستید که می‌خواهید معاملات قبلی را استخراج کنید؟")) {
                return;
            }

            fetch('?action=extract_previous_trades', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'}
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    alert('❌ خطا در استخراج معاملات قبلی: ' + data.error);
                } else {
                    alert('✅ ' + (data.extracted_count || 0) + ' معامله از تاریخچه با موفقیت استخراج شد.');
                    // Refresh the UI to show the extracted trades
                    update();
                }
            })
            .catch(error => {
                console.error('Error extracting previous trades:', error);
                alert('❌ خطای اتص��ل در هنگام استخراج معاملات قبلی: ' + error.message);
            });
        }


        function update() {
            const p = getParams();
            ['h','p','m','n','g','t','fetch'].forEach(id => {
                document.getElementById(id+'-v').innerText = p[id];
            });

            fetch('?action=process', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(p)
            })
            .then(res => {
                if(!res.ok) throw new Error('Network error');
                return res.json();
            })
            .then(data => {
                if(data.error) {
                    console.error(data.error);
                    return;
                }
                document.getElementById('bal-val').innerHTML = data.bal_html;
                document.getElementById('step-val').innerHTML = data.step_html;
                document.getElementById('live-steps').innerText = data.live_steps;
                document.getElementById('pnl-val').innerHTML = data.pnl_html;

                document.getElementById('bt-rep').innerText = data.bt_report;
                document.getElementById('bt-open-info').innerHTML = data.bt_open_info;
                document.getElementById('bt-open-list').innerHTML = data.bt_open_list;
                document.getElementById('box-history').innerHTML = data.bt_history;
                document.getElementById('bt-initial-capital').innerText = data.bt_initial_capital || '1,000,000 تومان';
                document.getElementById('bt-final-capital').innerText = data.bt_final_capital || '1,000,000 تومان';
                document.getElementById('bt-total-profit').innerText = data.bt_total_profit || '0 تومان';
                document.getElementById('bt-total-trades').innerText = data.bt_total_trades || '0';
                document.getElementById('bt-profit-trades').innerText = data.bt_profit_trades || '0';
                document.getElementById('bt-loss-trades').innerText = data.bt_loss_trades || '0';
                document.getElementById('bt-avg-buy-price').innerText = data.bt_avg_buy_price || '-';
                document.getElementById('bt-last-trade').innerText = data.bt_last_trade || '-';

                const liveOpenListEl = document.getElementById('live-open-list');
                const liveHistoryContentEl = document.getElementById('live-history-content');
                if (liveOpenListEl) liveOpenListEl.innerHTML = data.live_open_list;
                if (liveHistoryContentEl) liveHistoryContentEl.innerHTML = data.live_history_content;

                // Show popup notification for crossovers and crossunders if detected
                if (data.has_crossover) {
                    // Play beep sound for crossover
                    playBeepSound();
                    alert('🔄 کراس آور شناسایی شد! قیمت از خط سوپرترند بالا رفته است');
                }
                
                if (data.has_crossunder) {
                    // Play beep sound for crossunder
                    playBeepSound();
                    alert('⚠️ کر��س آندر شناسایی شد! قیمت از خط سوپرترند پایین رفته است');
                }

                renderChart(data.chart, data.lines);
            })
            .catch(err => {
                console.error(err);
                alert('❌ خطای اتصال: ' + err.message);
            });
        }

        function renderChart(c, lines) {
            const chartLib = document.getElementById('chartLib').value;
            const chartDiv = document.getElementById('chart');
            let prevVisibleRange = null;
            let prevPriceRange = null;

            if (tvChart) {
                try { prevVisibleRange = tvChart.timeScale().getVisibleRange(); } catch (e) { prevVisibleRange = null; }
                try { prevPriceRange = tvChart.priceScale && tvChart.priceScale('right') && tvChart.priceScale('right').getPriceRange ? tvChart.priceScale('right').getPriceRange() : null; } catch (e) { prevPriceRange = null; }
            }

            chartDiv.innerHTML = '';
            chartDiv.style.backgroundColor = '#000000';

            const chart = LightweightCharts.createChart(chartDiv, {
                width: chartDiv.clientWidth,
                height: chartDiv.clientHeight,
                layout: {
                    background: { color: '#000000' },
                    textColor: '#ffffff',
                    fontSize: 12,
                    fontFamily: 'Vazirmatn, Tahoma, sans-serif',
                },
                grid: {
                    vertLines: { color: '#222831' },
                    horzLines: { color: '#222831' },
                },
                crosshair: {
                    mode: LightweightCharts.CrosshairMode.Normal,
                    vertLine: { width: 1, color: '#445566', style: LightweightCharts.LineStyle.Solid },
                    horzLine: { width: 1, color: '#445566', style: LightweightCharts.LineStyle.Solid },
                },
                rightPriceScale: {
                    borderColor: '#2b3942',
                    textColor: '#ffffff',
                    visible: true,
                },
                timeScale: {
                    borderColor: '#2b3942',
                    textColor: '#ffffff',
                    visible: true,
                    timeVisible: true,
                    secondsVisible: false,
                },
            });

            const lineSeries = chart.addLineSeries({ color: '#bdc3c7', lineWidth: 2 });
            const data = c.x.map((x, i) => ({time: x, value: c.y[i]}));
            lineSeries.setData(data);

            // SuperTrend line with color change based on direction (green for uptrend, red for downtrend)
            let stData = [];
            let cd = c.dir[0];
            let stSeries = chart.addLineSeries({color: cd === 1 ? '#2ecc71' : '#e74c3c', lineWidth: 3});
            c.x.forEach((x, i) => {
                if (!c.st[i] || c.st[i] === 0) return;
                if (c.dir[i] !== cd) {
                    stSeries.setData(stData);
                    stSeries = chart.addLineSeries({color: c.dir[i] === 1 ? '#2ecc71' : '#e74c3c', lineWidth: 3});
                    stData = [];
                    cd = c.dir[i];
                }
                stData.push({time: x, value: c.st[i]});
            });
            if (stData.length > 0) stSeries.setData(stData);

            const markers = [];
            if (c.bx.length > 0) {
                c.bx.forEach((idx, i) => {
                    markers.push({
                        time: c.x[idx],
                        position: 'belowBar',
                        color: '#3498db',
                        shape: 'arrowUp',
                        text: 'Buy',
                        size: 12
                    });
                });
            }
            if (c.sx.length > 0) {
                c.sx.forEach((idx, i) => {
                    markers.push({
                        time: c.x[idx],
                        position: 'aboveBar',
                        color: '#f39c12',
                        shape: 'arrowDown',
                        text: 'B-Sell',
                        size: 12
                    });
                });
            }
            // Automatic buy markers (separate for live and sim)
            if (c.abx.length > 0) {
                c.abx.forEach((idx, i) => {
                    markers.push({
                        time: c.x[idx],
                        position: 'belowBar',
                        color: currentMode === 'live' ? '#2ecc71' : '#3498db', // Green for live, blue for sim
                        shape: currentMode === 'live' ? 'circle' : 'triangle-up', // Circle for live, triangle for sim
                        text: 'A-Buy',
                        size: 14
                    });
                });
            }
            if (c.asx.length > 0) {
                c.asx.forEach((idx, i) => {
                    markers.push({
                        time: c.x[idx],
                        position: 'aboveBar',
                        color: currentMode === 'live' ? '#e74c3c' : '#9b59b6', // Red for live, purple for sim
                        shape: currentMode === 'live' ? 'circle' : 'triangle-down', // Circle for live, triangle for sim
                        text: 'A-Sell',
                        size: 14
                    });
                });
            }
            // Actual trade markers (separate for live and sim)
            if (c.abx && c.abx.length > 0) {
                c.abx.forEach((idx, i) => {
                    markers.push({
                        time: c.x[idx],
                        position: 'belowBar',
                        color: currentMode === 'live' ? '#27ae60' : '#2980b9', // Different shades for live vs sim
                        shape: 'circle',
                        text: 'B',
                        size: 8
                    });
                });
            }
            if (c.asx && c.asx.length > 0) {
                c.asx.forEach((idx, i) => {
                    markers.push({
                        time: c.x[idx],
                        position: 'aboveBar',
                        color: currentMode === 'live' ? '#c0392b' : '#8e44ad', // Different shades for live vs sim
                        shape: 'circle',
                        text: 'S',
                        size: 8
                    });
                });
            }
            lineSeries.setMarkers(markers);

            // Dotted lines have been removed as requested
            /*
            // Green dotted line for average buy price (controlled by toggle)
            if (lines.avg > 0 && showDottedLines) {
                const avgLine = chart.addLineSeries({color: '#2ecc71', lineStyle: LightweightCharts.LineStyle.Dotted, lineWidth: 2});
                avgLine.setData([{time: c.x[0], value: lines.avg}, {time: c.x[c.x.length-1], value: lines.avg}]);
            }
            // Yellow dotted line for profit target/sell threshold (controlled by toggle)
            if (lines.target > 0 && showDottedLines) {
                const targetLine = chart.addLineSeries({color: '#f1c40f', lineStyle: LightweightCharts.LineStyle.Dotted, lineWidth: 2});
                targetLine.setData([{time: c.x[0], value: lines.target}, {time: c.x[c.x.length-1], value: lines.target}]);
            }
            // White dotted line for next buy threshold (controlled by toggle)
            if (lines.gap > 0 && showDottedLines) {
                const gapLine = chart.addLineSeries({color: '#ffffff', lineStyle: LightweightCharts.LineStyle.Dotted, lineWidth: 1});
                gapLine.setData([{time: c.x[0], value: lines.gap}, {time: c.x[c.x.length-1], value: lines.gap}]);
            }
            */

            if (prevVisibleRange) {
                try { chart.timeScale().setVisibleRange(prevVisibleRange); } catch (e) { chart.timeScale().fitContent(); }
            } else {
                chart.timeScale().fitContent();
            }
            if (prevPriceRange) {
                try { chart.priceScale('right').setPriceRange(prevPriceRange); } catch (e) { /* ignore */ }
            }

            tvChart = chart;
        }

        function toggleMenu(menuId) {
            // Get the menu item and content elements
            const menuItem = document.getElementById(menuId);
            const menuContent = document.getElementById(menuId + '-content');

            // Determine current state
            const isActive = menuContent.classList.contains('active');

            // Close all other menus
            document.querySelectorAll('.menu-content').forEach(content => {
                if (content.id !== menuId + '-content') {
                    content.classList.remove('active');
                    content.style.display = 'none';
                }
            });

            // Toggle the clicked menu
            if (isActive) {
                menuContent.classList.remove('active');
                menuContent.style.display = 'none';
                menuItem.classList.remove('active');
            } else {
                menuContent.classList.add('active');
                menuContent.style.display = 'block';
                menuItem.classList.add('active');
            }
        }

        function toggleSidebar() {
            const sidebar = document.querySelector('.sidebar');
            const settingsBtn = document.querySelector('.settings-btn');

            if (window.innerWidth <= 768) {
                // On mobile devices, toggle sidebar visibility
                if (sidebar.style.display === 'none' || sidebar.style.display === '') {
                    sidebar.style.display = 'flex';
                    settingsBtn.innerHTML = '❌';
                } else {
                    sidebar.style.display = 'none';
                    settingsBtn.innerHTML = '⚙️';
                }
            } else {
                // On desktop, just show the settings (though this button is hidden on desktop)
                alert('تنظیمات در دسترس است');
            }
        }

        function startAutoUpdate() {
            if (autoInterval) clearInterval(autoInterval);
            update();
            
            // When in live mode, auto-trading should be active
            if (currentMode === 'live') {
                // Call auto_trade endpoint periodically when in live mode
                autoInterval = setInterval(() => {
                    update(); // Update the UI
                    
                    // Execute auto-trading logic when in live mode
                    fetch('?action=auto_trade', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'}
                    })
                    .then(response => response.json())
                    .then(data => {
                        console.log('Auto-trade result:', data);
                    })
                    .catch(error => {
                        console.error('Error in auto-trade:', error);
                    });
                }, 10000); // Auto-trade every 10 seconds
            } else {
                // Just update the UI when in simulation mode
                const fetchSec = parseInt(document.getElementById('fetch').value) || 10;
                autoInterval = setInterval(update, fetchSec * 1000);
            }
        }

        document.getElementById('fetch').addEventListener('input', startAutoUpdate);
        document.getElementById('chartLib').addEventListener('change', update);

        window.onload = () => {
            startAutoUpdate();
            // Update balance every 5 seconds
            setInterval(() => {
                const p = getParams();
                fetch('?action=process', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({...p, h:1, tf:'60'})
                }).then(r => r.json()).then(data => {
                    if(data && !data.error) {
                        document.getElementById('bal-val').innerHTML = data.bal_html;
                    }
                });
            }, 5000);

            window.addEventListener('resize', update);
        };
        
        // Function to play beep sound
        function playBeepSound() {
            try {
                const context = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = context.createOscillator();
                const gainNode = context.createGain();
                
                oscillator.connect(gainNode);
                gainNode.connect(context.destination);
                
                oscillator.type = 'sine';
                oscillator.frequency.value = 800; // Frequency in Hz
                gainNode.gain.value = 0.3; // Volume (0.0 to 1.0)
                
                oscillator.start();
                setTimeout(() => {
                    oscillator.stop();
                }, 200); // Duration in ms
            } catch (e) {
                console.log('Could not play beep sound: ', e);
            }
        }
    </script>
</body>
</html>
<?php
}
?>