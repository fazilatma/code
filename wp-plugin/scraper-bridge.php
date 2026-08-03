<?php
/**
 * Plugin Name: Scraper Bridge
 * Description: پل ارتباطی بین اسکرپر و ووکامرس — ساخت و به‌روزرسانی محصول همراه با تصویر، بدون نیاز به دسترسی wp/v2/media
 * Version: 1.0.0
 * Author: Scraper
 * License: GPL-2.0-or-later
 *
 * ─────────────────────────────────────────────────────────────────────
 *  چرا این افزونه لازم شد
 *
 *  کلید ووکامرس (ck_/cs_) فقط روی مسیرهای wc/v3 معتبر است. هستهٔ وردپرس
 *  آن را کاربر نمی‌شناسد، پس آپلود تصویر به wp/v2/media با ۴۰۱ رد می‌شود.
 *  راه دوم — دادن آدرس تصویر به ووکامرس با images[].src — هم وقتی سایت
 *  مبدأ هات‌لینک را بسته باشد شکست می‌خورد، چون این‌بار سرور ووکامرس
 *  باید تصویر را بردارد و اجازه ندارد.
 *
 *  این افزونه مسیر سومی می‌سازد: خودِ اسکرپر بایت‌های تصویر را می‌گیرد و
 *  مستقیم به وردپرس می‌فرستد. افزونه آن را با توابع داخلی وردپرس در
 *  کتابخانهٔ رسانه می‌نشاند و به محصول وصل می‌کند. هیچ درخواستی از سمت
 *  سرور وردپرس به بیرون زده نمی‌شود، پس تحریم و هات‌لینک بی‌اثر می‌شوند.
 *
 *  نکتهٔ کلیدی امنیتی/فنی: فضای نام با «wc-» شروع می‌شود. کلاس
 *  WC_REST_Authentication در ووکامرس هر مسیری را که با wc- شروع شود با
 *  همان consumer key/secret احراز هویت می‌کند (متد is_request_to_rest_api).
 *  یعنی بدون هیچ کلید تازه‌ای، همان کلیدی که الان دارید کار می‌کند.
 * ─────────────────────────────────────────────────────────────────────
 */

if (!defined('ABSPATH')) exit;

define('SCRAPER_BRIDGE_VERSION', '1.0.0');
define('SCRAPER_BRIDGE_NS', 'wc-scraper/v1');   // «wc-» عمدی است — احراز هویت ووکامرس

add_action('rest_api_init', function () {

    // دسترسی: همان چیزی که ووکامرس برای نوشتن محصول می‌خواهد
    $perm = function () {
        if (!current_user_can('edit_products') && !current_user_can('manage_woocommerce')) {
            return new WP_Error('scraper_forbidden', 'دسترسی ندارید',
                ['status' => rest_authorization_required_code()]);
        }
        return true;
    };

    register_rest_route(SCRAPER_BRIDGE_NS, '/ping', [
        'methods'             => 'GET',
        'callback'            => 'scraper_bridge_ping',
        'permission_callback' => $perm,
    ]);

    register_rest_route(SCRAPER_BRIDGE_NS, '/product', [
        'methods'             => 'POST',
        'callback'            => 'scraper_bridge_product',
        'permission_callback' => $perm,
    ]);

    register_rest_route(SCRAPER_BRIDGE_NS, '/attach-image', [
        'methods'             => 'POST',
        'callback'            => 'scraper_bridge_attach_image',
        'permission_callback' => $perm,
    ]);
});

/** آیا افزونه نصب و در دسترس است؟ */
function scraper_bridge_ping() {
    return rest_ensure_response([
        'ok'          => true,
        'plugin'      => 'scraper-bridge',
        'version'     => SCRAPER_BRIDGE_VERSION,
        'wc'          => defined('WC_VERSION') ? WC_VERSION : null,
        'wp'          => get_bloginfo('version'),
        'user'        => wp_get_current_user()->user_login,
        'can_upload'  => current_user_can('upload_files'),
        'max_upload'  => wp_max_upload_size(),
    ]);
}

/**
 * تصویر را در کتابخانهٔ رسانه می‌نشاند.
 * $src یکی از این‌هاست:
 *   - image_b64 : بایت‌های تصویر با base64 (مطمئن‌ترین راه؛ سرور وردپرس
 *                 لازم نیست به اینترنت وصل شود)
 *   - image_url : آدرس، که وردپرس خودش می‌گیرد (نیاز به دسترسی بیرونی)
 * خروجی: شناسهٔ پیوست یا WP_Error
 */
function scraper_bridge_store_image(array $p, int $parentId = 0) {
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    require_once ABSPATH . 'wp-admin/includes/image.php';

    $b64 = (string)($p['image_b64'] ?? '');
    $url = (string)($p['image_url'] ?? '');
    $name = sanitize_file_name((string)($p['image_name'] ?? ''));

    if ($b64 !== '') {
        $bytes = base64_decode($b64, true);
        if ($bytes === false || strlen($bytes) < 100) {
            return new WP_Error('bad_image', 'دادهٔ تصویر نامعتبر است');
        }
        // پسوند را از خود بایت‌ها تشخیص بده، نه از نام فایل
        $ext = scraper_bridge_sniff_ext($bytes);
        if ($ext === '') return new WP_Error('bad_image', 'فرمت تصویر شناخته نشد');
        if ($name === '' || strpos($name, '.') === false) {
            $name = 'product-' . substr(md5($bytes), 0, 10) . '.' . $ext;
        }
        $tmp = wp_tempnam($name);
        if (!$tmp) return new WP_Error('tmp_fail', 'فایل موقت ساخته نشد');
        file_put_contents($tmp, $bytes);
        $file = ['name' => $name, 'tmp_name' => $tmp];
        $id = media_handle_sideload($file, $parentId);
        if (is_wp_error($id)) { @unlink($tmp); return $id; }
        return (int)$id;
    }

    if ($url !== '') {
        $id = media_sideload_image($url, $parentId, null, 'id');
        if (is_wp_error($id)) return $id;
        return (int)$id;
    }

    return new WP_Error('no_image', 'تصویری فرستاده نشد');
}

/** تشخیص فرمت از روی امضای فایل */
function scraper_bridge_sniff_ext(string $d): string {
    if (strncmp($d, "\xFF\xD8", 2) === 0) return 'jpg';
    if (strncmp($d, "\x89PNG", 4) === 0) return 'png';
    if (strncmp($d, 'GIF', 3) === 0) return 'gif';
    if (strncmp($d, 'RIFF', 4) === 0 && substr($d, 8, 4) === 'WEBP') return 'webp';
    if (strncmp($d, 'BM', 2) === 0) return 'bmp';
    if (substr($d, 4, 4) === 'ftyp' && in_array(substr($d, 8, 4), ['avif', 'avis'], true)) return 'avif';
    return '';
}

/**
 * ساخت یا به‌روزرسانی محصول، همراه با تصویر — همه در یک درخواست.
 *
 * ورودی‌های مهم:
 *   name, regular_price, description, short_description, sku, status,
 *   stock_quantity, manage_stock, categories[], product_id (برای آپدیت)
 *   match_by_title (اگر product_id ندهید، با عنوان می‌گردد)
 *   image_b64 / image_url, image_name
 *   skip_if_has_image (پیش‌فرض true — تصویر موجود را خراب نکن)
 */
function scraper_bridge_product(WP_REST_Request $req) {
    if (!class_exists('WC_Product_Simple')) {
        return new WP_Error('no_wc', 'ووکامرس فعال نیست', ['status' => 500]);
    }
    $p = $req->get_json_params();
    if (!is_array($p)) $p = $req->get_params();

    $name = trim((string)($p['name'] ?? ''));
    $pid  = (int)($p['product_id'] ?? 0);

    // پیدا کردن محصول موجود
    if ($pid <= 0 && !empty($p['match_by_title']) && $name !== '') {
        $found = get_page_by_title($name, OBJECT, 'product');
        if ($found) $pid = (int)$found->ID;
        if ($pid <= 0 && !empty($p['match_without_suffix'])) {
            $base = trim(str_replace((string)$p['match_without_suffix'], '', $name));
            if ($base !== '' && $base !== $name) {
                $f2 = get_page_by_title($base, OBJECT, 'product');
                if ($f2) $pid = (int)$f2->ID;
            }
        }
    }

    $creating = $pid <= 0;
    $product = $creating ? new WC_Product_Simple() : wc_get_product($pid);
    if (!$product) return new WP_Error('not_found', 'محصول یافت نشد', ['status' => 404]);

    if ($name !== '') $product->set_name($name);
    if (isset($p['regular_price']) && $p['regular_price'] !== '') {
        $product->set_regular_price((string)$p['regular_price']);
    }
    if (isset($p['sale_price']) && $p['sale_price'] !== '')      $product->set_sale_price((string)$p['sale_price']);
    if (isset($p['description']))       $product->set_description((string)$p['description']);
    if (isset($p['short_description']))  $product->set_short_description((string)$p['short_description']);
    if (!empty($p['sku'])) {
        // SKU تکراری کل درخواست را می‌اندازد؛ پس محتاط عمل می‌کنیم
        $existing = wc_get_product_id_by_sku((string)$p['sku']);
        if (!$existing || $existing === $product->get_id()) {
            try { $product->set_sku((string)$p['sku']); } catch (Exception $e) { /* بی‌خیال SKU */ }
        }
    }
    if (!empty($p['status']))  $product->set_status((string)$p['status']);
    if (isset($p['manage_stock'])) $product->set_manage_stock((bool)$p['manage_stock']);
    if (isset($p['stock_quantity']) && $p['stock_quantity'] !== '') {
        $q = (int)$p['stock_quantity'];
        $product->set_stock_quantity($q);
        $product->set_stock_status($q > 0 ? 'instock' : 'outofstock');
    }
    if (!empty($p['categories']) && is_array($p['categories'])) {
        $ids = [];
        foreach ($p['categories'] as $c) {
            $cid = is_array($c) ? (int)($c['id'] ?? 0) : (int)$c;
            if ($cid > 0) $ids[] = $cid;
        }
        if ($ids) $product->set_category_ids($ids);
    }

    $productId = $product->save();
    if (!$productId) return new WP_Error('save_fail', 'ذخیرهٔ محصول ناموفق بود', ['status' => 500]);

    // ---- تصویر ----
    $imageResult = 'ارسال نشد';
    $attachId = 0;
    $hasImage = (bool)$product->get_image_id();
    $skipIfHas = !array_key_exists('skip_if_has_image', $p) || !empty($p['skip_if_has_image']);
    $wantImage = !empty($p['image_b64']) || !empty($p['image_url']);

    if ($wantImage && $hasImage && $skipIfHas) {
        $imageResult = 'محصول از قبل تصویر داشت — دست نخورد';
    } elseif ($wantImage) {
        $att = scraper_bridge_store_image($p, $productId);
        if (is_wp_error($att)) {
            $imageResult = 'ناموفق: ' . $att->get_error_message();
        } else {
            $attachId = (int)$att;
            $product->set_image_id($attachId);
            $product->save();
            $imageResult = 'تصویر ثبت شد';
        }
    }

    return rest_ensure_response([
        'ok'          => true,
        'product_id'  => $productId,
        'created'     => $creating,
        'updated'     => !$creating,
        'image_id'    => $attachId,
        'image'       => $imageResult,
        'edit_url'    => admin_url('post.php?post=' . $productId . '&action=edit'),
        'permalink'   => get_permalink($productId),
    ]);
}

/** فقط تصویر را به محصولی که از قبل هست وصل می‌کند */
function scraper_bridge_attach_image(WP_REST_Request $req) {
    $p = $req->get_json_params();
    if (!is_array($p)) $p = $req->get_params();
    $pid = (int)($p['product_id'] ?? 0);
    if ($pid <= 0) return new WP_Error('bad_id', 'شناسهٔ محصول لازم است', ['status' => 400]);
    $product = wc_get_product($pid);
    if (!$product) return new WP_Error('not_found', 'محصول یافت نشد', ['status' => 404]);

    $skipIfHas = !array_key_exists('skip_if_has_image', $p) || !empty($p['skip_if_has_image']);
    if ($product->get_image_id() && $skipIfHas) {
        return rest_ensure_response(['ok' => true, 'product_id' => $pid,
            'image_id' => (int)$product->get_image_id(), 'image' => 'از قبل تصویر داشت']);
    }
    $att = scraper_bridge_store_image($p, $pid);
    if (is_wp_error($att)) {
        return new WP_Error('image_fail', $att->get_error_message(), ['status' => 422]);
    }
    $product->set_image_id((int)$att);
    $product->save();
    return rest_ensure_response(['ok' => true, 'product_id' => $pid,
        'image_id' => (int)$att, 'image' => 'تصویر ثبت شد']);
}
