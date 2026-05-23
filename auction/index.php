<?php
declare(strict_types=1);

// ===== ERROR REPORTING =====
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
set_error_handler(function($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    error_log(sprintf("[%s] %s in %s on line %d", 
        date('Y-m-d H:i:s'), $message, $file, $line));
    return false;
});
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE])) {
        error_log(sprintf("[FATAL] %s in %s on line %d", 
            $error['message'], $error['file'], $error['line']));
    }
});
// ===========================

session_start();

/*
=========================================================
 Reverse Auction Pro - Complete Edition
 All Features: Buyer + Seller + Admin
 Modern UI with Glassmorphism Design
=========================================================
 Demo: admin/admin123 | buyer/buyer123 | seller1/seller123
=========================================================
*/

date_default_timezone_set('Asia/Tehran');
const APP_NAME = 'بازار هوشمند';
const DATA_DIR = __DIR__ . '/data';
const USERS_FILE = DATA_DIR . '/users.json';
const REQUESTS_FILE = DATA_DIR . '/requests.json';
const OFFERS_FILE = DATA_DIR . '/offers.json';
const CHATS_FILE = DATA_DIR . '/chats.json';
const LOGS_FILE = DATA_DIR . '/logs.json';
const TRANSACTIONS_FILE = DATA_DIR . '/transactions.json';
const ADDRESSES_FILE = DATA_DIR . '/addresses.json';
const REVIEWS_FILE = DATA_DIR . '/reviews.json';
const PRODUCTS_FILE = DATA_DIR . '/products.json';
const ORDERS_FILE = DATA_DIR . '/orders.json';
const INVOICES_FILE = DATA_DIR . '/invoices.json';
const SETTINGS_FILE = DATA_DIR . '/settings.json';

// ===== HELPERS =====
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function now(): string { return date('Y-m-d H:i:s'); }
function rid(): string { return bin2hex(random_bytes(8)); }
function ensure_dir(): void { if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0777, true); }
function safe_array($v): array { return is_array($v) ? $v : []; }
function json_read(string $f, $d = []): array {
    ensure_dir();
    if (!file_exists($f)) { file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); return safe_array($d); }
    $c = file_get_contents($f);
    if ($c === false || trim($c) === '') return safe_array($d);
    $dec = json_decode($c, true);
    return is_array($dec) ? $dec : safe_array($d);
}
function json_write(string $f, array $d): bool {
    ensure_dir();
    return file_put_contents($f, json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
}
function flash(string $t, string $m): void { $_SESSION['flash'] = ['type' => $t, 'msg' => $m]; }
function get_flash(): ?array { $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return is_array($f) ? $f : null; }
function current_user(): ?array {
    if (empty($_SESSION['uid'])) return null;
    foreach (get_users() as $u) if (($u['id'] ?? '') === $_SESSION['uid']) return $u;
    return null;
}
function is_logged_in(): bool { return current_user() !== null; }
function is_admin(): bool { return (current_user()['role'] ?? '') === 'admin'; }
function is_buyer(): bool { return (current_user()['role'] ?? '') === 'buyer'; }
function is_seller(): bool { return (current_user()['role'] ?? '') === 'seller'; }
function require_login(): void {
    if (!is_logged_in()) { flash('error', 'ابتدا وارد شوید.'); header('Location: ?page=login'); exit; }
}
function require_role(array $roles): void {
    $u = current_user();
    if (!$u || !in_array($u['role'] ?? '', $roles, true)) { flash('error', 'دسترسی غیرمجاز.'); header('Location: ?'); exit; }
}
function log_action(string $t, string $m, ?string $uid = null): void {
    $l = json_read(LOGS_FILE, []);
    $l[] = ['id' => rid(), 'type' => $t, 'message' => $m, 'user_id' => $uid, 'created_at' => now()];
    json_write(LOGS_FILE, $l);
}
function sort_desc(array $rows, string $k = 'created_at'): array {
    usort($rows, fn($a, $b) => strcmp((string)($b[$k] ?? ''), (string)($a[$k] ?? '')));
    return $rows;
}
function avg_arr(array $n): float { $n = array_values(array_filter($n, fn($x) => is_numeric($x))); return $n ? array_sum($n) / count($n) : 0; }
function money($n): string { return number_format((float)$n, 0) . ' تومان'; }
function format_num($n): string { return number_format((float)$n, 0); }
function status_label(string $s): string {
    return ['open' => 'باز', 'closed' => 'بسته', 'selected' => 'برنده شد', 'pending' => 'در انتظار', 'completed' => 'تکمیل شده', 'delivered' => 'تحویل شده', 'processing' => 'در حال پردازش', 'shipped' => 'ارسال شده', 'cancelled' => 'لغو شده'][$s] ?? $s;
}
function role_label(string $r): string { return ['admin' => 'ادمین', 'buyer' => 'خریدار', 'seller' => 'فروشنده'][$r] ?? $r; }
function cat_icon(string $c): string {
    $c = mb_strtolower($c);
    if (str_contains($c, 'موبایل') || str_contains($c, 'گوشی')) return '';
    if (str_contains($c, 'لپ') || str_contains($c, 'لپ‌تاپ')) return '💻';
    if (str_contains($c, 'بازی') || str_contains($c, 'کنسول')) return '🎮';
    if (str_contains($c, 'هدفون') || str_contains($c, 'هدست') || str_contains($c, 'سپیکر')) return '';
    if (str_contains($c, 'ساعت') || str_contains($c, 'واچ')) return '⌚';
    if (str_contains($c, 'کیبورد') || str_contains($c, 'موس')) return '️';
    if (str_contains($c, 'دوربین')) return '📷';
    return '🛒';
}

// ===== DATA ACCESS =====
function get_users(): array { return safe_array(json_read(USERS_FILE, [])); }
function save_users(array $r): bool { return json_write(USERS_FILE, array_values($r)); }
function get_requests_db(): array { return safe_array(json_read(REQUESTS_FILE, [])); }
function save_requests_db(array $r): bool { return json_write(REQUESTS_FILE, array_values($r)); }
function get_offers_db(): array { return safe_array(json_read(OFFERS_FILE, [])); }
function save_offers_db(array $r): bool { return json_write(OFFERS_FILE, array_values($r)); }
function get_chats_db(): array { return safe_array(json_read(CHATS_FILE, [])); }
function save_chats_db(array $r): bool { return json_write(CHATS_FILE, array_values($r)); }
function get_settings(): array { return safe_array(json_read(SETTINGS_FILE, ['allow_admin_register' => true])); }
function save_settings(array $s): bool { return json_write(SETTINGS_FILE, $s); }
function get_transactions(): array { return safe_array(json_read(TRANSACTIONS_FILE, [])); }
function save_transactions(array $r): bool { return json_write(TRANSACTIONS_FILE, array_values($r)); }
function get_addresses(): array { return safe_array(json_read(ADDRESSES_FILE, [])); }
function save_addresses(array $r): bool { return json_write(ADDRESSES_FILE, array_values($r)); }
function get_reviews(): array { return safe_array(json_read(REVIEWS_FILE, [])); }
function save_reviews(array $r): bool { return json_write(REVIEWS_FILE, array_values($r)); }
function get_products(): array { return safe_array(json_read(PRODUCTS_FILE, [])); }
function save_products(array $r): bool { return json_write(PRODUCTS_FILE, array_values($r)); }
function get_orders(): array { return safe_array(json_read(ORDERS_FILE, [])); }
function save_orders(array $r): bool { return json_write(ORDERS_FILE, array_values($r)); }
function get_invoices(): array { return safe_array(json_read(INVOICES_FILE, [])); }
function save_invoices(array $r): bool { return json_write(INVOICES_FILE, array_values($r)); }

function find_user(string $id): ?array { foreach (get_users() as $u) if (($u['id'] ?? '') === $id) return $u; return null; }
function find_request(string $id): ?array { foreach (get_requests_db() as $r) if (($r['id'] ?? '') === $id) return $r; return null; }
function find_offer(string $id): ?array { foreach (get_offers_db() as $o) if (($o['id'] ?? '') === $id) return $o; return null; }
function find_product(string $id): ?array { foreach (get_products() as $p) if (($p['id'] ?? '') === $id) return $p; return null; }
function find_order(string $id): ?array { foreach (get_orders() as $o) if (($o['id'] ?? '') === $id) return $o; return null; }

// ===== SEED =====
function seed_if_needed(): void {
    ensure_dir();
    if (!file_exists(USERS_FILE)) {
        $users = [
            ['id'=>rid(),'username'=>'admin','name'=>'مدیر سامانه','password'=>password_hash('admin123',PASSWORD_DEFAULT),'role'=>'admin','avatar'=>'','rating'=>5,'history_count'=>99,'wallet_balance'=>0,'created_at'=>now()],
            ['id'=>rid(),'username'=>'buyer','name'=>'رضا احمدی','password'=>password_hash('buyer123',PASSWORD_DEFAULT),'role'=>'buyer','avatar'=>'','rating'=>4.8,'history_count'=>42,'success_rate'=>98,'wallet_balance'=>12450000,'phone'=>'09123456789','email'=>'reza@email.com','city'=>'تهران','address'=>'تهران، خیابان ولیعصر، کوچه نسترن، پلاک ۱۲','member_since'=>'1403/01/01','reward_points'=>2340,'created_at'=>now()],
            ['id'=>rid(),'username'=>'seller1','name'=>'MobileStar','password'=>password_hash('seller123',PASSWORD_DEFAULT),'role'=>'seller','avatar'=>'','rating'=>4.7,'history_count'=>61,'success_rate'=>92,'warranty_months'=>18,'delivery_days'=>1,'wallet_balance'=>15000000,'phone'=>'09121112233','email'=>'mobilestar@email.com','city'=>'تهران','created_at'=>now()],
            ['id'=>rid(),'username'=>'seller2','name'=>'دیجیتال استور','password'=>password_hash('seller123',PASSWORD_DEFAULT),'role'=>'seller','avatar'=>'','rating'=>4.8,'history_count'=>48,'success_rate'=>88,'warranty_months'=>12,'delivery_days'=>2,'wallet_balance'=>12000000,'phone'=>'09124445566','email'=>'digital@email.com','city'=>'تهران','created_at'=>now()],
            ['id'=>rid(),'username'=>'seller3','name'=>'گیمرلند','password'=>password_hash('seller123',PASSWORD_DEFAULT),'role'=>'seller','avatar'=>'','rating'=>4.6,'history_count'=>53,'success_rate'=>90,'warranty_months'=>24,'delivery_days'=>1,'wallet_balance'=>8000000,'phone'=>'09127778899','email'=>'gamer@email.com','city'=>'شیراز','created_at'=>now()],
        ];
        save_users($users);
        $buyer = $users[1]; $sellers = array_filter($users, fn($u) => $u['role'] === 'seller'); $sellers = array_values($sellers);
        $requests = [
            ['id'=>rid(),'buyer_id'=>$buyer['id'],'title'=>'آیفون 15 پرو مکس 256GB','category'=>'موبایل','description'=>'رنگ تیتانیوم مشکی، رجیستر شده، آکبند','budget'=>65000000,'status'=>'open','created_at'=>now()],
            ['id'=>rid(),'buyer_id'=>$buyer['id'],'title'=>'هدفون بلوتوثی سونی WH-1000XM5','category'=>'هدفون','description'=>'نو، با گارانتی معتبر','budget'=>12500000,'status'=>'completed','created_at'=>now()],
            ['id'=>rid(),'buyer_id'=>$buyer['id'],'title'=>'ساعت هوشمند اپل واچ سری 9','category'=>'ساعت','description'=>'سایز 45mm، رنگ مشکی','budget'=>18900000,'status'=>'selected','created_at'=>now()],
        ];
        save_requests_db($requests);
        $offers = [];
        $req = $requests[0];
        $offers[] = ['id'=>rid(),'request_id'=>$req['id'],'seller_id'=>$sellers[0]['id'],'price'=>64500000,'description'=>'سلام، گوشی موجوده، آکبند با گارانتی ۱۸ ماهه فروشگاه','warranty_months'=>18,'delivery_days'=>1,'delivery_time'=>'۱ تا ۲ روز کاری','status'=>'pending','discount_percent'=>3,'created_at'=>now()];
        $offers[] = ['id'=>rid(),'request_id'=>$req['id'],'seller_id'=>$sellers[1]['id'],'price'=>62800000,'description':'قیمت مناسب، کالای اصلی با گارانتی','warranty_months'=>12,'delivery_days'=>2,'delivery_time'=>'۲ تا ۳ روز کاری','status'=>'pending','discount_percent'=>0,'created_at'=>now()];
        $offers[] = ['id'=>rid(),'request_id'=>$req['id'],'seller_id'=>$sellers[2]['id'],'price'=>65900000,'description'=>'موجود در شیراز، ارسال فوری با پیک','warranty_months'=>7,'delivery_days'=>3,'delivery_time'=>'۳ تا ۵ روز کاری','status'=>'pending','discount_percent'=>2,'created_at'=>now()];
        save_offers_db($offers);
        $products = [
            ['id'=>rid(),'seller_id'=>$sellers[0]['id'],'name'=>'آیفون 15 پرو مکس','category'=>'موبایل','price'=>65000000,'stock'=>5,'description'=>'گوشی آیفون 15 پرو مکس','image'=>'','status'=>'active','created_at'=>now()],
            ['id'=>rid(),'seller_id'=>$sellers[0]['id'],'name'=>'سامسونگ S24 Ultra','category'=>'موبایل','price'=>58000000,'stock'=>3,'description'=>'گوشی سامسونگ','image'=>'','status'=>'active','created_at'=>now()],
            ['id'=>rid(),'seller_id'=>$sellers[1]['id'],'name'=>'هدفون Sony WH-1000XM5','category'=>'هدفون','price'=>12500000,'stock'=>10,'description'=>'هدفون بلوتوثی','image'=>'','status'=>'active','created_at'=>now()],
        ];
        save_products($products);
        $orders = [
            ['id'=>rid(),'seller_id'=>$sellers[0]['id'],'buyer_id'=>$buyer['id'],'product_id'=>$products[0]['id'],'request_id'=>$req['id'],'offer_id'=>$offers[0]['id'],'amount'=>64500000,'status'=>'processing','created_at'=>now(),'order_num'=>'ORD-1403-00230','shipping_method'=>'پست پیشتاز'],
            ['id'=>rid(),'seller_id'=>$sellers[1]['id'],'buyer_id'=>$buyer['id'],'product_id'=>$products[2]['id'],'request_id'=>$requests[1]['id'],'offer_id'=>'','amount'=>12500000,'status'=>'delivered','created_at'=>date('Y-m-d', strtotime('-10 days')),'order_num'=>'ORD-1403-00231','shipping_method'=>'پست پیشتاز'],
            ['id'=>rid(),'seller_id'=>$sellers[2]['id'],'buyer_id'=>$buyer['id'],'product_id'=>'','request_id'=>'','offer_id'=>'','amount'=>850000,'status'=>'shipped','created_at'=>date('Y-m-d', strtotime('-5 days')),'order_num'=>'ORD-1403-00229','shipping_method'=>'تیپاکس'],
        ];
        save_orders($orders);
        $transactions = [
            ['id'=>rid(),'user_id'=>$buyer['id'],'type'=>'debit','amount'=>64500000,'description'=>'پرداخت خرید آیفون 15','reference'=>'ORD-1403-00230','created_at'=>now()],
            ['id'=>rid(),'user_id'=>$buyer['id'],'type'=>'credit','amount'=>5000000,'description':'شارژ کیف پول','reference'=>'شارژ آنلاین','created_at'=>date('Y-m-d', strtotime('-7 days'))],
            ['id'=>rid(),'user_id'=>$buyer['id'],'type'=>'debit','amount'=>12500000,'description'=>'خرید هدفون سونی','reference'=>'ORD-1403-00231','created_at'=>date('Y-m-d', strtotime('-15 days'))],
            ['id'=>rid(),'user_id'=>$sellers[0]['id'],'type'=>'credit','amount'=>64500000,'description'=>'فروش آیفون 15 پرو مکس','reference'=>'ORD-1403-00230','created_at'=>now()],
        ];
        save_transactions($transactions);
        $invoices = [
            ['id'=>rid(),'user_id'=>$buyer['id'],'invoice_num'=>'1227','amount'=>260000,'date'=>'1403/05/20','description'=>'فاکتور شماره 1227','status'=>'paid'],
            ['id'=>rid(),'user_id'=>$buyer['id'],'invoice_num'=>'1177','amount'=>580000,'date'=>'1403/05/15','description'=>'فاکتور شماره 1177','status'=>'paid'],
            ['id'=>rid(),'user_id'=>$buyer['id'],'invoice_num'=>'1155','amount'=>1100000,'date'=>'1403/05/10','description'=>'فاکتور شماره 1155','status'=>'paid'],
        ];
        save_invoices($invoices);
        $addresses = [
            ['id'=>rid(),'user_id'=>$buyer['id'],'title'=>'خانه','address'=>'تهران، خیابان ولیعصر، کوچه نسترن، پلاک ۱۲، واحد ۵','city'=>'تهران','is_default'=>1,'created_at'=>now()],
            ['id'=>rid(),'user_id'=>$buyer['id'],'title'=>'محل کار','address'=>'تهران، شهرک غرب، برج میلاد، طبقه ۱۵','city'=>'تهران','is_default'=>0,'created_at'=>now()],
        ];
        save_addresses($addresses);
        $reviews = [
            ['id'=>rid(),'seller_id'=>$sellers[0]['id'],'buyer_id'=>$buyer['id'],'order_id'=>$orders[0]['id'],'rating'=>5,'comment'=>'عالی بود، سریع ارسال شد و بسته‌بندی حرفه‌ای','created_at'=>now()],
            ['id'=>rid(),'seller_id'=>$sellers[0]['id'],'buyer_id'=>$buyer['id'],'order_id'=>$orders[0]['id'],'rating'=>4,'comment'=>'خوب بود ولی زمان ارسال کمی طولانی بود','created_at'=>now()],
        ];
        save_reviews($reviews);
        $chats = [
            ['id'=>rid(),'from_id'=>$buyer['id'],'to_id'=>$sellers[0]['id'],'request_id'=>$req['id'],'message'=>'سلام، گوشی موجود دارید؟','message_type'=>'text','created_at'=>now()],
            ['id'=>rid(),'from_id'=>$sellers[0]['id'],'to_id'=>$buyer['id'],'request_id'=>$req['id'],'message'=>'سلام، بله موجود داریم با گارانتی ۸ ماهه','message_type'=>'text','created_at'=>now()],
        ];
        save_chats_db($chats);
        save_settings(['allow_admin_register' => true]);
    }
}
seed_if_needed();

// ===== ENRICH =====
function enriched_requests(array $reqs): array {
    $users = get_users(); $offers = get_offers_db();
    $um = []; foreach($users as $u) $um[$u['id']] = $u;
    foreach($reqs as &$r){
        $r['buyer'] = $um[$r['buyer_id']??''] ?? null;
        $ro = array_values(array_filter($offers, fn($o) => ($o['request_id']??'') == ($r['id']??'')));
        $r['offers_count'] = count($ro);
        $prices = array_map(fn($o) => (float)($o['price']??0), $ro);
        $r['best_price'] = $prices ? min($prices) : null;
    }
    return $reqs;
}
function enriched_offers(array $offs): array {
    $offs = safe_array($offs); $users = get_users(); $reqs = get_requests_db();
    $um = []; foreach($users as $u) $um[$u['id']] = $u;
    $rm = []; foreach($reqs as $r) $rm[$r['id']] = $r;
    foreach($offs as &$o){
        $o['seller'] = $um[$o['seller_id']??''] ?? null;
        $o['request'] = $rm[$o['request_id']??''] ?? null;
    }
    usort($offs, fn($a,$b) => ((float)($a['price']??PHP_INT_MAX)) <=> ((float)($b['price']??PHP_INT_MAX)));
    return $offs;
}

// ===== ACTIONS =====
$action = $_POST['action'] ?? $_GET['action'] ?? null;

if ($action === 'register') {
    $users = get_users(); $username = trim($_POST['username'] ?? ''); $name = trim($_POST['name'] ?? '');
    $password = (string)($_POST['password'] ?? ''); $role = trim($_POST['role'] ?? 'buyer');
    $phone = trim($_POST['phone'] ?? ''); $email = trim($_POST['email'] ?? '');
    if (!$username || !$name || !$password) { flash('error','همه فیلدها را پر کنید.'); header('Location: ?page=register'); exit; }
    foreach($users as $u) if(mb_strtolower($u['username'])===mb_strtolower($username)) { flash('error','نام کاربری تکراری.'); header('Location: ?page=register'); exit; }
    $users[] = ['id'=>rid(),'username'=>$username,'name'=>$name,'password'=>password_hash($password,PASSWORD_DEFAULT),'role'=>$role,'avatar'=>'','rating'=>$role==='seller'?4.5:5,'history_count'=>0,'success_rate'=>$role==='seller'?80:100,'wallet_balance'=>0,'phone'=>$phone,'email'=>$email,'created_at'=>now()];
    save_users($users); log_action('register',"ثبت‌نام {$username}"); flash('success','ثبت‌نام موفق.'); header('Location: ?page=login'); exit;
}
if ($action === 'login') {
    $username = trim($_POST['username'] ?? ''); $password = (string)($_POST['password'] ?? '');
    foreach(get_users() as $u) if(mb_strtolower($u['username'])===mb_strtolower($username) && password_verify($password,$u['password']??'')) { $_SESSION['uid']=$u['id']; flash('success','ورود موفق.'); header('Location: ?page='.($u['role']==='seller'?'seller-dashboard':'dashboard')); exit; }
    flash('error','نام کاربری یا رمز نادرست.'); header('Location: ?page=login'); exit;
}
if ($action === 'logout') { session_destroy(); session_start(); flash('success','خارج شدید.'); header('Location: ?'); exit; }
if ($action === 'update_profile') {
    require_login(); $uid = current_user()['id']; $users = get_users();
    foreach($users as &$u) if($u['id']===$uid) { $u['name']=trim($_POST['name']??$u['name']); $u['phone']=trim($_POST['phone']??$u['phone']); $u['email']=trim($_POST['email']??$u['email']); $u['city']=trim($_POST['city']??$u['city']); $u['address']=trim($_POST['address']??$u['address']); break; }
    save_users($users); flash('success','پروفایل به‌روز شد.'); header('Location: ?page=profile'); exit;
}
if ($action === 'update_settings') {
    require_login(); $uid = current_user()['id']; $users = get_users();
    foreach($users as &$u) if($u['id']===$uid) { if(isset($_POST['cur_pass']) && $_POST['cur_pass'] && password_verify($_POST['cur_pass'],$u['password'])) $u['password']=password_hash($_POST['new_pass']??$u['password'],PASSWORD_DEFAULT); break; }
    save_users($users); flash('success','تنظیمات ذخیره شد.'); header('Location: ?page=settings'); exit;
}
if ($action === 'add_address') {
    require_login(); $addrs = get_addresses(); $is_def = isset($_POST['is_default']) && $_POST['is_default']==='1';
    if($is_def) foreach($addrs as &$a) if($a['user_id']===current_user()['id']) $a['is_default']=0;
    $addrs[] = ['id'=>rid(),'user_id'=>current_user()['id'],'title'=>trim($_POST['title']??''),'address'=>trim($_POST['address']??''),'city'=>trim($_POST['city']??''),'is_default'=>$is_def?1:0,'created_at'=>now()];
    save_addresses($addrs); flash('success','آدرس اضافه شد.'); header('Location: ?page=profile'); exit;
}
if ($action === 'delete_address') {
    require_login(); $id = $_GET['id'] ?? ''; $addrs = get_addresses();
    $addrs = array_values(array_filter($addrs, fn($a) => ($a['id']??'') !== $id));
    save_addresses($addrs); flash('success','آدرس حذف شد.'); header('Location: ?page=profile'); exit;
}
if ($action === 'wallet_charge') {
    require_login(); $amount = (float)($_POST['amount'] ?? 0);
    if($amount <= 0) { flash('error','مبلغ معتبر نیست.'); header('Location: ?page=wallet'); exit; }
    $uid = current_user()['id']; $users = get_users();
    foreach($users as &$u) if($u['id']===$uid) { $u['wallet_balance'] = ($u['wallet_balance']??0) + $amount; break; }
    save_users($users);
    $txs = get_transactions(); $txs[] = ['id'=>rid(),'user_id'=>$uid,'type'=>'credit','amount'=>$amount,'description'=>'شارژ کیف پول','reference'=>'شارژ آنلاین','created_at'=>date('Y/m/d')];
    save_transactions($txs); flash('success','کیف پول شارژ شد.'); header('Location: ?page=wallet'); exit;
}
if ($action === 'wallet_withdraw') {
    require_role(['seller']); $amount = (float)($_POST['amount'] ?? 0); $uid = current_user()['id'];
    $users = get_users();
    foreach($users as &$u) if($u['id']===$uid) { if(($u['wallet_balance']??0) < $amount) { flash('error','موجودی کافی نیست.'); header('Location: ?page=seller-wallet'); exit; } $u['wallet_balance'] -= $amount; break; }
    save_users($users);
    $txs = get_transactions(); $txs[] = ['id'=>rid(),'user_id'=>$uid,'type'=>'debit','amount'=>$amount,'description'=>'برداشت از کیف پول','reference'=>'برداشت','created_at'=>date('Y/m/d')];
    save_transactions($txs); flash('success','برداشت ثبت شد.'); header('Location: ?page=seller-wallet'); exit;
}
if ($action === 'send_message') {
    require_login(); $to_id=trim($_POST['to_id']??''); $request_id=trim($_POST['request_id']??''); $message=trim($_POST['message']??'');
    if(!$to_id || !$message) { flash('error','پیام الزامی است.'); header('Location: ?page=chat&user='.urlencode($to_id)); exit; }
    $chats = get_chats_db(); $chats[] = ['id'=>rid(),'from_id'=>current_user()['id'],'to_id'=>$to_id,'request_id'=>$request_id,'message'=>$message,'message_type'=>'text','created_at'=>now()];
    save_chats_db($chats); header('Location: ?page=chat&user='.urlencode($to_id).'&request_id='.urlencode($request_id)); exit;
}
if ($action === 'create_request') {
    require_role(['buyer','admin']); $title=trim($_POST['title']??''); $category=trim($_POST['category']??''); $desc=trim($_POST['description']??''); $budget=(float)($_POST['budget']??0);
    if(!$title || !$category || $budget <= 0) { flash('error','فیلدها را پر کنید.'); header('Location: ?page=dashboard'); exit; }
    $db = get_requests_db(); $db[] = ['id'=>rid(),'buyer_id'=>current_user()['id'],'title'=>$title,'category'=>$category,'description'=>$desc,'budget'=>$budget,'status'=>'open','created_at'=>now()];
    save_requests_db($db); log_action('create_request',"درخواست: {$title}", current_user()['id']); flash('success','درخواست ثبت شد.'); header('Location: ?page=dashboard'); exit;
}
if ($action === 'create_offer') {
    require_role(['seller']); $request_id=trim($_POST['request_id']??''); $price=(float)($_POST['price']??0); $desc=trim($_POST['description']??'');
    $warranty=(int)($_POST['warranty_months']??0); $delivery=(int)($_POST['delivery_days']??0); $dtime=trim($_POST['delivery_time']??''); $disc=(float)($_POST['discount_percent']??0);
    $req = find_request($request_id); if(!$req) { flash('error','درخواست یافت نشد.'); header('Location: ?page=seller-offers'); exit; }
    if($price <= 0) { flash('error','قیمت معتبر نیست.'); header('Location: ?page=seller-offers'); exit; }
    $offs = get_offers_db(); $offs[] = ['id'=>rid(),'request_id'=>$request_id,'seller_id'=>current_user()['id'],'price'=>$price,'description'=>$desc,'warranty_months'=>$warranty,'delivery_days'=>$delivery,'delivery_time'=>$dtime,'discount_percent'=>$disc,'status'=>'pending','created_at'=>now()];
    save_offers_db($offs); flash('success','پیشنهاد ثبت شد.'); header('Location: ?page=seller-offers'); exit;
}
if ($action === 'select_offer') {
    require_role(['buyer']); $offer_id=trim($_GET['id']??''); $offer=find_offer($offer_id); if(!$offer) { flash('error','پیشنهاد یافت نشد.'); header('Location: ?page=dashboard'); exit; }
    $req=find_request($offer['request_id']); if(!$req || ($req['buyer_id']??'')!==current_user()['id']) { flash('error','مجاز نیستید.'); header('Location: ?page=dashboard'); exit; }
    $offs=get_offers_db(); foreach($offs as &$o) if(($o['request_id']??'')===$req['id']) $o['status']=(($o['id']??'')===($offer_id))?'selected':'pending';
    save_offers_db($offs); $reqs=get_requests_db(); foreach($reqs as &$r) if(($r['id']??'')===$req['id']) {$r['status']='selected'; break;}
    save_requests_db($reqs); flash('success','فروشنده انتخاب شد.'); header('Location: ?page=sellers&id='.urlencode($req['id'])); exit;
}
if ($action === 'update_order_status') {
    require_role(['seller']); $id=$_POST['order_id']??''; $status=$_POST['status']??'';
    $orders=get_orders(); foreach($orders as &$o) if($o['id']===$id && $o['seller_id']===current_user()['id']) {$o['status']=$status; break;}
    save_orders($orders); flash('success','وضعیت به‌روز شد.'); header('Location: ?page=seller-orders'); exit;
}
if ($action === 'add_product') {
    require_role(['seller']); $products=get_products();
    $products[] = ['id'=>rid(),'seller_id'=>current_user()['id'],'name'=>trim($_POST['name']??''),'category'=>trim($_POST['category']??''),'price'=>(float)($_POST['price']??0),'stock'=>(int)($_POST['stock']??0),'description'=>trim($_POST['description']??''),'image'=>'','status'=>'active','created_at'=>now()];
    save_products($products); flash('success','محصول اضافه شد.'); header('Location: ?page=seller-products'); exit;
}
if ($action === 'delete_product') {
    require_role(['seller']); $id=$_GET['id']??''; $products=get_products();
    $products=array_values(array_filter($products, fn($p)=>!($p['id']===$id && $p['seller_id']===current_user()['id'])));
    save_products($products); flash('success','حذف شد.'); header('Location: ?page=seller-products'); exit;
}

// ===== ROUTING =====
$page = $_GET['page'] ?? 'home'; $q = trim($_GET['q'] ?? ''); $theme = $_COOKIE['theme'] ?? 'light';
if(isset($_GET['set_theme'])) { $theme = $_GET['set_theme']==='dark'?'dark':'light'; setcookie('theme',$theme,time()+86400*180); header('Location: '.strtok($_SERVER["REQUEST_URI"],'?').'?'.http_build_query(array_diff_key($_GET,['set_theme'=>1]))); exit; }
$requests = sort_desc(enriched_requests(get_requests_db())); $offersAll = enriched_offers(get_offers_db()); $usersAll = get_users(); $flash = get_flash(); $me = current_user();
if($q !== '') { $requests = array_values(array_filter($requests, fn($r) => str_contains(mb_strtolower(($r['title']??'').' '.($r['description']??'')), mb_strtolower($q)))); }

// ===== UI HELPERS =====
function render_stars(float $r): string {
    $f = (int)floor($r); $o = '<span class="stars">'; for($i=1;$i<=5;$i++) $o .= $i<=$f?'★':'☆'; return $o.'</span>';
}
function avatar_html(?array $u, string $sz='md'): string {
    $name = trim((string)($u['name']??'U')); $init = mb_substr($name,0,1);
    $colors = ['#6366f1','#8b5cf6','#ec4899','#f43f5e','#14b8a6','#06b6d4','#22c55e','#f59e0b'];
    $c = $colors[abs(crc32($name))%count($colors)];
    return "<div class=\"avatar {$sz}\" style=\"background:linear-gradient(135deg,{$c},{$c}aa)\">".e($init)."</div>";
}
function icon_svg(string $n): string {
    $icons = [
        'search'=>'<svg viewBox="0 0 24 24"><path d="M21 21l-4.35-4.35"/><circle cx="11" cy="11" r="6"/></svg>',
        'bell'=>'<svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 006 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 01-3.46 0"/></svg>',
        'menu'=>'<svg viewBox="0 0 24 24"><path d="M3 12h18M3 6h18M3 18h18"/></svg>',
        'chat'=>'<svg viewBox="0 0 24 24"><path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/></svg>',
        'user'=>'<svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 00-4-4H8a4 4 0 00-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
        'plus'=>'<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>',
        'moon'=>'<svg viewBox="0 0 24 24"><path d="M21 12.79A9 9 0 1111.21 3 7 7 0 0021 12.79z"/></svg>',
        'sun'=>'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="5"/><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/></svg>',
        'edit'=>'<svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>',
        'trash'=>'<svg viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 012-2h4a2 2 0 012 2v2"/><path d="M19 6l-1 14H6L5 6"/></svg>',
        'check'=>'<svg viewBox="0 0 24 24"><path d="M20 6L9 17l-5-5"/></svg>',
        'filter'=>'<svg viewBox="0 0 24 24"><path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/></svg>',
        'logout'=>'<svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>',
        'dashboard'=>'<svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>',
        'box'=>'<svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
        'wallet'=>'<svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><path d="M1 10h22"/></svg>',
        'settings'=>'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-2 2 2 2 0 01-2-2v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 01-2-2 2 2 0 012-2h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 012-2 2 2 0 012 2v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 012 2 2 2 0 01-2 2h-.09a1.65 1.65 0 00-1.51 1z"/></svg>',
        'location'=>'<svg viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>',
        'phone'=>'<svg viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>',
        'email'=>'<svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>',
        'shield'=>'<svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
        'zap'=>'<svg viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>',
        'sparkles'=>'<svg viewBox="0 0 24 24"><path d="M12 2l2.4 7.2L22 12l-7.6 2.8L12 22l-2.4-7.2L2 12l7.6-2.8z"/></svg>',
        'trending-up'=>'<svg viewBox="0 0 24 24"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>',
        'trending-down'=>'<svg viewBox="0 0 24 24"><polyline points="23 18 13.5 8.5 8.5 13.5 1 6"/><polyline points="17 18 23 18 23 12"/></svg>',
        'mic'=>'<svg viewBox="0 0 24 24"><path d="M12 1a3 3 0 00-3 3v8a3 3 0 006 0V4a3 3 0 00-3-3z"/><path d="M19 10v2a7 7 0 01-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>',
        'send'=>'<svg viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
        'smile'=>'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 14s1.5 2 4 2 4-2 4-2"/><line x1="9" y1="9" x2="9.01" y2="9"/><line x1="15" y1="9" x2="15.01" y2="9"/></svg>',
        'star'=>'<svg viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>',
        'clock'=>'<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
        'truck'=>'<svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
        'award'=>'<svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="7"/><polyline points="8.21 13.89 7 23 12 20 17 23 15.79 13.88"/></svg>',
        'package'=>'<svg viewBox="0 0 24 24"><path d="M16.5 9.4l-9-5.19M21 16V8a2 2 0 00-1-1.73l-7-4a2 2 0 00-2 0l-7 4A2 2 0 003 8v8a2 2 0 001 1.73l7 4a2 2 0 002 0l7-4A2 2 0 0021 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
        'credit-card'=>'<svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
        'home'=>'<svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>',
        'shopping-bag'=>'<svg viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 01-8 0"/></svg>',
        'chart'=>'<svg viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>',
        'download'=>'<svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
        'store'=>'<svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 01-2 2H5a2 2 0 01-2-2z"/><path d="M9 22V12h6v10"/></svg>',
        'clipboard'=>'<svg viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 012 2v14a2 2 0 01-2 2H6a2 2 0 01-2-2V6a2 2 0 012-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/></svg>',
        'copy'=>'<svg viewBox="0 0 24 24"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>',
        'file-text'=>'<svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
        'refresh'=>'<svg viewBox="0 0 24 24"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/></svg>',
        'gift'=>'<svg viewBox="0 0 24 24"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 010-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 000-5C13 2 12 7 12 7z"/></svg>',
        'arrow-left'=>'<svg viewBox="0 0 24 24"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>',
        'chevron-left'=>'<svg viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>',
        'check-circle'=>'<svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        'tag'=>'<svg viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 01-2.83 0L2 12V2h10l8.59 8.59a2 2 0 010 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg>',
        'truck-icon'=>'<svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>',
        'truck-fast'=>'<svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/><path d="M14 1h4l2 4"/></svg>',
        'timer'=>'<svg viewBox="0 0 24 24"><circle cx="12" cy="13" r="8"/><path d="M12 9v4l2 2"/><path d="M5 3L2 6"/><path d="M22 6l-3-3"/><line x1="12" y1="2" x2="12" y2="5"/></svg>',
    ];
    return '<span class="icon">'.($icons[$n]??'').'</span>';
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title><?= e(APP_NAME) ?></title>
<style>
:root{--bg:#f0f4f8;--bg2:#ffffff;--panel:#ffffff;--panel2:#f8fafc;--soft:#f1f5f9;--line:#e2e8f0;--text:#1e293b;--muted:#64748b;--subtle:#94a3b8;--primary:#4f46e5;--primary-light:#e0e7ff;--primary-dark:#3730a3;--success:#10b981;--success-light:#d1fae5;--warning:#f59e0b;--warning-light:#fef3c7;--danger:#ef4444;--danger-light:#fee2e2;--info:#06b6d4;--info-light:#cffafe;--shadow:0 1px 3px rgba(0,0,0,.08),0 4px 12px rgba(0,0,0,.04);--shadow-lg:0 4px 20px rgba(0,0,0,.1),0 8px 30px rgba(0,0,0,.06);--radius:16px;--radius-lg:20px;--radius-xl:24px;--transition:all .25s ease}
body.dark{--bg:#0f172a;--bg2:#1e293b;--panel:#1e293b;--panel2:#334155;--soft:#334155;--line:#475569;--text:#f1f5f9;--muted:#94a3b8;--subtle:#64748b;--primary:#818cf8;--primary-light:#312e81;--primary-dark:#a5b4fc;--success:#34d399;--success-light:#064e3b;--warning:#fbbf24;--warning-light:#78350f;--danger:#f87171;--danger-light:#7f1d1d;--info:#22d3ee;--info-light:#164e63;--shadow:0 1px 3px rgba(0,0,0,.3);--shadow-lg:0 4px 20px rgba(0,0,0,.4)}
*{box-sizing:border-box;margin:0;padding:0}
::selection{background:var(--primary);color:#fff}
html{scroll-behavior:smooth}
body{font-family:'Segoe UI',Tahoma,Vazirmatn,sans-serif;background:var(--bg);color:var(--text);line-height:1.6;min-height:100vh}
a{text-decoration:none;color:inherit}
img{max-width:100%;display:block}
.container{width:min(1200px,100%);margin:auto;padding:0 16px}

/* NAV */
.topnav{background:var(--panel);border-bottom:1px solid var(--line);position:sticky;top:0;z-index:100;padding:12px 0}
.nav-inner{display:flex;align-items:center;justify-content:space-between;gap:12px}
.nav-brand{display:flex;align-items:center;gap:10px;font-weight:800;font-size:18px}
.nav-logo{width:32px;height:32px;border-radius:10px;background:linear-gradient(135deg,#4f46e5,#7c3aed);display:grid;place-items:center;color:#fff;font-weight:900;font-size:16px}
.nav-links{display:flex;align-items:center;gap:6px}
.nav-link{padding:8px 14px;border-radius:12px;font-size:14px;font-weight:600;color:var(--muted);transition:var(--transition)}
.nav-link:hover,.nav-link.active{background:var(--primary-light);color:var(--primary)}
.nav-actions{display:flex;align-items:center;gap:8px}
.icon-btn{width:40px;height:40px;border-radius:12px;display:grid;place-items:center;background:var(--soft);border:none;cursor:pointer;transition:var(--transition);position:relative}
.icon-btn:hover{background:var(--primary-light)}
.icon-btn .icon svg{width:20px;height:20px;stroke:var(--muted);fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.icon-btn .badge-dot{position:absolute;top:8px;right:8px;width:8px;height:8px;background:var(--danger);border-radius:50%;border:2px solid var(--panel)}
.btn{padding:10px 20px;border-radius:12px;font-weight:700;font-size:14px;border:none;cursor:pointer;transition:var(--transition);display:inline-flex;align-items:center;justify-content:center;gap:8px}
.btn .icon svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.btn-primary{background:var(--primary);color:#fff}.btn-primary:hover{opacity:.9;transform:translateY(-1px)}
.btn-success{background:var(--success);color:#fff}
.btn-outline{background:transparent;border:1px solid var(--line);color:var(--text)}.btn-outline:hover{background:var(--soft)}
.btn-ghost{background:transparent;color:var(--muted)}.btn-ghost:hover{background:var(--soft)}
.btn-sm{padding:6px 14px;font-size:13px;border-radius:10px}

/* AVATAR */
.avatar{border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;flex-shrink:0}
.avatar-sm{width:36px;height:36px;font-size:14px}
.avatar-md{width:48px;height:48px;font-size:18px}
.avatar-lg{width:64px;height:64px;font-size:24px}
.avatar-xl{width:80px;height:80px;font-size:32px;border:3px solid #fff;box-shadow:0 0 0 3px var(--primary)}

/* CARDS */
.card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);box-shadow:var(--shadow);padding:20px;transition:var(--transition)}
.card:hover{box-shadow:var(--shadow-lg)}
.card-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.card-title{font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px}

/* BADGE */
.badge{display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:999px;font-size:12px;font-weight:600}
.badge-success{background:var(--success-light);color:var(--success)}
.badge-warning{background:var(--warning-light);color:var(--warning)}
.badge-danger{background:var(--danger-light);color:var(--danger)}
.badge-info{background:var(--info-light);color:var(--info)}
.badge-primary{background:var(--primary-light);color:var(--primary)}
.stars{color:#fbbf24;letter-spacing:1px}

/* GRID */
.grid{display:grid;gap:16px}
.grid-4{grid-template-columns:repeat(4,1fr)}
.grid-3{grid-template-columns:repeat(3,1fr)}
.grid-2{grid-template-columns:repeat(2,1fr)}

/* BOTTOM NAV (Mobile) */
.bottom-nav{position:fixed;bottom:0;left:0;right:0;background:var(--panel);border-top:1px solid var(--line);padding:8px 16px;display:flex;justify-content:space-around;z-index:100;display:none}
.bottom-nav .bn-item{display:flex;flex-direction:column;align-items:center;gap:2px;padding:6px 12px;border-radius:12px;font-size:11px;color:var(--subtle);cursor:pointer;transition:var(--transition)}
.bottom-nav .bn-item.active{color:var(--primary)}
.bottom-nav .bn-item .icon svg{width:22px;height:22px;stroke:currentColor;fill:none;stroke-width:2}
@media(max-width:768px){.bottom-nav{display:flex}.bottom-spacer{height:80px}}

/* FLASH */
.flash{position:fixed;top:20px;left:50%;transform:translateX(-50%);padding:12px 24px;border-radius:12px;font-size:14px;font-weight:600;z-index:200;animation:slideDown .3s ease;box-shadow:var(--shadow-lg)}
.flash-success{background:var(--success);color:#fff}
.flash-error{background:var(--danger);color:#fff}
@keyframes slideDown{from{opacity:0;transform:translateX(-50%) translateY(-20px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}

/* ===== BUYER DASHBOARD ===== */
.welcome-section{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:16px}
.welcome-info{display:flex;align-items:center;gap:16px}
.welcome-text h1{font-size:28px;font-weight:800;margin-bottom:4px}
.welcome-text p{color:var(--muted);font-size:14px}
.welcome-meta{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
.meta-card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:16px;text-align:center}
.meta-card .mc-icon{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;margin:0 auto 8px}
.meta-card .mc-value{font-size:22px;font-weight:800;margin-bottom:2px}
.meta-card .mc-label{font-size:12px;color:var(--muted)}
.meta-card .mc-change{font-size:12px;margin-top:4px;font-weight:600}

/* SPENDING CHART */
.spending-section{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:20px;margin-bottom:24px}
.spending-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.spending-amount{font-size:32px;font-weight:900;color:var(--primary)}
.spending-change{color:var(--success);font-size:14px;font-weight:600}
.chart-container{height:200px;position:relative;margin-top:16px}
.chart-svg{width:100%;height:100%}

/* RECENT ORDERS */
.order-item{display:flex;align-items:center;gap:16px;padding:14px;background:var(--panel2);border-radius:14px;margin-bottom:10px;transition:var(--transition);cursor:pointer}
.order-item:hover{background:var(--soft)}
.order-thumb{width:56px;height:56px;border-radius:12px;background:var(--soft);display:grid;place-items:center;font-size:28px;flex-shrink:0}
.order-details{flex:1;min-width:0}
.order-name{font-weight:700;font-size:14px;margin-bottom:2px}
.order-id{font-size:12px;color:var(--muted)}
.order-price{text-align:left;font-weight:700;font-size:15px;flex-shrink:0}
.order-date{font-size:12px;color:var(--muted);text-align:left}
.order-status{text-align:left;flex-shrink:0}

/* TRANSACTIONS */
.tx-item{display:flex;align-items:center;gap:14px;padding:12px;background:var(--panel2);border-radius:12px;margin-bottom:8px}
.tx-icon{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;flex-shrink:0}
.tx-icon.credit{background:var(--success-light);color:var(--success)}
.tx-icon.debit{background:var(--danger-light);color:var(--danger)}
.tx-icon.reward{background:var(--primary-light);color:var(--primary)}
.tx-info{flex:1}
.tx-desc{font-weight:600;font-size:13px;margin-bottom:2px}
.tx-time{font-size:11px;color:var(--muted)}
.tx-amount{font-weight:700;font-size:14px;flex-shrink:0}
.tx-amount.credit{color:var(--success)}
.tx-amount.debit{color:var(--danger)}

/* ===== ORDERS PAGE ===== */
.orders-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.search-box{display:flex;align-items:center;gap:10px;background:var(--panel2);border:1px solid var(--line);border-radius:12px;padding:10px 16px;width:min(400px,100%)}
.search-box input{border:none;background:transparent;color:var(--text);outline:none;font-size:14px;flex:1}
.filter-chips{display:flex;gap:8px;margin-bottom:20px;overflow-x:auto;padding-bottom:4px}
.chip{padding:8px 18px;border-radius:999px;font-size:13px;font-weight:600;background:var(--panel2);border:1px solid var(--line);color:var(--muted);cursor:pointer;white-space:nowrap;transition:var(--transition)}
.chip.active{background:var(--primary);color:#fff;border-color:var(--primary)}
.order-card-full{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:20px;margin-bottom:12px;display:grid;grid-template-columns:auto 1fr auto;gap:20px;align-items:center;transition:var(--transition)}
.order-card-full:hover{box-shadow:var(--shadow-lg)}
.order-img{width:80px;height:80px;border-radius:16px;background:var(--soft);display:grid;place-items:center;font-size:40px}
.order-info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.order-info-item{display:flex;flex-direction:column;gap:2px}
.order-info-label{font-size:12px;color:var(--muted)}
.order-info-value{font-size:14px;font-weight:600}
.order-info-value a{color:var(--primary);display:inline-flex;align-items:center;gap:4px}
.order-info-value a .icon svg{width:14px;height:14px}
.order-actions-col{display:flex;flex-direction:column;align-items:flex-end;gap:10px}
.order-copy-btn{display:flex;align-items:center;gap:6px;background:var(--soft);border:none;padding:6px 12px;border-radius:8px;font-size:12px;cursor:pointer;color:var(--muted);transition:var(--transition)}
.order-copy-btn:hover{background:var(--primary-light);color:var(--primary)}

/* ===== WALLET PAGE ===== */
.wallet-balance-card{background:linear-gradient(135deg,#4f46e5,#7c3aed);border-radius:var(--radius-xl);padding:28px;color:#fff;margin-bottom:20px;position:relative;overflow:hidden}
.wallet-balance-card::before{content:'';position:absolute;top:-50px;left:-50px;width:200px;height:200px;background:rgba(255,255,255,.1);border-radius:50%}
.wallet-balance-card .wb-label{font-size:14px;opacity:.8}
.wallet-balance-card .wb-amount{font-size:36px;font-weight:900;margin:8px 0}
.wallet-tabs{display:flex;gap:8px;margin-bottom:20px;background:var(--panel2);padding:4px;border-radius:12px;overflow-x:auto}
.wallet-tab{padding:8px 20px;border-radius:10px;font-size:13px;font-weight:600;cursor:pointer;color:var(--muted);transition:var(--transition);white-space:nowrap}
.wallet-tab.active{background:var(--panel);color:var(--text);box-shadow:var(--shadow)}
.tx-full{display:flex;align-items:center;gap:14px;padding:16px;background:var(--panel);border:1px solid var(--line);border-radius:14px;margin-bottom:10px}
.tx-full-icon{width:44px;height:44px;border-radius:12px;display:grid;place-items:center;flex-shrink:0}
.tx-full-info{flex:1}
.tx-full-desc{font-weight:700;font-size:14px;margin-bottom:2px}
.tx-full-ref{font-size:12px;color:var(--muted)}
.tx-full-amount{font-weight:800;font-size:16px;flex-shrink:0}

/* INVOICES */
.invoice-item{display:flex;align-items:center;gap:14px;padding:14px;background:var(--panel);border:1px solid var(--line);border-radius:14px;margin-bottom:8px}
.invoice-icon{width:44px;height:44px;border-radius:10px;background:var(--danger-light);display:grid;place-items:center;color:var(--danger);font-size:12px;font-weight:800;flex-shrink:0}
.invoice-info{flex:1}
.invoice-num{font-weight:700;font-size:14px}
.invoice-date{font-size:12px;color:var(--muted)}
.invoice-amount{font-weight:700;font-size:15px}
.invoice-status{font-size:12px;color:var(--success);font-weight:600}

/* ===== PROFILE PAGE ===== */
.profile-header{background:linear-gradient(135deg,#4f46e5,#7c3aed,#a78bfa);border-radius:var(--radius-xl);padding:32px;color:#fff;margin-bottom:24px;position:relative;overflow:hidden}
.profile-header::before{content:'';position:absolute;top:-40%;right:-10%;width:300px;height:300px;background:rgba(255,255,255,.08);border-radius:50%}
.profile-top{display:flex;align-items:center;gap:20px;position:relative;z-index:1}
.profile-info h2{font-size:24px;font-weight:800;margin-bottom:4px}
.profile-info p{opacity:.8;font-size:14px}
.profile-badges{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
.profile-badge{padding:4px 12px;border-radius:999px;background:rgba(255,255,255,.2);font-size:12px;font-weight:600}
.profile-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-top:20px;position:relative;z-index:1}
.ps-box{background:rgba(255,255,255,.15);border-radius:14px;padding:14px;text-align:center;backdrop-filter:blur(10px)}
.ps-box .ps-val{font-size:22px;font-weight:800}
.ps-box .ps-lbl{font-size:11px;opacity:.8}
.section-box{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:20px;margin-bottom:16px}
.section-title{font-size:16px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.info-row{display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--line);font-size:14px}
.info-row:last-child{border-bottom:none}
.info-lbl{color:var(--muted)}
.info-val{font-weight:600;display:flex;align-items:center;gap:6px}
.address-card{background:var(--panel2);border:1px solid var(--line);border-radius:14px;padding:14px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:start}
.address-info h4{font-weight:700;margin-bottom:4px}
.address-info p{font-size:13px;color:var(--muted);line-height:1.6}

/* ===== BUYER ANALYTICS ===== */
.analytics-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:24px}
.stat-box{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:18px}
.stat-box .sb-icon{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;margin-bottom:10px}
.stat-box .sb-value{font-size:24px;font-weight:800;margin-bottom:2px}
.stat-box .sb-label{font-size:13px;color:var(--muted);margin-bottom:4px}
.stat-box .sb-change{font-size:12px;font-weight:600}

/* ===== SELLER DASHBOARD ===== */
.seller-layout{display:grid;grid-template-columns:260px 1fr;gap:20px}
.seller-sidebar{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:16px;height:fit-content;position:sticky;top:80px}
.sidebar-menu{display:grid;gap:4px}
.sb-item{display:flex;align-items:center;gap:12px;padding:10px 14px;border-radius:12px;font-size:14px;font-weight:600;color:var(--muted);cursor:pointer;transition:var(--transition)}
.sb-item:hover,.sb-item.active{background:var(--primary-light);color:var(--primary)}
.sb-item.active{background:var(--primary);color:#fff}
.sb-item .sb-badge{margin-right:auto;background:var(--primary);color:#fff;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:700}
.sb-item.active .sb-badge{background:rgba(255,255,255,.3)}
.welcome-banner{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:24px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px}
.welcome-banner h2{font-size:22px;font-weight:800}
.welcome-banner p{color:var(--muted);font-size:14px;margin-top:4px}
.quick-offer-card{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:20px}
.revenue-chart{height:160px;position:relative;margin-top:12px}
.seller-rating{display:grid;gap:8px}
.rating-bar{display:flex;align-items:center;gap:8px;font-size:13px}
.rating-bar .rb-fill{height:6px;background:var(--line);border-radius:3px;flex:1;overflow:hidden}
.rating-bar .rb-fill div{height:100%;background:var(--warning);border-radius:3px}
.rating-bar .rb-count{min-width:24px;text-align:left;color:var(--muted);font-size:12px}

/* ===== BUYER REQUESTS TABLE ===== */
.requests-table{width:100%;border-collapse:separate;border-spacing:0}
.requests-table th{text-align:right;padding:12px 16px;font-size:13px;color:var(--muted);font-weight:600;border-bottom:1px solid var(--line)}
.requests-table td{padding:14px 16px;font-size:14px;border-bottom:1px solid var(--line)}
.requests-table tr:hover td{background:var(--soft)}
.requests-table tr:last-child td{border-bottom:none}

/* MODAL */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:300;place-items:center}
.modal-overlay.show{display:grid}
.modal-box{background:var(--panel);border-radius:var(--radius-lg);padding:24px;width:min(560px,95%);max-height:90vh;overflow:auto;box-shadow:var(--shadow-lg)}
.modal-title{font-size:18px;font-weight:700;margin-bottom:16px;display:flex;align-items:center;justify-content:space-between}
.modal-close{width:32px;height:32px;border-radius:8px;display:grid;place-items:center;background:var(--soft);border:none;cursor:pointer;font-size:18px;color:var(--muted)}

/* FORMS */
.form-group{margin-bottom:14px}
.form-label{display:block;font-size:13px;font-weight:600;color:var(--muted);margin-bottom:6px}
.form-input,.form-select,.form-textarea{width:100%;padding:12px 16px;border:1px solid var(--line);border-radius:12px;background:var(--panel2);color:var(--text);outline:none;font-size:14px;transition:var(--transition)}
.form-input:focus,.form-select:focus,.form-textarea:focus{border-color:var(--primary);box-shadow:0 0 0 3px var(--primary-light)}
.form-textarea{min-height:80px;resize:vertical}

/* RESPONSIVE */
@media(max-width:1024px){
    .seller-layout{grid-template-columns:1fr}
    .seller-sidebar{position:static;display:flex;overflow-x:auto;padding:10px}
    .sidebar-menu{display:flex;gap:4px}
    .sb-item{white-space:nowrap}
}
@media(max-width:768px){
    .welcome-meta,.profile-stats,.analytics-stats{grid-template-columns:repeat(2,1fr)}
    .order-card-full{grid-template-columns:1fr;text-align:center}
    .order-actions-col{align-items:center;flex-direction:row;flex-wrap:wrap;justify-content:center}
    .order-info-grid{grid-template-columns:1fr}
    .nav-links{display:none}
    .welcome-section{flex-direction:column;text-align:center}
    .grid-4{grid-template-columns:repeat(2,1fr)}
    .grid-3{grid-template-columns:1fr}
    .grid-2{grid-template-columns:1fr}
}
@media(max-width:480px){
    .welcome-meta,.profile-stats,.analytics-stats{grid-template-columns:1fr 1fr}
    .wallet-balance-card .wb-amount{font-size:28px}
}

/* MISC */
.empty-state{text-align:center;padding:40px;color:var(--muted)}
.empty-state .empty-icon{font-size:48px;margin-bottom:12px}
.divider{height:1px;background:var(--line);margin:16px 0}
.text-center{text-align:center}
.text-muted{color:var(--muted)}
.flex{display:flex}.flex-between{display:flex;justify-content:space-between;align-items:center}
.gap-8{gap:8px}.gap-12{gap:12px}.gap-16{gap:16px}
.mt-8{margin-top:8px}.mt-16{margin-top:16px}.mt-24{margin-top:24px}
.mb-8{margin-bottom:8px}.mb-16{margin-bottom:16px}.mb-24{margin-bottom:24px}
.w-full{width:100%}
</style>
</head>
<body class="<?= $theme==='dark'?'dark':'' ?>">

<?php if($flash): ?><div class="flash flash-<?=e($flash['type'])?>"><?=e($flash['msg'])?></div><?php endif; ?>

<!-- TOP NAV -->
<div class="topnav">
    <div class="container nav-inner">
        <div class="nav-brand">
            <div class="nav-logo">B</div>
            <span><?=e(APP_NAME)?></span>
        </div>
        <div class="nav-links">
            <a class="nav-link <?= $page==='home'?'active':'' ?>" href="?">خانه</a>
            <?php if(is_logged_in()): ?>
                <?php if(is_buyer()): ?>
                    <a class="nav-link <?=in_array($page,['dashboard','buyer-analytics'])?'active':''?>" href="?page=dashboard">داشبورد</a>
                    <a class="nav-link <?=$page==='orders'?'active':''?>" href="?page=orders">سفارش‌ها</a>
                    <a class="nav-link <?=$page==='wallet'?'active':''?>" href="?page=wallet">کیف پول</a>
                    <a class="nav-link <?=$page==='profile'||$page==='settings'?'active':''?>" href="?page=profile">پروفایل</a>
                <?php elseif(is_seller()): ?>
                    <a class="nav-link <?=$page==='seller-dashboard'?'active':''?>" href="?page=seller-dashboard">داشبورد</a>
                    <a class="nav-link <?=$page==='seller-orders'?'active':''?>" href="?page=seller-orders">سفارش‌ها</a>
                    <a class="nav-link <?=$page==='seller-wallet'?'active':''?>" href="?page=seller-wallet">مالی</a>
                    <a class="nav-link <?=$page==='seller-shop'?'active':''?>" href="?page=seller-shop">فروشگاه</a>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="nav-actions">
            <button class="icon-btn" onclick="location.href='?set_theme=<?=e($theme==='light'?'dark':'light')?>'"><?=icon_svg($theme==='light'?'moon':'sun')?></button>
            <?php if(is_logged_in()): ?>
                <a class="icon-btn" href="?page=chat&user=<?=e($me['id'])?>"><div class="badge-dot"></div><?=icon_svg('chat')?></a>
                <a class="icon-btn" href="?page=<?=is_seller()?'seller-shop':'profile'?>"><?=avatar_html($me,'sm')?></a>
            <?php else: ?>
                <a class="btn btn-primary btn-sm" href="?page=login">ورود</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="container" style="padding-top:20px;padding-bottom:40px">

<?php if($page==='home' && !is_logged_in()): ?>
    <div class="card text-center" style="padding:60px 30px;margin-top:40px">
        <div style="font-size:64px;margin-bottom:16px">🛒</div>
        <h1 style="font-size:28px;margin-bottom:12px">به <?=e(APP_NAME)?> خوش آمدید</h1>
        <p class="text-muted" style="margin-bottom:24px">بهترین قیمت را از فروشندگان معتبر دریافت کنید</p>
        <div style="display:flex;gap:12px;justify-content:center">
            <a class="btn btn-primary" href="?page=register">ثبت‌نام رایگان</a>
            <a class="btn btn-outline" href="?page=login">ورود</a>
        </div>
    </div>
<?php endif; ?>

<?php if($page==='login'): ?>
    <div class="card" style="max-width:420px;margin:40px auto;padding:32px">
        <h2 style="text-align:center;margin-bottom:24px">ورود به حساب</h2>
        <form method="post">
            <input type="hidden" name="action" value="login">
            <div class="form-group"><label class="form-label">نام کاربری</label><input class="form-input" name="username" required></div>
            <div class="form-group"><label class="form-label">رمز عبور</label><input class="form-input" type="password" name="password" required></div>
            <button class="btn btn-primary w-full" type="submit">ورود</button>
        </form>
        <p class="text-center text-muted mt-16" style="font-size:13px">دمو: buyer/buyer123 | seller1/seller123</p>
    </div>
<?php endif; ?>

<?php if($page==='register'): ?>
    <div class="card" style="max-width:500px;margin:40px auto;padding:32px">
        <h2 style="text-align:center;margin-bottom:24px">ثبت‌نام</h2>
        <form method="post">
            <input type="hidden" name="action" value="register">
            <div class="grid grid-2">
                <div class="form-group"><label class="form-label">نام کامل</label><input class="form-input" name="name" required></div>
                <div class="form-group"><label class="form-label">نام کاربری</label><input class="form-input" name="username" required></div>
            </div>
            <div class="form-group"><label class="form-label">رمز عبور</label><input class="form-input" type="password" name="password" required></div>
            <div class="form-group"><label class="form-label">نقش</label><select class="form-select" name="role"><option value="buyer">خریدار</option><option value="seller">فروشنده</option></select></div>
            <button class="btn btn-primary w-full" type="submit">ثبت‌نام</button>
        </form>
    </div>
<?php endif; ?>

<?php if($page==='dashboard' && is_buyer()):
    $myOrders = array_values(array_filter(get_orders(), fn($o)=>($o['buyer_id']??'')===$me['id']));
    $myOrders = sort_desc($myOrders);
    $myRequests = array_values(array_filter($requests, fn($r)=>($r['buyer_id']??'')===$me['id']));
    $myTx = array_values(array_filter(get_transactions(), fn($t)=>($t['user_id']??'')===$me['id']));
    $myTx = sort_desc($myTx);
    $totalSpent = array_sum(array_map(fn($o)=>($o['status']??'')!=='cancelled'?($o['amount']??0):0, $myOrders));
    $orderCount = count($myOrders);
    $avgOrder = $orderCount ? $totalSpent/$orderCount : 0;
    $activeRequests = count(array_filter($myRequests, fn($r)=>($r['status']??'')==='open'));
?>
    <!-- Welcome -->
    <div class="welcome-section">
        <div class="welcome-info">
            <?=avatar_html($me,'lg')?>
            <div class="welcome-text">
                <h1>سلام، <?=e(mb_substr($me['name'],0,mb_strpos($me['name'],' ')?:99))?>! 👋</h1>
                <div class="profile-badges">
                    <span class="profile-badge" style="background:var(--primary-light);color:var(--primary)">⭐ عضو ویژه</span>
                </div>
            </div>
        </div>
        <div style="display:flex;gap:12px">
            <a class="btn btn-primary" href="#" onclick="document.getElementById('requestModal').classList.add('show')"><?=icon_svg('plus')?> درخواست جدید</a>
        </div>
    </div>

    <!-- Meta Cards -->
    <div class="welcome-meta">
        <div class="meta-card">
            <div class="mc-icon" style="background:var(--primary-light);color:var(--primary)"><?=icon_svg('shopping-bag')?></div>
            <div class="mc-value"><?= $orderCount ?></div>
            <div class="mc-label">کل سفارش‌ها</div>
        </div>
        <div class="meta-card">
            <div class="mc-icon" style="background:var(--success-light);color:var(--success)"><?=icon_svg('star')?></div>
            <div class="mc-value"><?=number_format((float)($me['rating']??0),1)?></div>
            <div class="mc-label">امتیاز خریدار</div>
        </div>
        <div class="meta-card">
            <div class="mc-icon" style="background:var(--info-light);color:var(--info)"><?=icon_svg('shield')?></div>
            <div class="mc-value"><?= (int)$me['success_rate'] ?>%</div>
            <div class="mc-label">نرخ موفقیت</div>
        </div>
        <div class="meta-card">
            <div class="mc-icon" style="background:var(--warning-light);color:var(--warning)"><?=icon_svg('gift')?></div>
            <div class="mc-value"><?= number_format($me['reward_points']??0) ?></div>
            <div class="mc-label">امتیاز جایزه</div>
            <div style="font-size:11px;color:var(--primary);cursor:pointer;margin-top:4px">redeem →</div>
        </div>
    </div>

    <!-- Stats Row -->
    <div class="grid grid-4 mb-24">
        <div class="card" style="background:var(--primary-light);border-color:transparent">
            <div class="flex-between">
                <div><div style="font-size:13px;color:var(--primary);margin-bottom:4px">موجودی کیف پول</div><div style="font-size:24px;font-weight:800;color:var(--primary-dark)"><?=e(money($me['wallet_balance']??0))?></div></div>
                <div style="width:40px;height:40px;border-radius:10px;background:var(--primary);display:grid;place-items:center;color:#fff"><?=icon_svg('wallet')?></div>
            </div>
            <a class="btn btn-sm" style="background:var(--primary);color:#fff;margin-top:10px;width:100%" href="?page=wallet">افزایش موجودی →</a>
        </div>
        <div class="card">
            <div style="font-size:13px;color:var(--muted);margin-bottom:4px">سفارش این ماه</div>
            <div style="font-size:24px;font-weight:800"><?= count(array_filter($myOrders, fn($o)=>str_starts_with($o['created_at']??'','2024-05')||str_starts_with($o['created_at']??'','2025-05'))) ?: $orderCount ?></div>
            <div class="mc-change" style="color:var(--success)">↑ ۲۰٪ از ماه قبل</div>
        </div>
        <div class="card">
            <div style="font-size:13px;color:var(--muted);margin-bottom:4px">درخواست‌های فعال</div>
            <div style="font-size:24px;font-weight:800"><?= $activeRequests ?></div>
            <a class="btn btn-sm btn-ghost" href="?page=dashboard" style="margin-top:4px">مشاهده ←</a>
        </div>
        <div class="card">
            <div style="font-size:13px;color:var(--muted);margin-bottom:4px">عضویت از</div>
            <div style="font-size:20px;font-weight:800"><?= e($me['member_since']??'۴۰۳/۰۱') ?></div>
            <div style="font-size:12px;color:var(--muted);margin-top:2px">از <?= e($me['created_at']??'') ?></div>
        </div>
    </div>

    <!-- Spending Overview Chart -->
    <div class="spending-section">
        <div class="spending-header">
            <div>
                <div style="font-size:16px;font-weight:700;margin-bottom:4px">نمای کلی هزینه‌ها</div>
                <div class="spending-amount"><?= e(money($totalSpent)) ?></div>
                <div class="spending-change">↑ ۸٪ نسبت به ۳۰ روز قبل</div>
            </div>
            <select class="form-select" style="width:auto"><option>۳۰ روز اخیر</option><option>این ماه</option><option>امسال</option></select>
        </div>
        <div class="chart-container">
            <svg class="chart-svg" viewBox="0 0 800 200" preserveAspectRatio="none">
                <defs>
                    <linearGradient id="chartGrad" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0%" stop-color="var(--primary)" stop-opacity=".2"/>
                        <stop offset="100%" stop-color="var(--primary)" stop-opacity="0"/>
                    </linearGradient>
                </defs>
                <path d="M0,180 L80,160 L160,140 L240,150 L320,100 L400,60 L480,80 L560,50 L640,70 L720,40 L800,55 L800,200 L0,200Z" fill="url(#chartGrad)"/>
                <polyline points="0,180 80,160 160,140 240,150 320,100 400,60 480,80 560,50 640,70 720,40 800,55" fill="none" stroke="var(--primary)" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
                <circle cx="400" cy="60" r="6" fill="var(--primary)" stroke="#fff" stroke-width="2"/>
                <rect x="360" y="15" width="80" height="36" rx="8" fill="var(--primary)"/>
                <text x="400" y="32" text-anchor="middle" fill="#fff" font-size="12" font-weight="700"><?=e(format_num($totalSpent/5))?> ت</text>
            </svg>
            <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-top:8px">
                <span>فروردین</span><span>اردیبهشت</span><span>خرداد</span><span>تیر</span><span>مرداد</span><span>شهریور</span>
            </div>
        </div>
    </div>

    <!-- Recent Orders & Transactions -->
    <div class="grid grid-2">
        <div class="card">
            <div class="card-header">
                <div class="card-title"><?=icon_svg('shopping-bag')?> سفارش‌های اخیر</div>
                <a class="btn btn-ghost btn-sm" href="?page=orders">مشاهده همه →</a>
            </div>
            <?php foreach(array_slice($myOrders,0,3) as $ord):
                $prod = find_product($ord['product_id']??'');
                $seller = find_user($ord['seller_id']??'');
            ?>
            <div class="order-item" onclick="location.href='?page=orders'">
                <div class="order-thumb"><?=e($prod?cat_icon($prod['category']):'')?></div>
                <div class="order-details">
                    <div class="order-name"><?=e($prod?$prod['name']:'سفارش')?></div>
                    <div class="order-id">Order ID: #<?=e($ord['order_num']??mb_substr($ord['id'],0,8))?></div>
                </div>
                <div>
                    <div class="order-price"><?=e(money($ord['amount']))?></div>
                    <div class="order-date"><?=e(mb_substr($ord['created_at'],0,10))?></div>
                </div>
                <div class="order-status">
                    <?php
                    $st = $ord['status']??'';
                    $cls = $st==='delivered'?'success':($st==='shipped'?'info':($st==='processing'?'warning':'danger'));
                    echo '<span class="badge badge-'.$cls.'">'.e(status_label($st)).'</span>';
                    ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if(!$myOrders): ?><div class="empty-state"><div class="empty-icon">📦</div>هنوز سفارشی ندارید</div><?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title"><?=icon_svg('credit-card')?> تراکنش‌های اخیر</div>
                <a class="btn btn-ghost btn-sm" href="?page=wallet">مشاهده همه →</a>
            </div>
            <?php foreach(array_slice($myTx,0,4) as $tx): ?>
            <div class="tx-item">
                <div class="tx-icon <?=e($tx['type'])?>"><?=icon_svg($tx['type']==='credit'?'plus':($tx['type']==='debit'?'credit-card':'gift'))?></div>
                <div class="tx-info">
                    <div class="tx-desc"><?=e($tx['description'])?></div>
                    <div class="tx-time"><?=e($tx['created_at'])?></div>
                </div>
                <div class="tx-amount <?=e($tx['type'])?>"><?=($tx['type']==='credit'?'+':'-') ?><?=e(money($tx['amount']))?></div>
            </div>
            <?php endforeach; ?>
            <?php if(!$myTx): ?><div class="empty-state"><div class="empty-icon">💳</div>تراکنشی ثبت نشده</div><?php endif; ?>
        </div>
    </div>

    <!-- Requests Table -->
    <div class="card mt-24">
        <div class="card-header">
            <div class="card-title"><?=icon_svg('clipboard')?> درخواست‌های من</div>
            <button class="btn btn-primary btn-sm" onclick="document.getElementById('requestModal').classList.add('show')"><?=icon_svg('plus')?> درخواست جدید</button>
        </div>
        <div style="overflow-x:auto">
        <table class="requests-table">
            <thead><tr><th>عنوان</th><th>دسته</th><th>بودجه</th><th>پیشنهادات</th><th>وضعیت</th><th>عملیات</th></tr></thead>
            <tbody>
            <?php foreach($myRequests as $r): ?>
            <tr>
                <td><strong><?=e($r['title'])?></strong></td>
                <td><?=e($r['category'])?></td>
                <td><?=e(money($r['budget']))?></td>
                <td><?= (int)$r['offers_count'] ?></td>
                <td><span class="badge badge-<?=($r['status']??'')==='completed'?'success':(($r['status']??'')==='open'?'info':'warning')?>"><?=e(status_label($r['status']??''))?></span></td>
                <td><a class="btn btn-outline btn-sm" href="?page=sellers&id=<?=e($r['id'])?>">مشاهده</a></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
<?php endif; ?>

<?php if($page==='orders' && is_buyer()):
    $myOrders = array_values(array_filter(get_orders(), fn($o)=>($o['buyer_id']??'')===$me['id']));
    $myOrders = sort_desc($myOrders);
    $statusFilter = $_GET['status'] ?? 'all';
    if($statusFilter!=='all') $myOrders = array_values(array_filter($myOrders, fn($o)=>($o['status']??'')===$statusFilter));
?>
    <div class="orders-header">
        <h2>📦 سفارش‌های من</h2>
        <div class="search-box">
            <?=icon_svg('search')?>
            <input type="text" placeholder="جستجو در سفارش‌ها...">
        </div>
    </div>
    <div class="filter-chips">
        <span class="chip <?= $statusFilter==='all'?'active':'' ?>" onclick="location.href='?page=orders&status=all'">همه</span>
        <span class="chip <?= $statusFilter==='delivered'?'active':'' ?>" onclick="location.href='?page=orders&status=delivered'">تحویل شده</span>
        <span class="chip <?= $statusFilter==='shipped'?'active':'' ?>" onclick="location.href='?page=orders&status=shipped'">در حال ارسال</span>
        <span class="chip <?= $statusFilter==='processing'?'active':'' ?>" onclick="location.href='?page=orders&status=processing'">در انتظار</span>
        <span class="chip <?= $statusFilter==='cancelled'?'active':'' ?>" onclick="location.href='?page=orders&status=cancelled'">لغو شده</span>
    </div>
    <?php foreach($myOrders as $ord):
        $prod = find_product($ord['product_id']??'');
        $seller = find_user($ord['seller_id']??'');
        $st = $ord['status']??'';
        $stLabel = status_label($st);
        $stIcon = $st==='delivered'?'check-circle':($st==='shipped'?'truck-icon':($st==='processing'?'clock':'trash'));
        $stColor = $st==='delivered'?'success':($st==='shipped'?'info':($st==='processing'?'warning':'danger'));
    ?>
    <div class="order-card-full">
        <div class="order-img"><?=e($prod?cat_icon($prod['category']):'📦')?></div>
        <div>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px">
                <strong style="font-size:16px"><?=e($prod?$prod['name']:'محصول')?></strong>
                <span class="text-muted" style="font-size:13px">فروشنده: <?=e($seller['name']??'-')?></span>
            </div>
            <div class="order-info-grid" style="margin-top:12px">
                <div class="order-info-item"><span class="order-info-label">شماره سفارش</span><span class="order-info-value"><a href="#" onclick="navigator.clipboard.writeText('#<?=e($ord['order_num']??'')?>')"><?=icon_svg('copy')?> #<?=e($ord['order_num']??mb_substr($ord['id'],0,8))?></a></span></div>
                <div class="order-info-item"><span class="order-info-label">تاریخ سفارش</span><span class="order-info-value"><?=e(mb_substr($ord['created_at'],0,10))?></span></div>
                <div class="order-info-item"><span class="order-info-label">روش ارسال</span><span class="order-info-value"><?=icon_svg('truck')?> <?=e($ord['shipping_method']??'-')?></span></div>
                <div class="order-info-item"><span class="order-info-label">قیمت</span><span class="order-info-value" style="color:var(--success);font-weight:800"><?=e(money($ord['amount']))?></span></div>
            </div>
        </div>
        <div class="order-actions-col">
            <span class="badge badge-<?=$stColor?>"><?=icon_svg($stIcon)?> <?=$stLabel?></span>
            <a class="btn btn-outline btn-sm" href="#">مشاهده جزئیات <?=icon_svg('chevron-left')?></a>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if(!$myOrders): ?><div class="empty-state"><div class="empty-icon">📦</div>سفارشی یافت نشد</div><?php endif; ?>
<?php endif; ?>

<?php if($page==='wallet' && is_buyer()):
    $myTx = array_values(array_filter(get_transactions(), fn($t)=>($t['user_id']??'')===$me['id']));
    $myTx = sort_desc($myTx);
    $invoices = array_values(array_filter(get_invoices(), fn($i)=>($i['user_id']??'')===$me['id']));
    $walletTab = $_GET['tab'] ?? 'all';
    $filteredTx = $myTx;
    if($walletTab==='wallet') $filteredTx = array_filter($myTx, fn($t)=>str_contains($t['description']??'','شارژ'));
    elseif($walletTab==='payment') $filteredTx = array_filter($myTx, fn($t)=>($t['type']??'')==='debit');
    elseif($walletTab==='refund') $filteredTx = array_filter($myTx, fn($t)=>str_contains($t['description']??'','بازپرداخت'));
    $filteredTx = sort_desc(array_values($filteredTx));
?>
    <h2 style="margin-bottom:20px">💰 صورت‌حساب مالی</h2>
    <div class="wallet-balance-card">
        <div class="wb-label">موجودی کیف پول</div>
        <div class="wb-amount"><?=e(money($me['wallet_balance']??0))?></div>
        <button class="btn" style="background:#fff;color:var(--primary);font-weight:700" onclick="document.getElementById('chargeModal').classList.add('show')">افزایش موجودی</button>
    </div>
    <div class="wallet-tabs">
        <span class="wallet-tab <?= $walletTab==='all'?'active':'' ?>" onclick="location.href='?page=wallet&tab=all'">همه</span>
        <span class="wallet-tab <?= $walletTab==='wallet'?'active':'' ?>" onclick="location.href='?page=wallet&tab=wallet'">کیف پول</span>
        <span class="wallet-tab <?= $walletTab==='payment'?'active':'' ?>" onclick="location.href='?page=wallet&tab=payment'">پرداخت‌ها</span>
        <span class="wallet-tab <?= $walletTab==='refund'?'active':'' ?>" onclick="location.href='?page=wallet&tab=refund'">بازپرداخت‌ها</span>
    </div>
    <h3 style="margin-bottom:12px">تراکنش‌های اخیر</h3>
    <?php foreach($filteredTx as $tx): ?>
    <div class="tx-full">
        <div class="tx-full-icon" style="background:<?=($tx['type']??'')==='credit'?'var(--success-light)':'var(--danger-light)';?>;color:<?=($tx['type']??'')==='credit'?'var(--success)':'var(--danger)'?>">
            <?=icon_svg($tx['type']==='credit'?'plus':'credit-card')?>
        </div>
        <div class="tx-full-info">
            <div class="tx-full-desc"><?=e($tx['description'])?></div>
            <div class="tx-full-ref"><?=e($tx['reference']??'')?></div>
        </div>
        <div class="tx-full-amount" style="color:<?=($tx['type']??'')==='credit'?'var(--success)':'var(--danger)'?>">
            <?=($tx['type']==='credit'?'+':'-') ?><?=e(format_num($tx['amount']))?> <small>تومان</small>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="divider"></div>
    <h3 style="margin-bottom:12px">صورت‌حساب‌ها</h3>
    <?php foreach($invoices as $inv): ?>
    <div class="invoice-item">
        <div class="invoice-icon">POP</div>
        <div class="invoice-info">
            <div class="invoice-num">فاکتور شماره <?=e($inv['invoice_num'])?></div>
            <div class="invoice-date"><?=e($inv['date'])?></div>
        </div>
        <div style="text-align:left">
            <div class="invoice-amount"><?=e(format_num($inv['amount']))?> <small>تومان</small></div>
            <div class="invoice-status">پرداخت شده</div>
        </div>
    </div>
    <?php endforeach; ?>

    <div id="chargeModal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('show')">
        <div class="modal-box">
            <div class="modal-title">افزایش موجودی <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('show')"></button></div>
            <form method="post" action="?action=wallet_charge">
                <div class="form-group"><label class="form-label">مبلغ (تومان)</label><input class="form-input" type="number" name="amount" required min="10000"></div>
                <button class="btn btn-primary w-full" type="submit">شارژ کیف پول</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if($page==='profile' && is_buyer()):
    $myAddresses = array_values(array_filter(get_addresses(), fn($a)=>($a['user_id']??'')===$me['id']));
?>
    <div class="profile-header">
        <div class="profile-top">
            <div class="avatar-xl"><?=avatar_html($me,'xl')?></div>
            <div class="profile-info">
                <h2><?=e($me['name'])?></h2>
                <p><?=e($me['email']??'')?> • <?=e($me['phone']??'')?></p>
                <div class="profile-badges">
                    <span class="profile-badge">⭐ عضو ویژه</span>
                    <span class="profile-badge">🛒 خریدار</span>
                    <span class="profile-badge">📍 <?=e($me['city']??'')?></span>
                </div>
            </div>
        </div>
        <div class="profile-stats">
            <div class="ps-box"><div class="ps-val"><?= (int)$me['history_count'] ?></div><div class="ps-lbl">کل سفارش‌ها</div></div>
            <div class="ps-box"><div class="ps-val"><?= number_format((float)($me['rating']??0),1) ?> ⭐</div><div class="ps-lbl">امتیاز خریدار</div></div>
            <div class="ps-box"><div class="ps-val"><?= (int)$me['success_rate'] ?>%</div><div class="ps-lbl">نرخ موفقیت</div></div>
            <div class="ps-box"><div class="ps-val"><?= e($me['member_since']??'۱۴۰۳') ?></div><div class="ps-lbl">عضو از</div></div>
        </div>
    </div>

    <div class="grid grid-2">
        <div class="section-box">
            <div class="section-title"><?=icon_svg('user')?> مشخصات خریدار <a class="btn btn-ghost btn-sm" href="?page=settings">ویرایش</a></div>
            <div class="info-row"><span class="info-lbl">نام کامل</span><span class="info-val"><?=e($me['name'])?></span></div>
            <div class="info-row"><span class="info-lbl">شهر</span><span class="info-val"><?=e($me['city']??'-')?></span></div>
            <div class="info-row"><span class="info-lbl">آدرس</span><span class="info-val"><?=e($me['address']??'-')?></span></div>
            <div class="info-row"><span class="info-lbl">روش پرداخت</span><span class="info-val"><?=icon_svg('credit-card')?> کارت بانکی</span></div>
        </div>
        <div class="section-box">
            <div class="flex-between mb-16">
                <div class="section-title" style="margin:0"><?=icon_svg('location')?> آدرس‌ها</div>
                <button class="btn btn-primary btn-sm" onclick="document.getElementById('addrModal').classList.add('show')"><?=icon_svg('plus')?> آدرس جدید</button>
            </div>
            <?php foreach($myAddresses as $addr): ?>
            <div class="address-card">
                <div class="address-info">
                    <h4><?=e($addr['title'])?> <?php if($addr['is_default']):?><span class="badge badge-primary" style="font-size:11px">پیش‌فرض</span><?php endif;?></h4>
                    <p><?=e($addr['address'])?></p>
                </div>
                <a class="btn btn-ghost btn-sm" href="?action=delete_address&id=<?=e($addr['id'])?>" onclick="return confirm('حذف شود؟')"><?=icon_svg('trash')?></a>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="section-box">
        <div class="section-title"><?=icon_svg('shopping-bag')?> خریدهای قبلی</div>
        <?php foreach(array_slice(array_values(array_filter(get_orders(),fn($o)=>($o['buyer_id']??'')===$me['id'])),0,3) as $ord):
            $prod=find_product($ord['product_id']??'');
        ?>
        <div class="order-item">
            <div class="order-thumb"><?=e($prod?cat_icon($prod['category']):'📦')?></div>
            <div class="order-details">
                <div class="order-name"><?=e($prod?$prod['name']:'محصول')?></div>
                <div class="order-id"><?=e(mb_substr($ord['created_at'],0,10))?></div>
            </div>
            <div class="order-price"><?=e(money($ord['amount']))?></div>
            <span class="badge badge-success">تحویل شده</span>
        </div>
        <?php endforeach; ?>
    </div>

    <div id="addrModal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('show')">
        <div class="modal-box">
            <div class="modal-title">آدرس جدید <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('show')"></button></div>
            <form method="post" action="?action=add_address">
                <div class="form-group"><label class="form-label">عنوان</label><input class="form-input" name="title" required></div>
                <div class="form-group"><label class="form-label">شهر</label><input class="form-input" name="city" required></div>
                <div class="form-group"><label class="form-label">آدرس</label><textarea class="form-textarea" name="address" required></textarea></div>
                <div class="form-group"><label><input type="checkbox" name="is_default" value="1"> آدرس پیش‌فرض</label></div>
                <button class="btn btn-primary w-full" type="submit">ذخیره</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if($page==='settings'): require_login(); ?>
    <h2 style="margin-bottom:20px"><?=icon_svg('settings')?> تنظیمات حساب</h2>
    <div class="grid grid-2">
        <div class="section-box">
            <div class="section-title"><?=icon_svg('user')?> اطلاعات شخصی</div>
            <form method="post" action="?action=update_profile">
                <div class="form-group"><label class="form-label">نام کامل</label><input class="form-input" name="name" value="<?=e($me['name'])?>" required></div>
                <div class="form-group"><label class="form-label">تلفن</label><input class="form-input" name="phone" value="<?=e($me['phone']??'')?>"></div>
                <div class="form-group"><label class="form-label">ایمیل</label><input class="form-input" name="email" value="<?=e($me['email']??'')?>"></div>
                <div class="form-group"><label class="form-label">شهر</label><input class="form-input" name="city" value="<?=e($me['city']??'')?>"></div>
                <div class="form-group"><label class="form-label">آدرس</label><textarea class="form-textarea" name="address"><?=e($me['address']??'')?></textarea></div>
                <button class="btn btn-primary" type="submit">ذخیره تغییرات</button>
            </form>
        </div>
        <div class="section-box">
            <div class="section-title"><?=icon_svg('shield')?> امنیت</div>
            <form method="post" action="?action=update_settings">
                <div class="form-group"><label class="form-label">رمز فعلی</label><input class="form-input" type="password" name="cur_pass"></div>
                <div class="form-group"><label class="form-label">رمز جدید</label><input class="form-input" type="password" name="new_pass"></div>
                <button class="btn btn-success" type="submit">تغییر رمز عبور</button>
            </form>
        </div>
        <div class="section-box">
            <div class="section-title"><?=icon_svg('bell')?> اعلان‌ها</div>
            <div class="setting-item" style="display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--line)">
                <div><strong style="font-size:14px">اعلان سفارش</strong><p class="text-muted" style="font-size:12px">اطلاع‌رسانی وضعیت سفارش</p></div>
                <div class="toggle active" onclick="this.classList.toggle('active')"></div>
            </div>
            <div class="setting-item" style="display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--line)">
                <div><strong style="font-size:14px">پیشنهادات</strong><p class="text-muted" style="font-size:12px">دریافت تخفیف و پیشنهاد</p></div>
                <div class="toggle active" onclick="this.classList.toggle('active')"></div>
            </div>
            <div class="setting-item" style="display:flex;justify-content:space-between;padding:12px 0">
                <div><strong style="font-size:14px">پیام‌ها</strong><p class="text-muted" style="font-size:12px">اطلاع پیام جدید</p></div>
                <div class="toggle active" onclick="this.classList.toggle('active')"></div>
            </div>
        </div>
        <div class="section-box">
            <div class="section-title"><?=icon_svg('settings')?> ترجیحات</div>
            <div class="setting-item" style="display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--line)">
                <div><strong style="font-size:14px">زبان</strong><p class="text-muted" style="font-size:12px">زبان نمایشی</p></div>
                <select class="form-select" style="width:auto"><option>فارسی</option><option>English</option></select>
            </div>
            <div class="setting-item" style="display:flex;justify-content:space-between;padding:12px 0">
                <div><strong style="font-size:14px">تم</strong><p class="text-muted" style="font-size:12px">تاریک یا روشن</p></div>
                <a class="btn btn-outline btn-sm" href="?set_theme=<?=e($theme==='light'?'dark':'light')?>"><?= $theme==='light'?'🌙 تاریک':'☀️ روشن' ?></a>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if($page==='buyer-analytics' && is_buyer()):
    $myOrders = array_values(array_filter(get_orders(), fn($o)=>($o['buyer_id']??'')===$me['id']));
    $totalSpent = array_sum(array_map(fn($o)=>($o['amount']??0), $myOrders));
    $avgOrder = count($myOrders)?($totalSpent/count($myOrders)):0;
    $savings = $totalSpent * 0.18;
?>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px">
        <div>
            <h1>📊 تحلیل خرید و آمار</h1>
            <p class="text-muted">الگوهای هزینه خود را دنبال کنید</p>
        </div>
        <div style="display:flex;gap:8px"><select class="form-select" style="width:auto"><option>خرداد ۱۴۰۳</option></select></div>
    </div>

    <div class="analytics-stats">
        <div class="stat-box"><div class="sb-icon" style="background:var(--primary-light);color:var(--primary)"><?=icon_svg('credit-card')?></div><div class="sb-label">کل هزینه این ماه</div><div class="sb-value"><?=e(money($totalSpent))?></div><div class="sb-change" style="color:var(--danger)">↓ ۱٪ نسبت به قبل</div></div>
        <div class="stat-box"><div class="sb-icon" style="background:var(--info-light);color:var(--info)"><?=icon_svg('shopping-bag')?></div><div class="sb-label">میانگین سفارش</div><div class="sb-value"><?=e(money($avgOrder))?></div><div class="sb-change" style="color:var(--success)">↑ ۸٪ نسبت به قبل</div></div>
        <div class="stat-box"><div class="sb-icon" style="background:var(--success-light);color:var(--success)"><?=icon_svg('package')?></div><div class="sb-label">کل سفارش‌ها</div><div class="sb-value"><?=count($myOrders)?></div><div class="sb-change" style="color:var(--danger)">↓ ۵٪ نسبت به قبل</div></div>
        <div class="stat-box"><div class="sb-icon" style="background:var(--warning-light);color:var(--warning)"><?=icon_svg('zap')?></div><div class="sb-label">صرفه‌جویی</div><div class="sb-value"><?=e(money($savings))?></div><div class="sb-change" style="color:var(--success)">↑ ۱۸٪</div></div>
    </div>

    <div class="grid grid-2 mb-24">
        <div class="card">
            <div class="flex-between mb-16"><div class="card-title">روند هزینه ماهانه</div></div>
            <div class="chart-container">
                <svg viewBox="0 0 600 200" preserveAspectRatio="none">
                    <defs><linearGradient id="cg2" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--primary)" stop-opacity=".15"/><stop offset="100%" stop-color="var(--primary)" stop-opacity="0"/></linearGradient></defs>
                    <path d="M0,180 L75,150 L150,140 L225,120 L300,80 L375,100 L450,70 L525,90 L600,60 L600,200 L0,200Z" fill="url(#cg2)"/>
                    <polyline points="0,180 75,150 150,140 225,120 300,80 375,100 450,70 525,90 600,60" fill="none" stroke="var(--primary)" stroke-width="3" stroke-linecap="round"/>
                    <circle cx="600" cy="60" r="5" fill="var(--primary)"/>
                </svg>
            </div>
        </div>
        <div class="card">
            <div class="flex-between mb-16"><div class="card-title">هزینه بر اساس دسته</div></div>
            <div style="display:flex;align-items:center;justify-content:center;gap:24px">
                <svg viewBox="0 0 200 200" width="180" height="180">
                    <circle cx="100" cy="100" r="80" fill="none" stroke="var(--primary)" stroke-width="32" stroke-dasharray="200 302" stroke-dashoffset="0" transform="rotate(-90 100 100)"/>
                    <circle cx="100" cy="100" r="80" fill="none" stroke="#8b5cf6" stroke-width="32" stroke-dasharray="120 302" stroke-dashoffset="-200" transform="rotate(-90 100 100)"/>
                    <circle cx="100" cy="100" r="80" fill="none" stroke="#ec4899" stroke-width="32" stroke-dasharray="90 302" stroke-dashoffset="-320" transform="rotate(-90 100 100)"/>
                    <circle cx="100" cy="100" r="80" fill="none" stroke="#f59e0b" stroke-width="32" stroke-dasharray="60 302" stroke-dashoffset="-410" transform="rotate(-90 100 100)"/>
                    <text x="100" y="96" text-anchor="middle" font-size="18" font-weight="800" fill="var(--text)"><?=e(format_num($totalSpent))?></text>
                    <text x="100" y="116" text-anchor="middle" font-size="12" fill="var(--muted)">کل</text>
                </svg>
                <div style="display:grid;gap:8px;font-size:13px">
                    <div style="display:flex;align-items:center;gap:8px"><span style="width:12px;height:12px;border-radius:3px;background:var(--primary)"></span> الکترونیک ۴۰٪</div>
                    <div style="display:flex;align-items:center;gap:8px"><span style="width:12px;height:12px;border-radius:3px;background:#8b5cf6"></span> منزل ۲۴٪</div>
                    <div style="display:flex;align-items:center;gap:8px"><span style="width:12px;height:12px;border-radius:3px;background:#ec4899"></span> مد و پوشاک ۱۸٪</div>
                    <div style="display:flex;align-items:center;gap:8px"><span style="width:12px;height:12px;border-radius:3px;background:#f59e0b"></span> زیبایی ۱۰٪</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-24">
        <div class="flex-between mb-16"><div class="card-title">خرید ماهانه</div></div>
        <div style="display:flex;align-items:flex-end;justify-content:space-around;height:200px;padding:20px 0;border-bottom:1px solid var(--line)">
            <?php foreach([['label'=>'آذر','val'=>18],['label'=>'دی','val'=>24],['label'=>'بهمن','val'=>27],['label'=>'اسفند','val'=>35],['label'=>'فروردین','val'=>26],['label'=>'اردیبهشت','val'=>28]] as $m): ?>
            <div style="text-align:center">
                <div style="font-weight:700;margin-bottom:4px"><?= $m['val'] ?></div>
                <div style="width:40px;height:<?= $m['val']*5 ?>px;background:var(--primary);border-radius:8px 8px 0 0;opacity:<?=0.4+($m['val']/35)*0.6?>"></div>
                <div style="font-size:12px;color:var(--muted);margin-top:8px"><?=e($m['label'])?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php if($page==='seller-dashboard' && is_seller()):
    $seller_id = $me['id'];
    $myOrders = array_values(array_filter(get_orders(), fn($o)=>($o['seller_id']??'')===$seller_id));
    $myProducts = array_values(array_filter(get_products(), fn($p)=>($p['seller_id']??'')===$seller_id));
    $myOffers = array_values(array_filter(get_offers_db(), fn($o)=>($o['seller_id']??'')===$seller_id));
    $myRequests = array_values(array_filter($requests, fn($r)=>($r['buyer_id']??'')!==$seller_id));
    $totalRevenue = array_sum(array_map(fn($o)=>($o['status']??'')!=='cancelled'?($o['amount']??0):0, $myOrders));
    $pendingOrders = count(array_filter($myOrders, fn($o)=>in_array($o['status']??'',['pending','processing'])));
    $shopReviews = array_values(array_filter(get_reviews(), fn($r)=>($r['seller_id']??'')===$seller_id));
    $avgRating = $shopReviews ? avg_arr(array_map(fn($r)=>(float)($r['rating']??0), $shopReviews)) : ($me['rating']??0);
    $ratingDist = [5=>0,4=>0,3=>0,2=>0,1=>0];
    foreach($shopReviews as $rv) if(isset($ratingDist[$rv['rating']])) $ratingDist[$rv['rating']]++;
?>
    <div class="seller-layout">
        <div class="seller-sidebar">
            <div style="text-align:center;margin-bottom:16px">
                <?=avatar_html($me,'lg')?>
                <div style="font-weight:700;margin-top:8px"><?=e($me['name'])?></div>
                <div style="font-size:12px;color:var(--primary)">فروشنده برتر</div>
            </div>
            <div class="sidebar-menu">
                <a class="sb-item active" href="?page=seller-dashboard"><?=icon_svg('dashboard')?> داشبورد</a>
                <a class="sb-item" href="?page=seller-orders"><?=icon_svg('package')?> سفارش‌ها <span class="sb-badge"><?=count($myOrders)?></span></a>
                <a class="sb-item" href="?page=seller-products"><?=icon_svg('box')?> محصولات <span class="sb-badge"><?=count($myProducts)?></span></a>
                <a class="sb-item" href="?page=seller-offers"><?=icon_svg('trending-up')?> پیشنهادات</a>
                <a class="sb-item" href="?page=seller-wallet"><?=icon_svg('wallet')?> کیف پول</a>
                <a class="sb-item" href="?page=seller-shop"><?=icon_svg('store')?> فروشگاه</a>
                <a class="sb-item" href="?page=settings"><?=icon_svg('settings')?> تنظیمات</a>
            </div>
        </div>

        <div>
            <div class="welcome-banner">
                <div>
                    <h2>خوش آمدید، <?=e(mb_substr($me['name'],0,10))?>! 👋</h2>
                    <p>امروز چه اتفاقی برای فروشگاهتان افتاده</p>
                </div>
                <div style="display:flex;gap:12px">
                    <a class="btn btn-primary" href="?page=seller-offers"><?=icon_svg('zap')?> پیشنهاد سریع</a>
                </div>
            </div>

            <div class="grid grid-4 mb-24">
                <div class="card">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px"><div style="width:40px;height:40px;border-radius:10px;background:var(--primary-light);display:grid;place-items:center;color:var(--primary)"><?=icon_svg('shopping-bag')?></div><div><div style="font-size:13px;color:var(--muted)">درخواست امروز</div></div></div>
                    <div style="font-size:28px;font-weight:800"><?= count($myRequests) ?></div>
                    <div style="font-size:12px;color:var(--success)">+۱۲٪ از دیروز</div>
                </div>
                <div class="card">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px"><div style="width:40px;height:40px;border-radius:10px;background:var(--success-light);display:grid;place-items:center;color:var(--success)"><?=icon_svg('tag')?></div><div><div style="font-size:13px;color:var(--muted)">پیشنهاد فعال</div></div></div>
                    <div style="font-size:28px;font-weight:800"><?= count(array_filter($myOffers,fn($o)=>($o['status']??'')==='pending')) ?></div>
                    <div style="font-size:12px;color:var(--success)">+۸٪ از دیروز</div>
                </div>
                <div class="card">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px"><div style="width:40px;height:40px;border-radius:10px;background:#ede9fe;display:grid;place-items:center;color:#7c3aed"><?=icon_svg('package')?></div><div><div style="font-size:13px;color:var(--muted)">سفارش در حال انجام</div></div></div>
                    <div style="font-size:28px;font-weight:800"><?= $pendingOrders ?></div>
                    <div style="font-size:12px;color:var(--danger)">-۲٪ از دیروز</div>
                </div>
                <div class="card">
                    <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px"><div style="width:40px;height:40px;border-radius:10px;background:var(--success-light);display:grid;place-items:center;color:var(--success)"><?=icon_svg('credit-card')?></div><div><div style="font-size:13px;color:var(--muted)">درآمد این ماه</div></div></div>
                    <div style="font-size:24px;font-weight:800"><?=e(money($totalRevenue))?></div>
                    <div style="font-size:12px;color:var(--success)">+۱٪ از ماه قبل</div>
                </div>
            </div>

            <div class="grid" style="grid-template-columns:1.5fr 1fr;gap:20px">
                <div class="card">
                    <div class="flex-between mb-16"><div class="card-title">آخرین درخواست خریداران</div><a class="btn btn-ghost btn-sm">مشاهده همه</a></div>
                    <table class="requests-table">
                        <thead><tr><th>محصول</th><th>امتیاز خریدار</th><th>بودجه</th><th>شهر</th><th>عملیات</th></tr></thead>
                        <tbody>
                        <?php foreach(array_slice($myRequests,0,5) as $r):
                            $buyer = find_user($r['buyer_id']??'');
                        ?>
                        <tr>
                            <td><div style="display:flex;align-items:center;gap:8px"><span style="font-size:20px"><?=e(cat_icon($r['category']))?></span><strong><?=e($r['title'])?></strong></div><div style="font-size:12px;color:var(--muted)"><?=e(time_ago($r['created_at']))?></div></td>
                            <td>⭐ <?=number_format((float)($buyer['rating']??0),1)?></td>
                            <td><?=e(money($r['budget']))?></td>
                            <td><?=e($buyer['city']??'-')?></td>
                            <td><a class="btn btn-outline btn-sm" href="?page=seller-offers">ارسال پیشنهاد</a></td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div>
                    <div class="card mb-16">
                        <div class="card-title mb-16">درآمد (۳۰ روز اخیر)</div>
                        <div style="font-size:22px;font-weight:800;margin-bottom:4px"><?=e(money($totalRevenue))?></div>
                        <div style="font-size:13px;color:var(--success)">↑ ۱۵٪</div>
                        <div class="revenue-chart">
                            <svg viewBox="0 0 300 140" preserveAspectRatio="none">
                                <defs><linearGradient id="sg" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="var(--success)" stop-opacity=".15"/><stop offset="100%" stop-color="var(--success)" stop-opacity="0"/></linearGradient></defs>
                                <path d="M0,120 L37,100 L74,80 L111,90 L148,60 L185,70 L222,50 L259,40 L300,20 L300,140 L0,140Z" fill="url(#sg)"/>
                                <polyline points="0,120 37,100 74,80 111,90 148,60 185,70 222,50 259,40 300,20" fill="none" stroke="var(--success)" stroke-width="2.5" stroke-linecap="round"/>
                            </svg>
                        </div>
                    </div>
                    <div class="card">
                        <div class="flex-between mb-16"><div class="card-title">امتیاز فروشنده</div><a class="btn btn-ghost btn-sm">مشاهده همه</a></div>
                        <div style="font-size:32px;font-weight:900;margin-bottom:4px"><?=number_format((float)$avgRating,1)?></div>
                        <div style="margin-bottom:12px"><?=render_stars((float)$avgRating)?> <span class="text-muted" style="font-size:12px">بر اساس <?=count($shopReviews)?> نظر</span></div>
                        <div class="seller-rating">
                            <?php foreach([5,4,3,2,1] as $star):
                                $pct = count($shopReviews)?($ratingDist[$star]/count($shopReviews)*100):0;
                            ?>
                            <div class="rating-bar"><span><?= $star ?> ستاره</span><div class="rb-fill"><div style="width:<?=e($pct)?>%"></div></div><span class="rb-count"><?= $ratingDist[$star] ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if($page==='seller-orders' && is_seller()):
    $myOrders = array_values(array_filter(get_orders(), fn($o)=>($o['seller_id']??'')===$me['id']));
    $myOrders = sort_desc($myOrders);
?>
    <div class="seller-layout">
        <div class="seller-sidebar">
            <div style="text-align:center;margin-bottom:16px">
                <?=avatar_html($me,'lg')?>
                <div style="font-weight:700;margin-top:8px"><?=e($me['name'])?></div>
            </div>
            <div class="sidebar-menu">
                <a class="sb-item" href="?page=seller-dashboard"><?=icon_svg('dashboard')?> داشبورد</a>
                <a class="sb-item active" href="?page=seller-orders"><?=icon_svg('package')?> سفارش‌ها</a>
                <a class="sb-item" href="?page=seller-products"><?=icon_svg('box')?> محصولات</a>
                <a class="sb-item" href="?page=seller-offers"><?=icon_svg('trending-up')?> پیشنهادات</a>
                <a class="sb-item" href="?page=seller-wallet"><?=icon_svg('wallet')?> کیف پول</a>
                <a class="sb-item" href="?page=seller-shop"><?=icon_svg('store')?> فروشگاه</a>
                <a class="sb-item" href="?page=settings"><?=icon_svg('settings')?> تنظیمات</a>
            </div>
        </div>
        <div>
            <h2 style="margin-bottom:20px"><?=icon_svg('package')?> مدیریت سفارش‌ها</h2>
            <?php foreach($myOrders as $ord):
                $buyer = find_user($ord['buyer_id']??'');
                $prod = find_product($ord['product_id']??'');
                $st = $ord['status']??'';
            ?>
            <div class="card mb-16">
                <div class="flex-between mb-12">
                    <div style="display:flex;align-items:center;gap:12px">
                        <strong style="color:var(--primary)">#<?=e($ord['order_num']??mb_substr($ord['id'],0,8))?></strong>
                        <span class="badge badge-<?=($st==='delivered'?'success':($st==='shipped'?'info':($st==='processing'?'warning':'danger')))?>"><?=e(status_label($st))?></span>
                    </div>
                    <div class="text-muted" style="font-size:13px"><?=e(mb_substr($ord['created_at'],0,10))?></div>
                </div>
                <div class="grid grid-2" style="gap:16px">
                    <div>
                        <div style="font-size:13px;color:var(--muted);margin-bottom:4px">خریدار</div>
                        <strong><?=e($buyer['name']??'-')?></strong>
                        <div style="margin-top:8px"><div style="font-size:13px;color:var(--muted)">محصول</div><strong><?=e($prod?$prod['name']:'محصول')?></strong></div>
                    </div>
                    <div style="text-align:left">
                        <div style="font-size:22px;font-weight:800;color:var(--success)"><?=e(money($ord['amount']))?></div>
                        <div style="margin-top:8px">
                            <form method="post" action="?action=update_order_status" style="display:flex;gap:8px">
                                <input type="hidden" name="order_id" value="<?=e($ord['id'])?>">
                                <select name="status" class="form-select" style="width:auto" onchange="this.form.submit()">
                                    <option value="pending" <?= $st==='pending'?'selected':'' ?>>در انتظار</option>
                                    <option value="processing" <?= $st==='processing'?'selected':'' ?>>در حال پردازش</option>
                                    <option value="shipped" <?= $st==='shipped'?'selected':'' ?>>ارسال شده</option>
                                    <option value="delivered" <?= $st==='delivered'?'selected':'' ?>>تحویل شده</option>
                                </select>
                            </form>
                        </div>
                        <a class="btn btn-outline btn-sm mt-8" href="?page=chat&user=<?=e($buyer['id'])?>"><?=icon_svg('chat')?> گفتگو</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if(!$myOrders): ?><div class="empty-state"><div class="empty-icon">📦</div>سفارشی ثبت نشده</div><?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if($page==='seller-products' && is_seller()):
    $myProducts = array_values(array_filter(get_products(), fn($p)=>($p['seller_id']??'')===$me['id']));
?>
    <div class="seller-layout">
        <div class="seller-sidebar">
            <div style="text-align:center;margin-bottom:16px">
                <?=avatar_html($me,'lg')?>
                <div style="font-weight:700;margin-top:8px"><?=e($me['name'])?></div>
            </div>
            <div class="sidebar-menu">
                <a class="sb-item" href="?page=seller-dashboard"><?=icon_svg('dashboard')?> داشبورد</a>
                <a class="sb-item" href="?page=seller-orders"><?=icon_svg('package')?> سفارش‌ها</a>
                <a class="sb-item active" href="?page=seller-products"><?=icon_svg('box')?> محصولات</a>
                <a class="sb-item" href="?page=seller-offers"><?=icon_svg('trending-up')?> پیشنهادات</a>
                <a class="sb-item" href="?page=seller-wallet"><?=icon_svg('wallet')?> کیف پول</a>
                <a class="sb-item" href="?page=seller-shop"><?=icon_svg('store')?> فروشگاه</a>
                <a class="sb-item" href="?page=settings"><?=icon_svg('settings')?> تنظیمات</a>
            </div>
        </div>
        <div>
            <div class="flex-between mb-20">
                <h2><?=icon_svg('box')?> محصولات</h2>
                <button class="btn btn-primary" onclick="document.getElementById('prodModal').classList.add('show')"><?=icon_svg('plus')?> محصول جدید</button>
            </div>
            <div class="grid grid-3">
                <?php foreach($myProducts as $p): ?>
                <div class="card">
                    <div style="height:120px;border-radius:12px;background:var(--soft);display:grid;place-items:center;font-size:48px;margin-bottom:12px"><?=e(cat_icon($p['category']))?></div>
                    <h4 style="margin-bottom:4px"><?=e($p['name'])?></h4>
                    <div class="text-muted" style="font-size:13px;margin-bottom:8px"><?=e($p['category'])?></div>
                    <div class="flex-between">
                        <div style="font-weight:800;color:var(--success)"><?=e(money($p['price']))?></div>
                        <div class="text-muted" style="font-size:13px">موجودی: <?= (int)$p['stock'] ?></div>
                    </div>
                    <div style="display:flex;gap:8px;margin-top:12px">
                        <a class="btn btn-outline btn-sm" href="?action=delete_product&id=<?=e($p['id'])?>" onclick="return confirm('حذف؟')">حذف</a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php if(!$myProducts): ?><div class="empty-state"><div class="empty-icon">📦</div>محصولی ثبت نشده</div><?php endif; ?>
        </div>
    </div>

    <div id="prodModal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('show')">
        <div class="modal-box">
            <div class="modal-title">محصول جدید <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('show')">✕</button></div>
            <form method="post" action="?action=add_product">
                <div class="form-group"><label class="form-label">نام</label><input class="form-input" name="name" required></div>
                <div class="form-group"><label class="form-label">دسته</label><input class="form-input" name="category" required></div>
                <div class="grid grid-2"><div class="form-group"><label class="form-label">قیمت</label><input class="form-input" type="number" name="price" required></div><div class="form-group"><label class="form-label">موجودی</label><input class="form-input" type="number" name="stock" required></div></div>
                <div class="form-group"><label class="form-label">توضیحات</label><textarea class="form-textarea" name="description"></textarea></div>
                <button class="btn btn-primary w-full" type="submit">ذخیره</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if($page==='seller-offers' && is_seller()):
    $myOffers = enriched_offers(array_values(array_filter(get_offers_db(), fn($o)=>($o['seller_id']??'')===$me['id'])));
    $myOffers = sort_desc($myOffers);
    $myRequests = array_values(array_filter($requests, fn($r)=>($r['buyer_id']??'')!==$me['id'] && ($r['status']??'')==='open'));
?>
    <div class="seller-layout">
        <div class="seller-sidebar">
            <div style="text-align:center;margin-bottom:16px">
                <?=avatar_html($me,'lg')?>
                <div style="font-weight:700;margin-top:8px"><?=e($me['name'])?></div>
            </div>
            <div class="sidebar-menu">
                <a class="sb-item" href="?page=seller-dashboard"><?=icon_svg('dashboard')?> داشبورد</a>
                <a class="sb-item" href="?page=seller-orders"><?=icon_svg('package')?> سفارش‌ها</a>
                <a class="sb-item" href="?page=seller-products"><?=icon_svg('box')?> محصولات</a>
                <a class="sb-item active" href="?page=seller-offers"><?=icon_svg('trending-up')?> پیشنهادات</a>
                <a class="sb-item" href="?page=seller-wallet"><?=icon_svg('wallet')?> کیف پول</a>
                <a class="sb-item" href="?page=seller-shop"><?=icon_svg('store')?> فروشگاه</a>
                <a class="sb-item" href="?page=settings"><?=icon_svg('settings')?> تنظیمات</a>
            </div>
        </div>
        <div>
            <h2 style="margin-bottom:20px"><?=icon_svg('trending-up')?> پیشنهادات من</h2>
            <div class="card mb-16">
                <div class="card-title mb-12">پیشنهاد سریع</div>
                <form method="post" action="?action=create_offer" class="grid" style="grid-template-columns:1fr 1fr;gap:12px">
                    <div class="form-group" style="margin:0"><label class="form-label">درخواست</label>
                        <select class="form-select" name="request_id">
                            <option value="">انتخاب درخواست...</option>
                            <?php foreach($myRequests as $r): ?><option value="<?=e($r['id'])?>"><?=e($r['title'])?> - <?=e(money($r['budget']))?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0"><label class="form-label">قیمت پیشنهادی</label><input class="form-input" type="number" name="price"></div>
                    <div class="form-group" style="margin:0"><label class="form-label">گارانتی (ماه)</label><input class="form-input" type="number" name="warranty_months" value="12"></div>
                    <div class="form-group" style="margin:0"><label class="form-label">ارسال (روز)</label><input class="form-input" type="number" name="delivery_days" value="1"></div>
                    <div class="form-group" style="margin:0"><label class="form-label">زمان ارسال</label><input class="form-input" name="delivery_time" value=" تا ۲ روز کاری"></div>
                    <div class="form-group" style="margin:0"><label class="form-label">تخفیف (%)</label><input class="form-input" type="number" name="discount_percent" value="0"></div>
                    <div class="form-group" style="margin:0;grid-column:1/-1"><label class="form-label">توضیحات</label><textarea class="form-textarea" name="description"></textarea></div>
                    <button class="btn btn-success w-full" type="submit" style="grid-column:1/-1"><?=icon_svg('send')?> ارسال پیشنهاد</button>
                </form>
            </div>
            <?php foreach($myOffers as $o):
                $req = $o['request']??null;
            ?>
            <div class="card mb-12">
                <div class="flex-between">
                    <div>
                        <strong style="font-size:16px"><?=e($req?$req['title']:'درخواست')?></strong>
                        <div class="text-muted" style="font-size:13px;margin-top:4px"><?=e(mb_strimwidth($o['description']??'',0,80,'...'))?></div>
                        <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
                            <span class="badge badge-primary"><?=e(money($o['price']))?></span>
                            <span class="badge badge-info"><?= (int)$o['warranty_months'] ?> ماه گارانتی</span>
                            <span class="badge badge-info">ارسال: <?=e($o['delivery_time']??$o['delivery_days'].' روز')?></span>
                        </div>
                    </div>
                    <div style="text-align:left">
                        <span class="badge badge-<?=($o['status']??'')==='selected'?'success':(($o['status']??'')==='pending'?'warning':'info')?>"><?=e(status_label($o['status']??''))?></span>
                        <div class="text-muted" style="font-size:12px;margin-top:4px"><?=e($o['created_at'])?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if(!$myOffers): ?><div class="empty-state"><div class="empty-icon">💼</div>هنوز پیشنهادی ثبت نشده</div><?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if($page==='seller-wallet' && is_seller()):
    $myTx = array_values(array_filter(get_transactions(), fn($t)=>($t['user_id']??'')===$me['id']));
    $myTx = sort_desc($myTx);
?>
    <div class="seller-layout">
        <div class="seller-sidebar">
            <div style="text-align:center;margin-bottom:16px">
                <?=avatar_html($me,'lg')?>
                <div style="font-weight:700;margin-top:8px"><?=e($me['name'])?></div>
            </div>
            <div class="sidebar-menu">
                <a class="sb-item" href="?page=seller-dashboard"><?=icon_svg('dashboard')?> داشبورد</a>
                <a class="sb-item" href="?page=seller-orders"><?=icon_svg('package')?> سفارش‌ها</a>
                <a class="sb-item" href="?page=seller-products"><?=icon_svg('box')?> محصولات</a>
                <a class="sb-item" href="?page=seller-offers"><?=icon_svg('trending-up')?> پیشنهادات</a>
                <a class="sb-item active" href="?page=seller-wallet"><?=icon_svg('wallet')?> کیف پول</a>
                <a class="sb-item" href="?page=seller-shop"><?=icon_svg('store')?> فروشگاه</a>
                <a class="sb-item" href="?page=settings"><?=icon_svg('settings')?> تنظیمات</a>
            </div>
        </div>
        <div>
            <div class="wallet-balance-card">
                <div class="wb-label">موجودی کیف پول فروشنده</div>
                <div class="wb-amount"><?=e(money($me['wallet_balance']??0))?></div>
                <div style="display:flex;gap:10px;margin-top:16px;position:relative;z-index:1">
                    <button class="btn" style="background:#fff;color:var(--primary)" onclick="document.getElementById('withdrawModal').classList.add('show')"><?=icon_svg('download')?> برداشت وجه</button>
                </div>
            </div>
            <h3 style="margin-bottom:12px">تراکنش‌های اخیر</h3>
            <?php foreach($myTx as $tx): ?>
            <div class="tx-full">
                <div class="tx-full-icon" style="background:<?=($tx['type']??'')==='credit'?'var(--success-light)':'var(--danger-light)';?>;color:<?=($tx['type']??'')==='credit'?'var(--success)':'var(--danger)'?>">
                    <?=icon_svg($tx['type']==='credit'?'plus':'credit-card')?>
                </div>
                <div class="tx-full-info">
                    <div class="tx-full-desc"><?=e($tx['description'])?></div>
                    <div class="tx-full-ref"><?=e($tx['reference']??'')?></div>
                </div>
                <div class="tx-full-amount" style="color:<?=($tx['type']??'')==='credit'?'var(--success)':'var(--danger)'?>">
                    <?=($tx['type']==='credit'?'+':'-') ?><?=e(format_num($tx['amount']))?> <small>تومان</small>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="withdrawModal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('show')">
        <div class="modal-box">
            <div class="modal-title">برداشت وجه <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('show')">✕</button></div>
            <form method="post" action="?action=wallet_withdraw">
                <div class="form-group"><label class="form-label">مبلغ (تومان)</label><input class="form-input" type="number" name="amount" required max="<?= (int)($me['wallet_balance']??0) ?>"></div>
                <div class="text-muted" style="font-size:12px;margin-bottom:16px">حداکثر: <?=e(money($me['wallet_balance']??0))?></div>
                <button class="btn btn-primary w-full" type="submit">ثبت برداشت</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php if($page==='seller-shop' && is_seller()):
    $shopReviews = array_values(array_filter(get_reviews(), fn($r)=>($r['seller_id']??'')===$me['id']));
    $avgRating = $shopReviews ? avg_arr(array_map(fn($r)=>(float)($r['rating']??0), $shopReviews)) : ($me['rating']??0);
    $myProducts = array_values(array_filter(get_products(), fn($p)=>($p['seller_id']??'')===$me['id']));
    $myOrders = array_values(array_filter(get_orders(), fn($o)=>($o['seller_id']??'')===$me['id']));
    $totalSales = count($myOrders);
?>
    <div class="seller-layout">
        <div class="seller-sidebar">
            <div style="text-align:center;margin-bottom:16px">
                <?=avatar_html($me,'lg')?>
                <div style="font-weight:700;margin-top:8px"><?=e($me['name'])?></div>
            </div>
            <div class="sidebar-menu">
                <a class="sb-item" href="?page=seller-dashboard"><?=icon_svg('dashboard')?> داشبورد</a>
                <a class="sb-item" href="?page=seller-orders"><?=icon_svg('package')?> سفارش‌ها</a>
                <a class="sb-item" href="?page=seller-products"><?=icon_svg('box')?> محصولات</a>
                <a class="sb-item" href="?page=seller-offers"><?=icon_svg('trending-up')?> پیشنهادات</a>
                <a class="sb-item" href="?page=seller-wallet"><?=icon_svg('wallet')?> کیف پول</a>
                <a class="sb-item active" href="?page=seller-shop"><?=icon_svg('store')?> فروشگاه</a>
                <a class="sb-item" href="?page=settings"><?=icon_svg('settings')?> تنظیمات</a>
            </div>
        </div>
        <div>
            <div class="profile-header" style="margin-bottom:24px">
                <div class="profile-top">
                    <div class="avatar-xl" style="width:80px;height:80px;font-size:32px;border:3px solid #fff"><?=avatar_html($me,'xl')?></div>
                    <div class="profile-info">
                        <h2><?=e($me['name'])?></h2>
                        <p style="opacity:.9">فروشنده حرفه‌ای <?=e($me['city']??'')?></p>
                        <div style="margin-top:8px"><?=render_stars((float)$avgRating)?> <span style="font-size:14px;opacity:.9">(<?=count($shopReviews)?> نظر)</span></div>
                    </div>
                </div>
                <div class="profile-stats">
                    <div class="ps-box"><div class="ps-val"><?= number_format((float)$avgRating,1) ?>/۵</div><div class="ps-lbl">سطح بسیار عالی</div></div>
                    <div class="ps-box"><div class="ps-val"><?= (int)$me['history_count'] ?></div><div class="ps-lbl">سفارش تکمیل شده</div></div>
                    <div class="ps-box"><div class="ps-val"><?= (int)$me['success_rate'] ?>%</div><div class="ps-lbl">عملکرد ارسال</div></div>
                    <div class="ps-box"><div class="ps-val"><?= (int)$me['rating'] ?></div><div class="ps-lbl">امتیاز مشتریان</div></div>
                </div>
            </div>

            <div class="grid grid-2">
                <div class="card">
                    <div class="card-title mb-16">نظرات مشتریان</div>
                    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px">
                        <?php foreach(array_slice($shopReviews,0,6) as $rv):
                            $b = find_user($rv['buyer_id']??'');
                        ?>
                        <div class="card" style="padding:14px">
                            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px">
                                <?=avatar_html($b,'sm')?>
                                <div><strong style="font-size:13px"><?=e($b['name']??'')</strong></div>
                            </div>
                            <div style="margin-bottom:6px"><?=render_stars((float)$rv['rating'])?></div>
                            <div class="text-muted" style="font-size:12px"><?=e(mb_strimwidth($rv['comment']??'',0,60,'...'))?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="card">
                    <div class="card-title mb-16">اطلاعات فروشگاه</div>
                    <div class="info-row"><span class="info-lbl">نام</span><span class="info-val"><?=e($me['name'])?></span></div>
                    <div class="info-row"><span class="info-lbl">تلفن</span><span class="info-val"><?=e($me['phone']??'-')?></span></div>
                    <div class="info-row"><span class="info-lbl">شهر</span><span class="info-val"><?=e($me['city']??'-')?></span></div>
                    <div class="info-row"><span class="info-lbl">محصولات</span><span class="info-val"><?=count($myProducts)?> محصول</span></div>
                    <div class="info-row"><span class="info-lbl">فروش کل</span><span class="info-val"><?=count($myOrders)?> سفارش</span></div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if($page==='sellers' && is_buyer()):
    $rid = $_GET['id'] ?? '';
    $req = find_request($rid);
    if(!$req) { echo '<div class="card"><div class="empty-state">درخواست یافت نشد</div></div>'; }
    else {
        $offers = enriched_offers(array_values(array_filter(get_offers_db(), fn($o)=>($o['request_id']??'')===$rid)));
        $sort = $_GET['sort'] ?? 'best';
        if($sort==='price') usort($offers, fn($a,$b)=>((float)($a['price']??0))<=>((float)($b['price']??0)));
        elseif($sort==='delivery') usort($offers, fn($a,$b)=>((int)($a['delivery_days']??99))<=>((int)($b['delivery_days']??99)));
        elseif($sort==='rating') usort($offers, fn($a,$b)=>((float)($b['seller_rating']??0))<=>((float)($a['seller_rating']??0)));
        $bestIdx = $sort==='best'?0:-1;
?>
    <div class="card mb-16" style="background:var(--panel);display:flex;align-items:center;gap:16px;flex-wrap:wrap">
        <div style="width:70px;height:70px;border-radius:14px;background:var(--primary-light);display:grid;place-items:center;font-size:36px"><?=e(cat_icon($req['category']))?></div>
        <div style="flex:1"><h2 style="margin:0"><?=e($req['title'])?></h2><p class="text-muted"><?=e($req['description'])?></p><span class="badge badge-primary">بودجه: <?=e(money($req['budget']))?></span></div>
    </div>

    <div class="filter-chips">
        <span class="chip <?= $sort==='best'?'active':'' ?>" onclick="location.href='?page=sellers&id=<?=e($rid)?>&sort=best'">🏆 بهترین امتیاز</span>
        <span class="chip <?= $sort==='price'?'active':'' ?>" onclick="location.href='?page=sellers&id=<?=e($rid)?>&sort=price'">💰 ارزان‌ترین</span>
        <span class="chip <?= $sort==='delivery'?'active':'' ?>" onclick="location.href='?page=sellers&id=<?=e($rid)?>&sort=delivery'">🚚 سریع‌ترین</span>
    </div>

    <?php foreach($offers as $idx=>$o):
        $s = $o['seller']??[];
        $isBest = $idx===$bestIdx;
    ?>
    <div class="card mb-12" style="<?= $isBest?'border:2px solid var(--success);background:var(--success-light);':'' ?>">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
            <div style="display:flex;align-items:center;gap:14px">
                <?=avatar_html($s,'md')?>
                <div>
                    <div style="display:flex;align-items:center;gap:8px">
                        <strong style="font-size:16px"><?=e($s['name']??'-')?></strong>
                        <?php if($isBest): ?><span class="badge badge-success">پیشنهاد عالی</span><?php endif; ?>
                    </div>
                    <div class="stars" style="font-size:14px"><?=render_stars((float)($s['rating']??0))?> <span class="text-muted"><?=number_format((float)($s['rating']??0),1)?></span></div>
                </div>
            </div>
            <div style="font-size:22px;font-weight:900;color:var(--success)"><?=e(money($o['price']??0))?></div>
        </div>
        <p class="text-muted" style="margin:10px 0"><?=e($o['description'])?></p>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px">
            <span class="badge badge-info">🛡️ <?= (int)$o['warranty_months'] ?> ماه</span>
            <span class="badge badge-info">🚚 <?=e($o['delivery_time']??$o['delivery_days'].' روز')?></span>
            <span class="badge badge-info">✅ <?= (int)($s['success_rate']??0) ?>%</span>
            <span class="badge badge-info">📦 <?= (int)($s['history_count']??0) ?> معامله</span>
            <?php if(!empty($o['discount_percent'])): ?><span class="badge badge-success">٪<?=e($o['discount_percent'])?> تخفیف</span><?php endif; ?>
        </div>
        <div style="display:flex;gap:10px">
            <a class="btn btn-outline btn-sm" href="?page=chat&user=<?=e($s['id'])?>&request_id=<?=e($rid)?>"><?=icon_svg('chat')?> گفتگو</a>
            <?php if(is_buyer() && ($req['buyer_id']??'')===$me['id']): ?>
                <a class="btn btn-success btn-sm" href="?action=select_offer&id=<?=e($o['id'])?>"><?=icon_svg('check')?> انتخاب فروشنده</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
    <?php if(!$offers): ?><div class="empty-state"><div class="empty-icon">💼</div>هنوز پیشنهادی ثبت نشده</div><?php endif; ?>
<?php } endif; ?>

<?php if($page==='chat'): require_login();
    $otherId = $_GET['user'] ?? ''; $requestId = $_GET['request_id'] ?? '';
    $otherUser = find_user($otherId);
    $request = $requestId ? find_request($requestId) : null;
    $allChats = sort_desc(get_chats_db());
    $conversation = [];
    foreach(array_reverse($allChats) as $c){
        $mine=($c['from_id']??'')===$me['id']&&($c['to_id']??'')===$otherId;
        $theirs=($c['to_id']??'')===$me['id']&&($c['from_id']??'')===$otherId;
        $rok=$requestId===''||($c['request_id']??'')===$requestId;
        if(($mine||$theirs)&&$rok)$conversation[]=$c;
    }
    $partners = [];
    foreach($allChats as $c){
        if(($c['from_id']??'')===$me['id']) $partners[$c['to_id']]=find_user($c['to_id']??'');
        if(($c['to_id']??'')===$me['id']) $partners[$c['from_id']]=find_user($c['from_id']??'');
    }
?>
    <div style="display:grid;grid-template-columns:1fr 300px;gap:20px;height:calc(100vh - 140px)">
        <div class="card" style="display:flex;flex-direction:column;overflow:hidden">
            <div class="flex-between mb-16" style="padding-bottom:12px;border-bottom:1px solid var(--line)">
                <div style="display:flex;align-items:center;gap:12px">
                    <?=avatar_html($otherUser,'md')?>
                    <div><strong><?=e($otherUser['name']??'کاربر')?></strong><?php if($request):?><div class="text-muted" style="font-size:12px"><?=e($request['title'])?></div><?php endif;?></div>
                </div>
            </div>
            <div style="flex:1;overflow-y:auto;display:grid;gap:12px;padding:8px 0" id="chatBox">
                <?php foreach($conversation as $m): ?>
                    <div style="max-width:75%;padding:12px 16px;border-radius:16px;<?= ($m['from_id']??'')===$me['id']?'margin-right:auto;background:var(--primary);color:#fff':'background:var(--soft)' ?>">
                        <?=nl2br(e($m['message']))?>
                        <div style="font-size:11px;opacity:.7;margin-top:4px"><?=e($m['created_at'])?></div>
                    </div>
                <?php endforeach; ?>
                <?php if(!$conversation): ?><div class="text-center text-muted" style="padding:40px">هنوز پیامی رد و بدل نشده</div><?php endif; ?>
            </div>
            <form method="post" action="?action=send_message" style="display:flex;gap:10px;margin-top:12px;padding-top:12px;border-top:1px solid var(--line)">
                <input type="hidden" name="to_id" value="<?=e($otherId)?>">
                <input type="hidden" name="request_id" value="<?=e($requestId)?>">
                <input type="text" name="message" class="form-input" placeholder="پیام خود را بنویسید..." required style="flex:1">
                <button class="btn btn-primary" type="submit"><?=icon_svg('send')?></button>
            </form>
        </div>
        <div class="card" style="overflow-y:auto">
            <div class="card-title mb-16">کاربران</div>
            <?php foreach($partners as $pid=>$pu): if(!$pu)continue; ?>
                <a href="?page=chat&user=<?=e($pid)?>" style="display:flex;align-items:center;gap:12px;padding:10px;border-radius:12px;<?= ($pid===$otherId)?'background:var(--primary-light)':'' ?>">
                    <?=avatar_html($pu,'sm')?>
                    <div><strong style="font-size:14px"><?=e($pu['name'])?></strong><div class="text-muted" style="font-size:12px"><?=e(role_label($pu['role']))?></div></div>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

</div>

<!-- BOTTOM NAV (Mobile) -->
<div class="bottom-nav">
    <a class="bn-item <?= $page==='home'?'active':'' ?>" href="?"><span class="icon"><?=icon_svg('home')?></span>خانه</a>
    <?php if(is_buyer()): ?>
        <a class="bn-item <?= $page==='dashboard'?'active':'' ?>" href="?page=dashboard"><span class="icon"><?=icon_svg('dashboard')?></span>داشبورد</a>
        <a class="bn-item <?= $page==='orders'?'active':'' ?>" href="?page=orders"><span class="icon"><?=icon_svg('shopping-bag')?></span>سفارش‌ها</a>
        <a class="bn-item <?= $page==='wallet'?'active':'' ?>" href="?page=wallet"><span class="icon"><?=icon_svg('wallet')?></span>کیف پول</a>
        <a class="bn-item <?= $page==='profile'?'active':'' ?>" href="?page=profile"><span class="icon"><?=icon_svg('user')?></span>پروفایل</a>
    <?php elseif(is_seller()): ?>
        <a class="bn-item <?= $page==='seller-dashboard'?'active':'' ?>" href="?page=seller-dashboard"><span class="icon"><?=icon_svg('dashboard')?></span>داشبورد</a>
        <a class="bn-item <?= $page==='seller-orders'?'active':'' ?>" href="?page=seller-orders"><span class="icon"><?=icon_svg('package')?></span>سفارش‌ها</a>
        <a class="bn-item <?= $page==='seller-wallet'?'active':'' ?>" href="?page=seller-wallet"><span class="icon"><?=icon_svg('wallet')?></span>مالی</a>
        <a class="bn-item <?= $page==='seller-shop'?'active':'' ?>" href="?page=seller-shop"><span class="icon"><?=icon_svg('user')?></span>پروفایل</a>
    <?php endif; ?>
</div>
<div class="bottom-spacer"></div>

<!-- REQUEST MODAL -->
<?php if(is_buyer() && in_array($page,['dashboard','orders'])): ?>
<div id="requestModal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('show')">
    <div class="modal-box">
        <div class="modal-title">درخواست جدید <button class="modal-close" onclick="this.closest('.modal-overlay').classList.remove('show')">✕</button></div>
        <form method="post" action="?action=create_request">
            <div class="form-group"><label class="form-label">عنوان</label><input class="form-input" name="title" required></div>
            <div class="form-group"><label class="form-label">دسته</label><input class="form-input" name="category" required></div>
            <div class="form-group"><label class="form-label">بودجه</label><input class="form-input" type="number" name="budget" required></div>
            <div class="form-group"><label class="form-label">توضیحات</label><textarea class="form-textarea" name="description"></textarea></div>
            <button class="btn btn-primary w-full" type="submit">ثبت درخواست</button>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
setTimeout(()=>{document.querySelectorAll('.flash').forEach(f=>{f.style.opacity='0';setTimeout(()=>f.remove(),300)})},3000);
document.querySelectorAll('.toggle').forEach(t=>t.addEventListener('click',function(){this.classList.toggle('active')}));
const cb=document.getElementById('chatBox');if(cb)cb.scrollTop=cb.scrollHeight;
</script>
</body>
</html>