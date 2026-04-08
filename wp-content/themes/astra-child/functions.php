<?php
/**
 * Astra Child Theme functions and definitions
 *
 * @link https://developer.wordpress.org/themes/basics/theme-functions/
 *
 * @package Astra Child
 * @since 1.0.0
 */

/**
 * Define Constants
 */
define( 'CHILD_THEME_ASTRA_CHILD_VERSION', '1.0.0' );

/**
 * Enqueue styles
 */
function child_enqueue_styles() {

	wp_enqueue_style( 'astra-child-theme-css', get_stylesheet_directory_uri() . '/style.css', array('astra-theme-css'), CHILD_THEME_ASTRA_CHILD_VERSION, 'all' );

}

add_action( 'wp_enqueue_scripts', 'child_enqueue_styles', 15 );

// 引入郵政劃撥上傳功能 (獨立檔案)
require_once get_stylesheet_directory() . '/inc/postal-upload.php';

/**
 *
 */
add_action( 'woocommerce_before_calculate_totals', 'keep_only_last_added_item', 10, 1 );

function keep_only_last_added_item( $cart ) {
    if ( is_admin() || ! is_cart() && ! is_checkout() ) return;

    $items = $cart->get_cart();

    // 如果購物車不止一個品項，則移除較早的品項
    if ( count( $items ) > 1 ) {
        // 取得購物車中的所有鍵值
        $keys = array_keys( $items );

        // 保留最新添加的商品，即最後一個品項
        $last_key = end( $keys );

        // 清空購物車，然後只保留最後一個商品
        foreach ( $items as $key => $item ) {
            if ( $key !== $last_key ) {
                $cart->remove_cart_item( $key );
            }
        }
    }
}

/*
 * 
 */
remove_action('wp_head', 'wp_generator');

/**/
/* 讓 WordPress 的媒體庫，出現圖片的檔案大小 */
// 1. 儲存圖片檔案大小到 meta（上傳時與編輯時）
add_action('add_attachment', 'update_attachment_filesize_meta');
add_action('edit_attachment', 'update_attachment_filesize_meta');
function update_attachment_filesize_meta($post_ID) {
    if (wp_attachment_is_image($post_ID)) {
        $file = get_attached_file($post_ID);
        if (file_exists($file)) {
            update_post_meta($post_ID, '_wp_attachment_filesize', filesize($file));
        }
    }
}

// 2. 在媒體庫新增「檔案大小」欄位，並顯示格式化大小
add_filter('manage_upload_columns', 'custom_add_column_file_size');
add_action('manage_media_custom_column', 'custom_column_file_size', 10, 2);
function custom_add_column_file_size($columns) {
    $columns['filesize'] = '檔案大小';
    return $columns;
}
function custom_column_file_size($column_name, $post_id) {
    if ($column_name === 'filesize') {
        $filesize = get_post_meta($post_id, '_wp_attachment_filesize', true);
        echo $filesize ? size_format($filesize, 2) : '—';
    }
}

// 3. 讓「檔案大小」欄位可以點擊排序
add_filter('manage_upload_sortable_columns', 'custom_sortable_file_size_column');
function custom_sortable_file_size_column($columns) {
    $columns['filesize'] = 'filesize';
    return $columns;
}
add_action('pre_get_posts', 'custom_sort_query_by_file_size');
function custom_sort_query_by_file_size($query) {
    if (is_admin() && $query->is_main_query() && $query->get('orderby') === 'filesize') {
        $query->set('meta_key', '_wp_attachment_filesize');
        $query->set('orderby', 'meta_value_num');
    }
}

/*阿火*/
//===================================================================================================對帳系統
// 對帳單上傳 → WooCommerce 子選單
add_action('admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        '對帳單上傳',
        '對帳單上傳',
        'manage_woocommerce',
        'upload-reconciliation',
        'render_reconciliation_upload_form'
    );
}, 40); // 優先度 40：排在「郵政劃撥上傳」後面


function render_reconciliation_upload_form( $post ) {
?>
<form id="upload-form" enctype="multipart/form-data">
    <p><strong>收件者 Email：</strong><br>
        <input type="email" name="recipient_email" required placeholder="請輸入要接收報表的信箱">
    </p>

    <p><strong>起始日期：</strong><br>
        <input type="date" name="start_date" required>
    </p>

    <p><strong>結束日期：</strong><br>
        <input type="date" name="end_date" required>
    </p>

    <p><strong>NewebPay：</strong><br><input type="file" name="newebpay"></p>
    <p><strong>LinePAY：</strong><br><input type="file" name="LinePAY"></p>
    <p><strong>iPassMoney：</strong><br><input type="file" name="iPassMoney"></p>

    <button type="submit" class="button button-primary">上傳</button>
</form>

<div id="upload-result"></div>

<script>
document.getElementById('upload-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const raw  = new FormData(e.target);      // 從 <form> 抓全部欄位
  const form = new FormData();

  // 文字欄位
  form.append('recipient_email', raw.get('recipient_email'));
  form.append('start_date',      raw.get('start_date'));
  form.append('end_date',        raw.get('end_date'));

  // 檔案欄位 - 只附帶有選檔案者
  ['newebpay', 'LinePAY', 'iPassMoney'].forEach((name) => {
    const f = raw.get(name);
    if (f && f.size) form.append(name, f, f.name);
  });

  const result = document.getElementById('upload-result');
  result.innerHTML = '處理中...需等待 10~20 秒';

  try {
    const res  = await fetch(
      'https://ezec.zeabur.app/webhook/uploadReconciliation',
      { method: 'POST', body: form }
    );

    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    const data = await res.json();          // ← n8n 回 {"status":"ok"}

    if (data.status === 'ok') {
      result.innerHTML =
        `<span style="color:green;">✅ 已寄送成功：${raw.get('recipient_email')}</span>`;
    } else {
      result.innerHTML = '<span style="color:red;">❌ 後端處理失敗</span>';
    }
  } catch (err) {
    console.error(err);
    result.innerHTML =
      `<span style="color:red;">上傳出錯：${err.message}</span>`;
  }
});

</script>
<?php
}

//=====================================================================================================訂單流水號
// 在 WooCommerce 訂單後台顯示「流水號」與「是否已開收據」
add_action( 'woocommerce_admin_order_data_after_order_totals', function ( $order ) {
	$serial  = $order->get_meta( '_custom_serial_number' );
	$receipt = $order->get_meta( 'receipt_generated' );
	?>
	<div class="order_data_column">
		<h4>收據資訊（顯示）</h4>
		<p class="form-field form-field-wide">
			<label>流水號</label>
			<input type="text" id="custom_serial_number_totals"
			       value="<?php echo esc_attr( $serial ); ?>" readonly
			       style="opacity:.85;background:#f6f7f7;cursor:not-allowed;">
		</p>
		<p class="form-field" style="display:flex;align-items:center;gap:6px;">
			<input type="checkbox" class="checkbox" id="receipt_generated_totals"
			       <?php checked( $receipt, 'yes' ); ?> disabled>
			<label style="margin:0;">是否已產生收據</label>
		</p>
	</div>
	<?php
} );
/**
 * 在訂單後台「一般」分欄：改成可編輯的流水號欄位（含 nonce）
 */
remove_action( 'woocommerce_admin_order_data_after_order_details', '__return_false' ); // 若你原本有其他掛件，先確保移除

add_action( 'woocommerce_admin_order_data_after_order_details', function ( $order ) {
	$serial  = $order->get_meta( '_custom_serial_number' ) ?: '';
	$receipt = $order->get_meta( 'receipt_generated' ) === 'yes' ? '✅ 已產生' : '尚未產生';

	// 這裡印出我們自家的 nonce，讓 ttcc_save_serial_admin() 能驗證來源
	wp_nonce_field( 'ttcc_save_serial', 'ttcc_save_serial_nonce' );
	?>
	<div class="form-field" style="margin-top:12px;">
		<p class="form-field form-field-wide">
			<label for="custom_serial_number">流水號</label>
			<input type="text"
			       class="short"
			       style="width:240px"
			       name="custom_serial_number"
			       id="custom_serial_number"
			       value="<?php echo esc_attr( $serial ); ?>"
			       placeholder="TTCC114-001 或 114001">
			<span class="description">
				可填 <code>TTCC114-001</code> 或 <code>114001</code>；清空後儲存可刪除此流水號。
			</span>
		</p>

		<p class="form-field">
			<strong>收據狀態：</strong> <?php echo esc_html( $receipt ); ?>
		</p>
	</div>
	<?php
} );

add_action( 'woocommerce_admin_order_data_after_order_details', function ( $order ) {
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
                return;
        }

        $order_id    = $order->get_id();
        $preview_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=ttcc_receipt_preview&order_id=' . $order_id ),
                'ttcc_receipt_preview_' . $order_id
        );
        $pdf_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=ttcc_receipt_pdf&order_id=' . $order_id ),
                'ttcc_receipt_pdf_' . $order_id
        );
        $send_url = wp_nonce_url(
                admin_url( 'admin-post.php?action=ttcc_send_receipt_pdf&order_id=' . $order_id ),
                'ttcc_send_receipt_pdf_' . $order_id
        );
        $receipt_mail_log = $order->get_meta( 'ttcc_receipt_mail_sent_log', true );
        if ( ! is_array( $receipt_mail_log ) ) {
                $receipt_mail_log = array();
        }

        $receipt_mail_count = count( $receipt_mail_log );
        $latest_receipt_log = ! empty( $receipt_mail_log ) ? end( $receipt_mail_log ) : array();

        $receipt_mail_log = array_reverse( $receipt_mail_log );
        $receipt_mail_log = array_slice( $receipt_mail_log, 0, 5 );
        ?>
        <div class="form-field" style="margin-top:12px;">
                <h4 style="margin:0 0 8px;">TTCC 收據</h4>
                <p style="display:flex;gap:8px;flex-wrap:nowrap;align-items:center;margin:0;">
                        <a class="button" href="<?php echo esc_url( $preview_url ); ?>" target="_blank" rel="noopener">預覽收據</a>
                        <a class="button button-primary" href="<?php echo esc_url( $pdf_url ); ?>">下載收據</a>
                        <a class="button" href="<?php echo esc_url( $send_url ); ?>">重寄收據</a>
                </p>

                <div style="margin-top:10px;font-size:12px;line-height:1.6;">
                        <strong>收據寄送摘要</strong>

                        <?php
                        $latest_sent_at = ! empty( $latest_receipt_log['sent_at'] ) ? (string) $latest_receipt_log['sent_at'] : '—';
                        $latest_success = isset( $latest_receipt_log['success'] ) ? (int) $latest_receipt_log['success'] : null;
                        $latest_status  = is_null( $latest_success ) ? '—' : ( $latest_success ? '成功' : '失敗' );
                        $latest_source  = ! empty( $latest_receipt_log['source'] ) ? (string) $latest_receipt_log['source'] : '';

                        if ( $latest_source === 'single_manual' ) {
                                $latest_source = '單筆手動';
                        } elseif ( $latest_source === 'bulk_manual' ) {
                                $latest_source = '批次手動';
                        }

                        if ( $latest_status !== '—' && $latest_source !== '' ) {
                                $latest_status .= '｜' . $latest_source;
                        }
                        ?>

                        <div style="margin-top:6px;">最近寄送時間：<?php echo esc_html( $latest_sent_at ); ?></div>
                        <div>累計寄送次數：<?php echo esc_html( (string) $receipt_mail_count ); ?></div>
                        <div>最近一次結果：<?php echo esc_html( $latest_status ); ?></div>

                        <details style="margin-top:8px;">
                                <summary style="cursor:pointer;">查看寄送紀錄（最近 5 筆）</summary>

                                <?php if ( empty( $receipt_mail_log ) ) : ?>
                                        <div style="margin-top:6px;color:#666;">尚無寄送紀錄</div>
                                <?php else : ?>
                                        <div style="margin-top:6px;">
                                                <?php foreach ( $receipt_mail_log as $log_row ) : ?>
                                                        <?php
                                                        $log_sent_at = ! empty( $log_row['sent_at'] ) ? (string) $log_row['sent_at'] : '';
                                                        $log_success = ! empty( $log_row['success'] ) ? 1 : 0;
                                                        $log_mail_to = '';
                                                        if ( ! empty( $log_row['mail_to'] ) && is_array( $log_row['mail_to'] ) ) {
                                                                $log_mail_to = implode( ', ', array_map( 'strval', $log_row['mail_to'] ) );
                                                        }
                                                        $log_source = ! empty( $log_row['source'] ) ? (string) $log_row['source'] : '';
                                                        if ( $log_source === 'single_manual' ) {
                                                                $log_source = '單筆手動';
                                                        } elseif ( $log_source === 'bulk_manual' ) {
                                                                $log_source = '批次手動';
                                                        }
                                                        $log_error  = ! empty( $log_row['error_message'] ) ? (string) $log_row['error_message'] : '';
                                                        ?>
                                                        <div style="margin:0 0 8px;padding:6px 8px;border:1px solid #e0e0e0;background:#fafafa;max-width:520px;">
                                                            <div style="white-space:nowrap;">
                                                                    <?php echo esc_html( $log_sent_at ); ?>
                                                            </div>

                                                            <div style="margin-top:2px;white-space:nowrap;">
                                                                    <?php echo $log_success ? '成功' : '失敗'; ?>
                                                                    <?php if ( $log_source !== '' ) : ?>
                                                                            ｜<?php echo esc_html( $log_source ); ?>
                                                                    <?php endif; ?>
                                                            </div>

                                                            <?php if ( $log_mail_to !== '' ) : ?>
                                                                    <div style="margin-top:2px;">
                                                                            收件人：<?php echo esc_html( $log_mail_to ); ?>
                                                                    </div>
                                                            <?php endif; ?>

                                                            <?php if ( $log_error !== '' ) : ?>
                                                                    <div style="margin-top:2px;color:#b32d2e;">
                                                                            錯誤：<?php echo esc_html( $log_error ); ?>
                                                                    </div>
                                                            <?php endif; ?>
                                                        </div>
                                                <?php endforeach; ?>
                                        </div>
                                <?php endif; ?>
                        </details>
                </div>
        </div>
        <?php
}, 20 );

// --- 流水號儲存（兼容 HPOS + 唯一性 + 同步 plain 版；避免遞迴） ---
add_action('woocommerce_process_shop_order_meta', 'ttcc_save_serial_admin', 10, 2);


function ttcc_save_serial_admin( $post_id, $post ) {
    if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
    if ( ! current_user_can('edit_shop_order', $post_id) ) return;

    // 只接受從後台編輯頁送出的請求
    if ( empty($_POST['ttcc_save_serial_nonce']) || ! wp_verify_nonce($_POST['ttcc_save_serial_nonce'], 'ttcc_save_serial') ) {
        return;
    }

    $order = wc_get_order( $post_id );
    if ( ! $order ) return;

    // 收據勾選（寫進 WC_Order 物件，讓核心 save 一起處理）
    if ( array_key_exists('receipt_generated', $_POST) ) {
        $receipt = ($_POST['receipt_generated'] === 'yes') ? 'yes' : 'no';
        $order->update_meta_data( 'receipt_generated', $receipt );
    }

    // 流水號
    if ( array_key_exists('custom_serial_number', $_POST) ) {
        $new_serial = trim( sanitize_text_field( wp_unslash($_POST['custom_serial_number']) ) );
        $old_serial = (string) $order->get_meta('_custom_serial_number');

        if ( $new_serial !== $old_serial ) {
            // 唯一性檢查（非空才檢查）
            if ( $new_serial !== '' ) {
                $dup = wc_get_orders([
                    'limit'        => 1,
                    'return'       => 'ids',
                    'exclude'      => [ $post_id ],
                    'meta_key'     => '_custom_serial_number',
                    'meta_value'   => $new_serial,
                    'meta_compare' => '=',
                ]);
                if ( ! empty($dup) ) {
                    add_action('admin_notices', function() use ($new_serial) {
                        echo '<div class="notice notice-error"><p>流水號 <code>' . esc_html($new_serial) . '</code> 已被其他訂單使用，未變更。</p></div>';
                    });
                    return; // 不覆蓋
                }
            }

            // 寫入/清除主字串
            if ( $new_serial === '' ) {
                $order->delete_meta_data( '_custom_serial_number' );
            } else {
                $order->update_meta_data( '_custom_serial_number', $new_serial );
            }

            // 同步 plain（114031 = 年*1000 + 流水）
            $plain = ttcc_parse_serial_to_plain_1000( $new_serial );
            if ( $new_serial === '' || $plain === null ) {
                $order->delete_meta_data( '_custom_serial_number_plain' );
            } else {
                $order->update_meta_data( '_custom_serial_number_plain', $plain );
            }
        }
    }

    // ⚠️ 不要呼叫 $order->save()，避免在 save_post_* 中造成遞迴
      $order->save_meta_data();    
}


/**
 * 將序號字串轉成「1000 倍制」純數字：114031（= 年三碼*1000 + 流水三碼）
 * 支援：
 *  - "TTCC114-031"
 *  - "114-031"
 *  - "114031"
 * 解析失敗回傳 null
 */
function ttcc_parse_serial_to_plain_1000( $s ) {
    $s = trim((string)$s);
    if ( $s === '' ) return null;

    // 1) 前綴(可選)+ 年3碼 + '-' + 流水3碼
    if ( preg_match('/^([A-Za-z]{2,10})?(\d{3})-(\d{3})$/', $s, $m) ) {
        $y = (int)$m[2];
        $q = (int)$m[3];
        return $y * 1000 + $q;
    }

    // 2) 6 碼純數字：114031
    $digits = preg_replace('/\D+/', '', $s);
    if ( strlen($digits) === 6 ) {
        $y = (int)substr($digits, 0, 3);
        $q = (int)substr($digits, 3, 3);
        return $y * 1000 + $q;
    }

    return null;
}




// 建立 WooCommerce 後台子選單
add_action('admin_menu', function () {
    add_submenu_page('woocommerce','收據設定','收據設定','manage_woocommerce','receipt-settings','render_receipt_settings_page');
}, 10);

// 顯示設定頁面
function render_receipt_settings_page() {
    $year = intval(date('Y')) - 1911; // 民國年
    $year_prefix = strval($year);
    $option_key = 'custom_serial_next_number_' . $year_prefix;

    if (isset($_POST['next_serial_number'])) {
        $next_serial = intval($_POST['next_serial_number']);
        update_option($option_key, $next_serial);
        echo '<div class="updated"><p>已更新為 ' . esc_html($next_serial) . '</p></div>';
    }

    $current = intval(get_option($option_key, 1));
    ?>
    <div class="wrap">
        <h1>收據設定</h1>
        <form method="post">
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="next_serial_number">目前年度 (民國 <?php echo $year; ?>) 的下一張流水號</label>
                    </th>
                    <td>
                        <input name="next_serial_number" type="number" id="next_serial_number" value="<?php echo esc_attr($current); ?>" class="regular-text">
                        <p class="description">將產生：<?php echo 'TTCC' . $year_prefix . '-' . str_pad($current, 3, '0', STR_PAD_LEFT); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button('儲存設定'); ?>
        </form>
    </div>
    <?php
}

// 當訂單建立時，如果沒有流水號，自動產生一組
/**
 * 取得某年度下一個序號（原子遞增），回傳整數 n（1,2,3…）
 * 會在 wp_options 建立/更新 key: custom_serial_next_number_{民國年}
 */
function ttcc_next_serial_int( $year_prefix ) {
    global $wpdb;
    $table = $wpdb->options;
    $key   = 'custom_serial_next_number_' . $year_prefix;

    // 第一次：插入並把 LAST_INSERT_ID 設為 1
    // 之後：option_value + 1，並把該值設為 LAST_INSERT_ID
    $sql = $wpdb->prepare(
        "INSERT INTO {$table} (option_name, option_value, autoload)
         VALUES (%s, LAST_INSERT_ID(1), 'no')
         ON DUPLICATE KEY UPDATE option_value = LAST_INSERT_ID(CAST(option_value AS UNSIGNED) + 1)",
        $key
    );
    $wpdb->query( $sql );

    $n = (int) $wpdb->get_var( "SELECT LAST_INSERT_ID()" );
    return max(1, $n);
}

/** 組字串：例如 TTCC114-001 */
function ttcc_format_serial( $year_prefix, $n ) {
    return 'TTCC' . $year_prefix . '-' . str_pad( (string) $n, 3, '0', STR_PAD_LEFT );
}

// 當訂單狀態改變時，如果沒有流水號，自動產生一組
add_action( 'woocommerce_order_status_changed', function ( $order_id, $from, $to, $order ) {
    
    // 修正 1: 放寬觸發時機
    // 允許「已完成」或「處理中」狀態都觸發 (通常金流回傳是 Processing)
    $allowed_statuses = ['completed', 'processing'];
    if ( ! in_array( $to, $allowed_statuses ) ) {
        return;
    }

    // 修正 2: 檢查是否已有流水號 (使用物件方法取得，相容 HPOS)
    if ( $order->get_meta( '_custom_serial_number' ) ) {
        return;
    }

    // ❶ 預設 receipt_generated
    if ( ! $order->meta_exists( 'receipt_generated' ) ) {
        $order->update_meta_data( 'receipt_generated', 'no' );
    }

    // ❸ 原子遞增取號
    $year        = intval( date( 'Y' ) ) - 1911;
    $year_prefix = strval( $year );

    $max_attempts = 5;
    $assigned     = '';

    for ( $i = 0; $i < $max_attempts; $i++ ) {
        $n      = ttcc_next_serial_int( $year_prefix );
        $serial = ttcc_format_serial( $year_prefix, $n );

        // 檢查重複 (使用 wc_get_orders 確保查到的是最新資料)
        $dup = wc_get_orders( [
            'limit'      => 1,
            'return'     => 'ids',
            'meta_key'   => '_custom_serial_number',
            'meta_value' => $serial,
            'meta_compare' => '=',
        ] );

        if ( empty( $dup ) ) { $assigned = $serial; break; }
    }

    if ( $assigned ) {
        // 修正 3: 使用 WC_Order 物件方法寫入並 Save，解決 HPOS 寫入無效問題
        $order->update_meta_data( '_custom_serial_number', $assigned );

        $plain = intval( $year_prefix ) * 1000 + intval( substr( $assigned, -3 ) );
        $order->update_meta_data( '_custom_serial_number_plain', $plain );
        $order->update_meta_data( 'receipt_generated', 'no' );
        
        // 重要：儲存變更。這會寫入正確的 tables (不管是 postmeta 還是 wc_orders_meta)
        $order->save(); 
        
    } else {
        error_log('[TTCC] Failed to assign unique serial after retries for order #' . $order_id);
    }
}, 10, 4 );



/*--------------------------------------------------------------
 *  WooCommerce - 收據編號快速搜尋
 *-------------------------------------------------------------*/
add_action('admin_menu', function () {
    add_submenu_page('woocommerce','收據編號搜尋','收據編號搜尋','manage_woocommerce','search-serial','ttcc_render_serial_search_page');
}, 10);

function ttcc_render_serial_search_page() {
    $serial  = isset( $_GET['serial'] ) ? sanitize_text_field( $_GET['serial'] ) : '';
    $matches = [];

    if ( $serial !== '' ) {
        $matches = wc_get_orders( [
            'limit'        => -1,
            'meta_key'     => '_custom_serial_number', // ← 你的 meta key
            'meta_value'   => $serial,
            'meta_compare' => '=',
            'orderby'      => 'date',
            'order'        => 'DESC',
        ] );
        /* ➜ 如需「模糊比對」把 meta_compare 改 'LIKE' 即可 */
    }
    ?>
    <div class="wrap">
        <h1>收據編號搜尋</h1>

        <!-- 搜尋表單 -->
        <form method="get" style="margin-bottom: 20px;">
            <input type="hidden" name="page" value="search-serial">
            <input type="search" name="serial" class="regular-text"
                   placeholder="輸入收據編號…"
                   value="<?php echo esc_attr( $serial ); ?>">
            <?php submit_button( '搜尋', 'primary', '', false ); ?>
        </form>

        <?php
        /* 沒輸入就不顯示結果區 */
        if ( $serial === '' ) {
            echo '<p>請輸入完整收據編號，然後按「搜尋」。</p>';
            return;
        }

        /* 沒找到 */
        if ( empty( $matches ) ) {
            echo '<div class="notice notice-error"><p>找不到收據編號：' . esc_html( $serial ) . '</p></div>';
            return;
        }

        /* 找到 1 筆 → 直接跳轉 */
        if ( count( $matches ) === 1 ) {
            wp_safe_redirect(
                admin_url( 'post.php?post=' . $matches[0]->get_id() . '&action=edit' )
            );
            exit;
        }

        /* 找到多筆 → 列表讓使用者選 */
        echo '<p>找到多筆符合結果：</p><ul>';
        foreach ( $matches as $o ) {
            printf(
                '<li><a href="%s">訂單 #%d — %s</a></li>',
                esc_url( admin_url( 'post.php?post=' . $o->get_id() . '&action=edit' ) ),
                $o->get_id(),
                $o->get_date_created()->date_i18n( 'Y-m-d H:i' )
            );
        }
        echo '</ul>';
        ?>
    </div>
    <?php
}


/*--------------------------------------------------------------
 * WooCommerce → 再次寄送收據（排在「收據編號搜尋」下方）
 *-------------------------------------------------------------*/
add_action('admin_menu', function () {
    add_submenu_page(
        'woocommerce',
        '再次寄送收據',
        '再次寄送收據',
        'manage_woocommerce',
        'resend-receipts',
        'ttcc_render_resend_receipts_page'
    );
}, 10.2); // 10 是「收據編號搜尋」，這裡用 12 讓它排在後面

function ttcc_render_resend_receipts_page() {
    // ✅ 使用正式 webhook（不要用 /webhook-test/...）
    $webhook = 'https://hook.ezec.com.tw/webhook-test/b501ccdd-1420-45c2-a42c-1f675a6466fb';
    ?>
    <div class="wrap">
        <h1>再次寄送收據</h1>
        <p class="description">
            提示：當「是否再次寄送給捐款者」勾選為 <strong>是</strong> 時，系統會優先寄給各筆訂單的捐款者 Email；
            若該筆訂單沒有捐款者 Email（或為系統預設信箱），則改寄送給下方的「收件者 Email」。<br>
            當勾選為 <strong>否</strong> 時，統一寄送到「收件者 Email」。
        </p>

        <form id="resend-receipts-form">
            <?php wp_nonce_field('ttcc_resend_receipts_nonce', 'ttcc_resend_receipts_nonce'); ?>

            <table class="form-table" role="presentation">
                <tbody>
                <tr>
                    <th scope="row"><label for="recipient_email">收件者 Email</label></th>
                    <td>
                        <input type="email" id="recipient_email" name="recipient_email" class="regular-text"
                               placeholder="可留空（當寄給捐款者失敗或選擇『否』時，寄送到此）">
                        <p class="description">可填群組信箱。</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">是否再次寄送給捐款者</th>
                    <td>
                        <label style="display:inline-flex;align-items:center;gap:.5rem;">
                            <input type="checkbox" name="resend_to_donor" value="yes" checked>
                            <span>是（預設）</span>
                        </label>
                        <p class="description">取消勾選＝否（統一寄送到「收件者 Email」）。</p>
                    </td>
                </tr>

				<tr class="merge-row" style="display:none;">
				  <th scope="row"><label for="merge_pdfs">附件處理方式</label></th>
				  <td>
				    <label style="display:block;margin-bottom:4px;">
				      <input type="radio" name="merge_pdfs" value="no" checked>
				      每張收據一份檔案（多附件）
				    </label>
				    <label style="display:block;">
				      <input type="radio" name="merge_pdfs" value="yes">
				      整合為一份 PDF
				    </label>
				    <p class="description">僅在「否（統一寄送到收件者）」時有效。</p>
				  </td>
				</tr>

                <tr>
                    <th scope="row"><label for="serial_start">開始收據編號</label></th>
                    <td>
                       <input type="text" id="serial_start" name="serial_start" class="regular-text" required
                       placeholder="例如：TTCC114-031 或 114031">
                       <p class="description">格式：<code>TTCC114-031</code>（前綴＋民國年三碼＋連字號＋三碼流水），或純數字 <code>114031</code> 亦可。</p> 
                   </td>
                </tr>

                <tr>
                    <th scope="row"><label for="serial_end">結束收據編號</label></th>
                    <td>
                         <input type="text" id="serial_end" name="serial_end" class="regular-text" required
                         placeholder="例如：TTCC114-120 或 114120">
                         <p class="description">必須 ≥ 開始編號（同上格式）。</p>
                    </td>
                </tr>
                </tbody>
            </table>

            <p>
                <button type="submit" class="button button-primary">送出</button>
            </p>
        </form>

        <div id="resend-result" style="margin-top: 10px;"></div>
    </div>

    <script>
    (function(){
        const form   = document.getElementById('resend-receipts-form');
        const result = document.getElementById('resend-result');
        const webhook = <?php echo json_encode( $webhook ); ?>;
		const chk = form.querySelector('input[name="resend_to_donor"]');
		const mergeRow = form.querySelector('.merge-row');
		
		chk.addEventListener('change', () => {
		  if (chk.checked) {
		    mergeRow.style.display = 'none';
		  } else {
		    mergeRow.style.display = '';
		  }
		});

        // 解析序號：支援 "TTCC114-031" 或 純數字 "114031"
        function parseSerialInput(input) {
            const s = String(input || '').trim();
            if (!s) return { ok:false };

            // 1) TTCC114-031 類型：前綴(2~10英文字母)+民國年(3碼)+ '-' + 流水(>=3碼建議3碼)
            const m = s.match(/^([A-Za-z]{2,10})(\d{3})-(\d{3})$/);
            if (m) {
                const prefix = m[1].toUpperCase();
                const year   = m[2];                 // 例如 114
                const seqRaw = m[3];                 // 例如 031
                const seqNum = parseInt(seqRaw, 10); // 31
                // 規範化數值：用「年*1000 + 流水」作為範圍比較依據（你的新格式是三碼流水）
                const norm = parseInt(year,10) * 1000 + seqNum;
                const pretty = `${prefix}${year}-${seqNum.toString().padStart(3,'0')}`;
                return { ok:true, type:'prefixed', prefix, year, seq:seqNum, norm, pretty, raw:s };
            }

            // 2) 純數字：114031（民國年三碼 + 流水三碼）
            const n = s.match(/^\d{6}$/); // 嚴謹採用 3+3（114 + 031）
            if (n) {
                const year = s.slice(0,3);           // 114
                const seq  = parseInt(s.slice(3),10);// 31
                const norm = parseInt(year,10) * 1000 + seq;
                // 沒有前綴時，pretty 先不帶前綴；後端如需 TTCC 可自行加上
                const pretty = `${year}-${seq.toString().padStart(3,'0')}`;
                return { ok:true, type:'numeric', prefix:'', year, seq, norm, pretty, raw:s };
            }

            return { ok:false };
        }

        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            result.innerHTML = '';

            const raw  = new FormData(form);
            const recipientEmail = (raw.get('recipient_email') || '').trim();
            const resendToDonor  = raw.get('resend_to_donor') === 'yes';
            const serialStart    = (raw.get('serial_start') || '').trim();
            const serialEnd      = (raw.get('serial_end') || '').trim();
			const mergePdfs = raw.get('merge_pdfs') === 'yes';

            // 解析與前端檢查（支援 TTCC114-031 或 114031）
            const pStart = parseSerialInput(serialStart);
            const pEnd   = parseSerialInput(serialEnd);
            if (!pStart.ok || !pEnd.ok) {
                result.innerHTML = '<p style="color:red;">請輸入正確的「收據編號」格式：<code>TTCC114-031</code> 或 <code>114031</code>。</p>';
                return;
            }
            if (pStart.norm > pEnd.norm) {
                result.innerHTML = '<p style="color:red;">「結束收據編號」必須大於或等於「開始編號」。</p>';
                return;
            }
            // 若選擇「否」→ 必填收件者 Email
            if (!resendToDonor && !recipientEmail) {
                result.innerHTML = '<p style="color:red;">已選擇「否」，請填寫「收件者 Email」。</p>';
                return;
            }

            const nonce = raw.get('ttcc_resend_receipts_nonce') || '';

		// 傳回多種格式，避免打壞既有 n8n：後端可優先用 *_pretty 或 *_norm
            const payload = {
                recipient_email   : recipientEmail,
                resend_to_donor   : resendToDonor, // true=寄給捐款者；false=寄給上方收件者
                merge_pdfs        : mergePdfs,
                // 舊鍵保留，但改成比較通用的 pretty（如需原輸入與數值範圍也一併提供）
                serial_start      : pStart.pretty,  // e.g. "TTCC114-031" 或 "114-031"
                serial_end        : pEnd.pretty,
                // 兼容資訊（n8n 可選用）
                serial_start_raw  : pStart.raw,
                serial_end_raw    : pEnd.raw,
                serial_start_norm : pStart.norm,    // e.g. 114*1000 + 31
                serial_end_norm   : pEnd.norm,
                serial_prefix     : (pStart.prefix || pEnd.prefix || ''), // e.g. "TTCC"
                serial_year_start : pStart.year,
                serial_year_end   : pEnd.year,
                wp_nonce          : nonce
            };

            result.innerHTML = '<p style="color:#2271b1;">處理中… 可能需要 10～20 秒，請稍候。</p>';

            try {
                const res = await fetch(webhook, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(payload)
                });

                let data = {};
                try { data = await res.json(); } catch(e){}

                if (!res.ok) {
                    throw new Error('HTTP ' + res.status);
                }

                const ok = (data && (data.success || data.status === 'ok')) ? true : false;

                if (ok) {
                    const range = (payload.serial_start || '') + ' ～ ' + (payload.serial_end || '');
                    const target = resendToDonor ? '捐款者 Email（遇系統信箱則改寄收件者）' : (recipientEmail || '（未提供）');
                    result.innerHTML =
                        '<p style="color:green;">✅ 已送出再次寄送請求！<br>寄送對象：<strong>' + target + '</strong><br>範圍：<strong>' + range + '</strong></p>';
                } else {
                    result.innerHTML =
                        '<p style="color:red;">❌ 後端回傳失敗，請稍後再試或檢查參數。' +
                        (data && data.message ? ('<br>訊息：' + ('' + data.message)) : '') +
                        '</p>';
                }
            } catch (err) {
                console.error(err);
                result.innerHTML = '<p style="color:red;">請求失敗：' + err.message + '</p>';
            }
        });
    })();
    </script>
    <?php
}

/*--------------------------------------------------------------
 * REST：用收據編號區間查訂單（供 n8n 以 GET 呼叫）
 * 例：/wp-json/ttcc/v1/orders-by-serial?start=1140001&end=1140120&to_donor=true&fallback_email=ops@ttcc.org.tw
 *-------------------------------------------------------------*/
add_action('rest_api_init', function () {
    register_rest_route('ttcc/v1', '/orders-by-serial', [
        'methods'  => 'GET',
        'callback' => 'ttcc_rest_orders_by_serial',
        // ✅ 依你需求：不再做額外權限驗證
        'permission_callback' => '__return_true',
        'args' => [
            'start' => ['required' => true],
            'end'   => ['required' => true],
            'to_donor' => ['required' => false],
            'fallback_email' => ['required' => false],
        ],
    ]);
});

function ttcc_rest_orders_by_serial( WP_REST_Request $req ) {
    // ===== 1) 解析輸入：優先吃 *_norm，其次解析 start/end =====
    $start_norm = $req->get_param('start_norm');
    $end_norm   = $req->get_param('end_norm');

    $s1000 = $e1000 = null;
    $prefix = ''; $year3 = null; // 供 LIKE 備援查詢

    if ($start_norm !== null && $end_norm !== null) {
        $s1000 = (int)$start_norm;
        $e1000 = (int)$end_norm;
    } else {
        $start_in = (string)$req->get_param('start');
        $end_in   = (string)$req->get_param('end');

        // 嘗試抓 prefix+year-seq（如 TTCC114-031）
        if (preg_match('/^([A-Za-z]{2,10})?(\d{3})-(\d{3})$/', $start_in, $m)) {
            $prefix = strtoupper($m[1] ?? '');
            $year3  = (int)$m[2];
            $seqS   = (int)$m[3];
            $s1000  = $year3 * 1000 + $seqS;
        }
        if (preg_match('/^([A-Za-z]{2,10})?(\d{3})-(\d{3})$/', $end_in, $m)) {
            $yearE  = (int)$m[2];
            $seqE   = (int)$m[3];
            $e1000  = $yearE * 1000 + $seqE;
            // 若 start 沒年，沿用 end 的年
            if ($year3 === null) $year3 = $yearE;
        }

        // 若不是 prefix 形式，試 114031（3+3）
        if ($s1000 === null) {
            $sd = preg_replace('/\D+/', '', $start_in);
            if (strlen($sd) === 6) {
                $y = (int)substr($sd,0,3);
                $q = (int)substr($sd,3);
                $s1000 = $y*1000 + $q;
                if ($year3 === null) $year3 = $y;
            }
        }
        if ($e1000 === null) {
            $ed = preg_replace('/\D+/', '', $end_in);
            if (strlen($ed) === 6) {
                $y = (int)substr($ed,0,3);
                $q = (int)substr($ed,3);
                $e1000 = $y*1000 + $q;
                if ($year3 === null) $year3 = $y;
            }
        }
    }

    if ($s1000 === null || $e1000 === null) {
        return new WP_REST_Response(['error' => 'invalid_range'], 400);
    }
    if ($s1000 > $e1000) { $t=$s1000; $s1000=$e1000; $e1000=$t; }

    // ===== 2) 查詢條件（HPOS 可用）：OR 查兩種尺度 + 年度 LIKE 備援 =====
    $s10000 = (int)floor($s1000/1000)*10000 + ($s1000 % 1000); // 轉舊尺度作補救
    $e10000 = (int)floor($e1000/1000)*10000 + ($e1000 % 1000);

    $meta_query = [
        'relation' => 'OR',
        [
            'key'     => '_custom_serial_number_plain',
            'value'   => [ $s1000, $e1000 ],
            'compare' => 'BETWEEN',
            'type'    => 'NUMERIC',
        ],
        [
            'key'     => '_custom_serial_number_plain',
            'value'   => [ $s10000, $e10000 ],
            'compare' => 'BETWEEN',
            'type'    => 'NUMERIC',
        ],
    ];

    // 若能取到年度，加入 LIKE 備援（舊單沒 plain 也能抓到再做程式端過濾）
    $like_clause = null;
    if (!empty($year3)) {
        $like_val = ($prefix ? $prefix : 'TTCC') . sprintf('%03d-', $year3); // 預設 TTCC
        $like_clause = [
            'key'     => '_custom_serial_number',
            'value'   => $like_val,
            'compare' => 'LIKE',
        ];
        $meta_query[] = $like_clause;
    }

    $to_donor       = (string)$req->get_param('to_donor');
    $fallback_email = sanitize_text_field((string)$req->get_param('fallback_email'));
    $send_to_donor  = in_array(strtolower($to_donor), ['1','true','yes'], true);

    $orders = wc_get_orders([
        'limit'      => -1,
        'orderby'    => 'date',
        'order'      => 'DESC',
        'meta_query' => $meta_query,
    ]);

    // ===== 3) 程式端二次過濾（確保最終在 1000 倍制 s..e 之間）=====
    $in_range = function(WC_Order $order) use($s1000, $e1000) {
        $plain = (int)$order->get_meta('_custom_serial_number_plain');
        if ($plain > 0) {
            // 若是舊尺度（年*10000+seq），轉為 1000 倍制比較
            $digits = (string)$plain;
            if (strlen($digits) === 7) {
                $y = (int)substr($digits,0,3);
                $q = (int)substr($digits,3);
                $norm = $y*1000 + $q;
            } else {
                $norm = $plain; // 已是 1000 倍制
            }
            return ($norm >= $s1000 && $norm <= $e1000);
        }
        // 沒 plain → 從字串解析
        $serial = (string)$order->get_meta('_custom_serial_number');
        if (preg_match('/^[A-Za-z]{2,10}(\d{3})-(\d{3})$/', $serial, $m)) {
            $y = (int)$m[1]; $q = (int)$m[2];
            $norm = $y*1000 + $q;
            return ($norm >= $s1000 && $norm <= $e1000);
        }
        return false;
    };

    $data = [];
    foreach ($orders as $order) {
        /** @var WC_Order $order */
        if (!$in_range($order)) continue;

        $id       = $order->get_id();
        $serial   = $order->get_meta('_custom_serial_number');
        $email    = trim($order->get_billing_email());
        $id_info  = trim((string) $order->get_meta('id_info'));

        $first_name = trim($order->get_billing_first_name());
        $last_name  = trim($order->get_billing_last_name());
        $full_name  = trim($last_name . $first_name) ?: trim($first_name . ' ' . $last_name);

        $billing_company   = trim((string) $order->get_billing_company());
        $billing_country   = $order->get_billing_country();
        $billing_state     = $order->get_billing_state();
        $billing_city      = $order->get_billing_city();
        $billing_postcode  = $order->get_billing_postcode();
        $billing_address_1 = $order->get_billing_address_1();
        $billing_address_2 = $order->get_billing_address_2();
        $address_string = trim(implode('', array_filter([
            $billing_postcode, $billing_state, $billing_city, $billing_address_1,
            $billing_address_2 ? ' ' . $billing_address_2 : '',
        ])));

        // 寄送對象規則
        if ($send_to_donor) {
            if (strcasecmp($email, 'cancercare@ttcc.org.tw') === 0 && !empty($fallback_email)) {
                $target = $fallback_email;
            } else {
                $target = $email;
            }
        } else {
            $target = $fallback_email ?: $email;
        }

        $data[] = [
            'order_id'           => $id,
            'serial'             => $serial,
            'date_created'       => $order->get_date_created()
                                       ? $order->get_date_created()->date_i18n('Y/m/d') : null,
            'total'              => $order->get_total(),
            'currency'           => $order->get_currency(),
            'donor_email'        => $email,
            'target_email'       => $target,
            'receipt_status'     => $order->get_meta('receipt_generated') ?: 'no',
            'id_info'            => $id_info,
            'billing_first_name' => $first_name,
            'billing_last_name'  => $last_name,
            'billing_full_name'  => $full_name,
            'billing_company'    => $billing_company,
            'billing_country'    => $billing_country,
            'billing_state'      => $billing_state,
            'billing_city'       => $billing_city,
            'billing_postcode'   => $billing_postcode,
            'billing_address_1'  => $billing_address_1,
            'billing_address_2'  => $billing_address_2,
            'billing_address'    => $address_string,
        ];
    }

    return new WP_REST_Response([
        'count'  => count($data),
        'range'  => [
            'start_1000' => $s1000, 'end_1000' => $e1000,
            'like'       => (!empty($year3) ? (($prefix?:'TTCC').sprintf('%03d-',$year3)) : null),
        ],
        'to_donor'       => $send_to_donor,
        'fallback_email' => $fallback_email,
        'orders'         => $data,
    ], 200);
}

/**
 * TTCC 收據：台灣州/縣市英文代碼轉中文
 */
function ttcc_get_tw_state_label( $state ) {
    $state = strtoupper( trim( (string) $state ) );

    $map = array(
        'TAIPEI CITY'        => '台北市',
        'NEW TAIPEI CITY'    => '新北市',
        'TAOYUAN CITY'       => '桃園市',
        'TAICHUNG CITY'      => '台中市',
        'TAINAN CITY'        => '台南市',
        'KAOHSIUNG CITY'     => '高雄市',
        'KEELUNG CITY'       => '基隆市',
        'HSINCHU CITY'       => '新竹市',
        'HSINCHU COUNTY'     => '新竹縣',
        'MIAOLI COUNTY'      => '苗栗縣',
        'CHANGHUA COUNTY'    => '彰化縣',
        'NANTOU COUNTY'      => '南投縣',
        'YUNLIN COUNTY'      => '雲林縣',
        'CHIAYI CITY'        => '嘉義市',
        'CHIAYI COUNTY'      => '嘉義縣',
        'PINGTUNG COUNTY'    => '屏東縣',
        'YILAN COUNTY'       => '宜蘭縣',
        'HUALIEN COUNTY'     => '花蓮縣',
        'TAITUNG COUNTY'     => '台東縣',
        'PENGHU COUNTY'      => '澎湖縣',
        'KINMEN COUNTY'      => '金門縣',
        'LIENCHIANG COUNTY'  => '連江縣',
    );

    return isset( $map[ $state ] ) ? $map[ $state ] : $state;
}

/**
 * TTCC 收據：整理單筆訂單資料（2026 樣本第一版）
 */
function ttcc_get_receipt_data( $order_id ) {
    $order_id = absint( $order_id );
    if ( ! $order_id ) {
        return null;
    }

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return null;
    }

    $first_name = trim( (string) $order->get_billing_first_name() );
    $last_name  = trim( (string) $order->get_billing_last_name() );
    $full_name  = trim( $last_name . $first_name );

    if ( $full_name === '' ) {
        $full_name = trim( (string) $order->get_billing_company() );
    }

    $id_info = trim( (string) $order->get_meta( 'id_info' ) );

    $billing_postcode  = trim( (string) $order->get_billing_postcode() );
    $billing_state_raw = trim( (string) $order->get_billing_state() );
    $billing_state     = ttcc_get_tw_state_label( $billing_state_raw );
    $billing_city      = trim( (string) $order->get_billing_city() );
    $billing_address_1 = trim( (string) $order->get_billing_address_1() );
    $billing_address_2 = trim( (string) $order->get_billing_address_2() );

    $address_tail_parts = array(
        $billing_state,
        $billing_city,
        $billing_address_1,
        $billing_address_2,
    );
    $address_tail_parts = array_filter( $address_tail_parts, function( $v ) {
        return $v !== '';
    } );

    $address_tail = implode( '', $address_tail_parts );

    if ( $billing_postcode !== '' && $address_tail !== '' ) {
        $receipt_address = $billing_postcode . ' ' . $address_tail;
    } elseif ( $billing_postcode !== '' ) {
        $receipt_address = $billing_postcode;
    } else {
        $receipt_address = $address_tail;
    }

    $date_created = $order->get_date_created();
    $receipt_date = $date_created ? $date_created->date_i18n( 'Y/m/d' ) : '';

    $serial = trim( (string) $order->get_meta( '_custom_serial_number' ) );

    $amount = $order->get_total();
    $amount = is_numeric( $amount ) ? (float) $amount : 0;

    return array(
        'order_id'            => $order->get_id(),
        'receipt_no'          => $serial,
        'receipt_date'        => $receipt_date,
        'donor_name'          => $full_name,
        'donor_id_info'       => $id_info,
        'recipient_email'     => trim( (string) $order->get_billing_email() ),
        'recipient_phone'     => trim( (string) $order->get_billing_phone() ),
        'receipt_address'     => $receipt_address,
        'donation_amount'     => $amount,
        'donation_amount_int' => (int) round( $amount ),
        'receipt_generated'   => (string) $order->get_meta( 'receipt_generated' ),
        'template_year'       => '2026',
        'logo_path'           => ABSPATH . 'wp-content/ttcc/cellImage_0_0.jpg',
        'qrcode_path'         => ABSPATH . 'wp-content/ttcc/cellImage_0_1.jpg',
        'seal_path'           => ABSPATH . 'wp-content/ttcc/cellImage_0_2.jpg',
        'chairman_sign_path'  => ABSPATH . 'wp-content/ttcc/cellImage_0_3.jpg',
        'handler_sign_path'   => ABSPATH . 'wp-content/ttcc/cellImage_0_4.jpg',
    );
}

/**
 * TTCC 收據：把本機圖片檔轉成 data URI
 */
function ttcc_img_file_to_data_uri( $file_path ) {
    $file_path = (string) $file_path;

    if ( $file_path === '' || ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
        return '';
    }

    $mime = function_exists( 'mime_content_type' ) ? mime_content_type( $file_path ) : '';
    if ( ! $mime ) {
        $ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        if ( $ext === 'png' ) {
            $mime = 'image/png';
        } elseif ( $ext === 'webp' ) {
            $mime = 'image/webp';
        } else {
            $mime = 'image/jpeg';
        }
    }

    $bin = file_get_contents( $file_path );
    if ( $bin === false ) {
        return '';
    }

    return 'data:' . $mime . ';base64,' . base64_encode( $bin );
}

/**
 * TTCC 收據：2026 版 HTML
 */
function ttcc_render_receipt_2026_html( $order_id ) {
    $data = ttcc_get_receipt_data( $order_id );
    if ( empty( $data ) || ! is_array( $data ) ) {
        return '<p>找不到收據資料</p>';
    }

    $logo_src      = ttcc_img_file_to_data_uri( $data['logo_path'] );
    $qrcode_src    = ttcc_img_file_to_data_uri( $data['qrcode_path'] );
    $seal_src      = ttcc_img_file_to_data_uri( $data['seal_path'] );
    $chairman_src  = ttcc_img_file_to_data_uri( $data['chairman_sign_path'] );
    $handler_src   = ttcc_img_file_to_data_uri( $data['handler_sign_path'] );

    $receipt_no      = esc_html( $data['receipt_no'] );
    $receipt_date    = esc_html( $data['receipt_date'] );
    $donor_name      = esc_html( $data['donor_name'] );
    $donor_id_info   = trim( (string) $data['donor_id_info'] );
    $receipt_address = esc_html( $data['receipt_address'] );
    $amount          = number_format( (int) $data['donation_amount_int'] );

    if ( $donor_id_info === '' ) {
        $donor_id_info = '未提供';
    }
    $donor_id_info = esc_html( $donor_id_info );

    ob_start();
    ?>
<!doctype html>
<html lang="zh-Hant">
<head>
<meta charset="utf-8">
<title>TTCC 2026 捐款收據預覽</title>
<style>
    @page {
        size: A5 landscape;
        margin: 8mm 10mm 8mm 10mm;
    }

    html, body {
        margin: 0;
        padding: 0;
        background: #fff;
        color: #111;
        font-family: "PMingLiU", "MingLiU", "Noto Serif TC", "Microsoft JhengHei", serif;
        font-size: 12px;
        line-height: 1.
    }

    body {
        background: #fff;
    }

    .page {
        width: 190mm;
        min-height: 126mm;
        margin: 0 auto;
        box-sizing: border-box;
        padding: 0;
    }

    .receipt {
        width: 100%;
        box-sizing: border-box;
        padding: 1mm 0 0 0;
    }

    .brand-row {
        text-align: center;
        margin-bottom: 1mm;
    }

    .brand-logo {
        display: inline-block;
        width: 80mm;
        max-width: 100%;
        height: auto;
    }

    .title-row {
        text-align: center;
        font-size: 16px;
        font-weight: 700;
        letter-spacing: 2px;
        margin-bottom: 1.5mm;
    }

    .meta-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        margin-bottom: 1.5mm;
    }

    .meta-table td {
        padding: 0.3mm 0;
        vertical-align: top;
        font-size: 13px;
        letter-spacing: 2px;
    }

    .meta-left {
        width: 58%;
        text-align: left;
        white-space: nowrap;
    }

    .meta-right {
        width: 42%;
        text-align: right;
        white-space: nowrap;
    }

    .receipt-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        margin-bottom: 1mm;
        border: 3px solid #000;
    }

    .receipt-table td {
        border: 2px solid #000;
        padding: 1.4mm 2.2mm;
        vertical-align: middle;
        font-size: 13px;
        letter-spacing: 2px;
        word-break: break-word;
    }

    .label-cell {
        width: 25%;
        text-align: center;
        white-space: nowrap;
    }

    .value-cell {
        width: 60%;
        text-align: left;
    }

    .qr-cell {
        width: 15%;
        text-align: center;
        vertical-align: middle;
        padding: 0;
    }

    .qr-cell-inner {
        min-height: 38mm;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 1mm;
        box-sizing: border-box;
    }

    .qr-img {
        width: 26mm;
        height: auto;
        display: block;
    }

    .money {
        font-size: 14px;
        font-weight: 700;
        letter-spacing: 0.2px;
    }

    .sign-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        margin-bottom: 1.2mm;
    }

    .sign-table td {
        padding: 0;
        vertical-align: top;
        font-size: 13px;
        letter-spacing: 2px;
    }

    .sign-left {
        width: 40%;
        text-align: center;
    }

    .sign-mid {
        width: 30%;
        text-align: center;
    }

    .sign-right {
        width: 30%;
        text-align: center;
    }

    .inline-sign {
        display: inline-flex;
        align-items: flex-start;
        justify-content: center;
        gap: 2mm;
    }

    .inline-sign .label {
        white-space: nowrap;
        padding-top: 12px;
    }

    .seal-img {
        width: 20mm;
        height: auto;
        display: block;
        margin-top: -10px;
    }

    .sign-img {
        width: 10mm;
        height: auto;
        display: block;
    }

    .bottom-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        margin-top: 0;
    }

    .bottom-table td {
        vertical-align: top;
        font-size: 12px;
        letter-spacing: 1px;
        padding: 0;
    }

    .bottom-left {
        width: 48%;
        text-align: left;
        padding-right: 6mm;
    }

    .bottom-right {
        width: 52%;
        text-align: left;
    }

    .bottom-line {
        margin-bottom: 0.3mm;
        white-space: nowrap;
    }
</style>
</head>
<body>
<div class="page">
    <section class="receipt">

        <div class="brand-row">
            <?php if ( $logo_src ) : ?>
                <img class="brand-logo" src="<?php echo esc_attr( $logo_src ); ?>" alt="">
            <?php endif; ?>
        </div>

        <div class="title-row">捐款收據</div>

        <table class="meta-table">
            <tr>
                <td class="meta-left">統一編號：25687479</td>
                <td class="meta-right">捐款日期：<?php echo $receipt_date; ?></td>
            </tr>
            <tr>
                <td class="meta-left">立案字號：衛署醫字第 0990206548 號</td>
                <td class="meta-right">收據編號：<?php echo $receipt_no; ?></td>
            </tr>
        </table>

        <table class="receipt-table">
            <colgroup>
                <col style="width:20%;">
                <col style="width:60%;">
                <col style="width:20%;">
            </colgroup>
            <tr>
                <td class="label-cell">捐款者</td>
                <td class="value-merged-cell" colspan="2"><?php echo $donor_name !== '' ? $donor_name : '&nbsp;'; ?></td>
            </tr>
            <tr>
                <td class="label-cell">身分證字號/統編</td>
                <td class="value-cell"><?php echo $donor_id_info; ?></td>
                <td class="qr-cell" rowspan="4">
                    <div class="qr-cell-inner">
                        <?php if ( $qrcode_src ) : ?>
                            <img class="qr-img" src="<?php echo esc_attr( $qrcode_src ); ?>" alt="">
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <tr>
                <td class="label-cell">收據地址</td>
                <td class="value-cell"><?php echo $receipt_address !== '' ? $receipt_address : '&nbsp;'; ?></td>
            </tr>
            <tr>
                <td class="label-cell">捐款金額（新台幣）</td>
                <td class="value-cell money">NT$ <?php echo esc_html( $amount ); ?></td>
            </tr>
            <tr>
                <td class="label-cell">備註</td>
                <td class="value-cell">本捐款單可做為扣抵稅額用</td>
            </tr>
        </table>

        <table class="sign-table">
            <tr>
                <td class="sign-left">
                    <div class="inline-sign">
                        <div class="label">本會大印</div>
                        <?php if ( $seal_src ) : ?>
                            <img class="seal-img" src="<?php echo esc_attr( $seal_src ); ?>" alt="">
                        <?php endif; ?>
                    </div>
                </td>
                <td class="sign-mid">
                    <div class="inline-sign">
                        <div class="label">董事長：</div>
                        <?php if ( $chairman_src ) : ?>
                            <img class="sign-img" src="<?php echo esc_attr( $chairman_src ); ?>" alt="">
                        <?php endif; ?>
                    </div>
                </td>
                <td class="sign-right">
                    <div class="inline-sign">
                        <div class="label">經手人：</div>
                        <?php if ( $handler_src ) : ?>
                            <img class="sign-img" src="<?php echo esc_attr( $handler_src ); ?>" alt="">
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        </table>

        <table class="bottom-table">
            <tr>
                <td class="bottom-left">
                    <div class="bottom-line">戶　　名：財團法人台灣癌症全人關懷基金會</div>
                    <div class="bottom-line">分　　行：玉山銀行 營業部</div>
                    <div class="bottom-line">捐款帳號：0015-940-139571</div>
                </td>
                <td class="bottom-right">
                    <div class="bottom-line">郵政劃撥帳號：50161441</div>
                    <div class="bottom-line">地址：104011 台北市中山區民生東路一段 26號11 樓之 2</div>
                    <div class="bottom-line">服務電話/E-Mail：02-25813136/cancercare@ttcc.org.tw</div>
                </td>
            </tr>
        </table>

    </section>
</div>
</body>
</html>
    <?php
    return ob_get_clean();
}

function ttcc_generate_receipt_pdf_binary( $order_id ) {

    $html = ttcc_render_receipt_2026_html( $order_id );

    if ( ! is_string( $html ) || trim( $html ) === '' ) {
        return new WP_Error( 'invalid_html', 'Receipt HTML is empty' );
    }

    if ( strpos( $html, '<html' ) === false ) {
        return new WP_Error( 'invalid_html', 'INVALID HTML RESPONSE: ' . substr( $html, 0, 200 ) );
    }

    $upload_dir = wp_upload_dir();
    $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'ttcc-receipt-pdf-tmp';

    if ( ! file_exists( $tmp_dir ) ) {
        wp_mkdir_p( $tmp_dir );
    }

    $tmp_html = trailingslashit( $tmp_dir ) . 'ttcc-receipt-' . $order_id . '-' . time() . '.html';
    file_put_contents( $tmp_html, $html );

    $ch = curl_init();

    curl_setopt_array( $ch, array(
        CURLOPT_URL            => 'https://gotenberg-proxy-668416723649.asia-east1.run.app/forms/chromium/convert/html',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => array(
            'files'             => new CURLFile( $tmp_html, 'text/html', 'index.html' ),
            'printBackground'   => 'true',
            'preferCssPageSize' => 'true',
            'waitDelay'         => '2s',
        ),
        CURLOPT_TIMEOUT        => 30,
    ) );

    $pdf_binary = curl_exec( $ch );
    $http_code  = curl_getinfo( $ch, CURLINFO_HTTP_CODE );

    if ( $pdf_binary === false ) {
        $error = curl_error( $ch );
        curl_close( $ch );
        if ( file_exists( $tmp_html ) ) {
            unlink( $tmp_html );
        }
        return new WP_Error( 'curl_failed', 'CURL ERROR: ' . $error );
    }

    curl_close( $ch );

    if ( file_exists( $tmp_html ) ) {
        unlink( $tmp_html );
    }

    if ( $http_code !== 200 ) {
        return new WP_Error( 'gotenberg_http_error', 'HTTP ERROR: ' . $http_code . ' | ' . substr( $pdf_binary, 0, 200 ) );
    }

    if ( strpos( $pdf_binary, '%PDF' ) !== 0 ) {
        return new WP_Error( 'invalid_pdf', 'PDF binary invalid' );
    }

    return $pdf_binary;
}

function ttcc_generate_receipt_pdf_file( $order_id ) {

    $pdf_binary = ttcc_generate_receipt_pdf_binary( $order_id );

    if ( is_wp_error( $pdf_binary ) ) {
        return $pdf_binary;
    }

    $upload_dir = wp_upload_dir();
    $tmp_dir    = trailingslashit( $upload_dir['basedir'] ) . 'ttcc-receipt-pdf-tmp';

    if ( ! file_exists( $tmp_dir ) ) {
        wp_mkdir_p( $tmp_dir );
    }

    $data = ttcc_get_receipt_data( $order_id );

    $receipt_no = '';
    if ( is_array( $data ) && ! empty( $data['receipt_no'] ) ) {
        $receipt_no = preg_replace( '/[^A-Za-z0-9\-_]/', '', (string) $data['receipt_no'] );
    }

    if ( $receipt_no === '' ) {
        $receipt_no = 'order-' . absint( $order_id );
    }

    $pdf_path = trailingslashit( $tmp_dir ) . 'TTCC-receipt-' . $receipt_no . '.pdf';

    $written = file_put_contents( $pdf_path, $pdf_binary );

    if ( $written === false || ! file_exists( $pdf_path ) ) {
        return new WP_Error( 'pdf_write_failed', 'Failed to write PDF file' );
    }

    return $pdf_path;
}

/**
 * TTCC 收據：2026 預覽入口
 * 用法：
 * /wp-admin/admin-post.php?action=ttcc_receipt_preview&order_id=24236
 */
add_action( 'admin_post_ttcc_receipt_preview', 'ttcc_receipt_preview_handler' );

function ttcc_receipt_preview_handler() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( '權限不足' );
    }

    $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
    if ( ! $order_id ) {
        wp_die( '缺少 order_id' );
    }

    check_admin_referer( 'ttcc_receipt_preview_' . $order_id );

    nocache_headers();
    header( 'Content-Type: text/html; charset=utf-8' );

    echo ttcc_render_receipt_2026_html( $order_id );
    exit;
}

add_action( 'admin_post_ttcc_receipt_pdf', 'ttcc_receipt_pdf_handler' );

function ttcc_receipt_pdf_handler() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( '權限不足' );
    }

    $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
    if ( ! $order_id ) {
        wp_die( '缺少 order_id' );
    }

    check_admin_referer( 'ttcc_receipt_pdf_' . $order_id );

    $pdf_file = ttcc_generate_receipt_pdf_file( $order_id );

    if ( is_wp_error( $pdf_file ) ) {
        wp_die( esc_html( $pdf_file->get_error_message() ) );
    }

    if ( ! file_exists( $pdf_file ) ) {
        wp_die( 'PDF 檔案不存在' );
    }

    $filename = basename( $pdf_file );

    nocache_headers();
    header( 'Content-Type: application/pdf' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    header( 'Content-Length: ' . filesize( $pdf_file ) );

    readfile( $pdf_file );
    exit;
}

function ttcc_log_receipt_mail_result( $order_id, $mail_to, $receipt_no, $sent, $extra = array() ) {
    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return;
    }

    $count = (int) $order->get_meta( 'ttcc_receipt_mail_send_count', true );
    $count++;
    $order->update_meta_data( 'ttcc_receipt_mail_send_count', $count );

    $sent_at = current_time( 'mysql' );
    $order->update_meta_data( 'ttcc_receipt_mail_last_sent_at', $sent_at );

    $log = $order->get_meta( 'ttcc_receipt_mail_sent_log', true );
    if ( ! is_array( $log ) ) {
        $log = array();
    }

    $row = array(
        'sent_at'    => $sent_at,
        'mail_to'    => is_array( $mail_to ) ? array_values( $mail_to ) : array( (string) $mail_to ),
        'receipt_no' => (string) $receipt_no,
        'success'    => $sent ? 1 : 0,
    );

    if ( ! empty( $extra ) && is_array( $extra ) ) {
        $row = array_merge( $row, $extra );
    }

    $log[] = $row;
    $order->update_meta_data( 'ttcc_receipt_mail_sent_log', $log );

    $order->save();
}

function ttcc_get_receipt_mail_recipients( $order_id, $args = array() ) {
    $args = wp_parse_args(
        $args,
        array(
            'mode'    => 'test',
            'mail_to' => array(
                'chia@mplus01.com',
                'shine_ivy@hotmail.com',
            ),
        )
    );

    $mail_to = array();

    if ( $args['mode'] === 'live' ) {
        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return new WP_Error( 'ttcc_order_not_found', '找不到訂單' );
        }

        $billing_email = trim( (string) $order->get_billing_email() );

        if ( $billing_email === '' ) {
            return new WP_Error( 'ttcc_live_mail_to_empty', '此訂單沒有捐款人 Email，無法正式寄送收據' );
        }

        $mail_to = array( $billing_email );
    } else {
        $mail_to = $args['mail_to'];
    }

    if ( ! is_array( $mail_to ) ) {
        $mail_to = array( (string) $mail_to );
    }

    $mail_to = array_values( array_filter( array_map( 'trim', $mail_to ) ) );

    if ( empty( $mail_to ) ) {
        if ( $args['mode'] === 'live' ) {
            return new WP_Error( 'ttcc_live_mail_to_empty', '此訂單沒有捐款人 Email，無法正式寄送收據' );
        }

        return new WP_Error( 'ttcc_mail_to_empty', '收件人不可為空' );
    }

    return $mail_to;
}

function ttcc_send_receipt_pdf_email( $order_id, $args = array() ) {
    $args = wp_parse_args(
        $args,
        array(
            'mode'    => 'test',
            'mail_to' => array(
                'chia@mplus01.com',
                'shine_ivy@hotmail.com',
            ),
            'source'  => 'single_manual',
            'user_id' => get_current_user_id(),
        )
    );

    $order = wc_get_order( $order_id );
    if ( ! $order ) {
        return new WP_Error( 'ttcc_order_not_found', '找不到訂單' );
    }

    $pdf_file = ttcc_generate_receipt_pdf_file( $order_id );
    if ( is_wp_error( $pdf_file ) ) {
        ttcc_log_receipt_mail_result(
            $order_id,
            array(),
            '',
            false,
            array(
                'source'        => (string) $args['source'],
                'user_id'       => (int) $args['user_id'],
                'error_message' => $pdf_file->get_error_message(),
            )
        );

        return $pdf_file;
    }

    if ( ! file_exists( $pdf_file ) ) {
        ttcc_log_receipt_mail_result(
            $order_id,
            array(),
            '',
            false,
            array(
                'source'        => (string) $args['source'],
                'user_id'       => (int) $args['user_id'],
                'error_message' => 'PDF 檔案不存在',
            )
        );

        return new WP_Error( 'ttcc_pdf_missing', 'PDF 檔案不存在' );
    }

    $data = ttcc_get_receipt_data( $order_id );

    $receipt_no = '';
    if ( is_array( $data ) && ! empty( $data['receipt_no'] ) ) {
        $receipt_no = (string) $data['receipt_no'];
    }

    $mail_to = ttcc_get_receipt_mail_recipients( $order_id, $args );

    if ( is_wp_error( $mail_to ) ) {
        ttcc_log_receipt_mail_result(
            $order_id,
            array(),
            $receipt_no,
            false,
            array(
                'source'        => (string) $args['source'],
                'user_id'       => (int) $args['user_id'],
                'error_message' => $mail_to->get_error_message(),
            )
        );

        return $mail_to;
    }

    $subject = '捐款收據';
    if ( $receipt_no !== '' ) {
        $subject .= '｜' . $receipt_no;
    }

    $donor_name = '';
    if ( is_array( $data ) && ! empty( $data['donor_name'] ) ) {
        $donor_name = trim( (string) $data['donor_name'] );
    }
    if ( $donor_name === '' ) {
        $donor_name = '捐款人';
    }

    $body  = '<!DOCTYPE html>';
    $body .= '<html lang="zh-Hant">';
    $body .= '<head>';
    $body .= '<meta charset="UTF-8">';
    $body .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
    $body .= '<title>捐款收據</title>';
    $body .= '</head>';
    $body .= '<body style="margin:0; padding:0; background-color:#f6f6f6; background-image:url(https://www.ttcc.org.tw/wp-content/uploads/2025/09/mailbackground-scaled.jpg); background-repeat:no-repeat; background-position:center top; background-size:cover;">';
    $body .= '<div style="margin:0; padding:40px 16px;">';
    $body .= '<div style="max-width:720px; margin:0 0 0 160px; background:transparent; border-radius:12px; padding:32px 28px; color:#333333; font-size:16px; line-height:1.9;">';

    $body .= '<p style="margin:0 0 20px; font-weight:700;">親愛的 ' . esc_html( $donor_name ) . ' 您好：</p>';
    $body .= '<p style="margin:0 0 20px;">感謝您伸出援手，支持本會。</p>';
    $body .= '<p style="margin:0 0 20px;">因為有您，我們能夠走進更多校園，傳遞正確的癌症知識；走進病房與家中，為癌友與家屬帶去鼓勵與溫暖；開設更多專業課程，培養更多守護生命的力量。</p>';
    $body .= '<p style="margin:0 0 20px;">您的捐款，不只是金額，而是陪伴、是希望、是力量。<br>讓我們一起，守護更多生命的笑容。</p>';
    $body .= '<p style="margin:0 0 20px;">再次謝謝您的愛心 💗</p>';
    $body .= '<p style="margin:0; font-weight:700;">財團法人台灣癌症全人關懷基金會 敬啟</p>';
    $body .= '<div style="margin-top:24px; text-align:right;">';
    $body .= '<img src="https://www.ttcc.org.tw/wp-content/uploads/2025/09/mailbackground2.png" alt="台灣癌症全人關懷基金會宣導圖" style="display:inline-block; max-width:360px; width:100%; height:auto; border:0;">';
    $body .= '</div>';

    $body .= '</div>';
    $body .= '</div>';
    $body .= '</body>';
    $body .= '</html>';

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: 財團法人台灣癌症全人關懷基金會 <no-reply@ttcc.org.tw>',
        'Reply-To: service@ttcc.org.tw',
        'Bcc: service@ttcc.org.tw',
    );
    $attachments = array( $pdf_file );

    $sent = wp_mail( $mail_to, $subject, $body, $headers, $attachments );

    $log_extra = array(
        'source'  => (string) $args['source'],
        'user_id' => (int) $args['user_id'],
    );

    if ( ! $sent ) {
        $log_extra['error_message'] = 'wp_mail 寄送失敗';
    }

    ttcc_log_receipt_mail_result(
        $order_id,
        $mail_to,
        $receipt_no,
        $sent,
        $log_extra
    );

    return array(
        'success'    => (bool) $sent,
        'mail_to'    => $mail_to,
        'receipt_no' => $receipt_no,
        'pdf_file'   => $pdf_file,
        'subject'    => $subject,
    );
}

add_action( 'admin_post_ttcc_send_receipt_pdf', 'ttcc_send_receipt_pdf_handler' );

function ttcc_send_receipt_pdf_handler() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( '權限不足' );
    }

    $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
    if ( ! $order_id ) {
        wp_die( '缺少 order_id' );
    }

    check_admin_referer( 'ttcc_send_receipt_pdf_' . $order_id );

    $result = ttcc_send_receipt_pdf_email(
        $order_id,
        array(
            'mode'    => 'live',
            'mail_to' => array(
                'chia@mplus01.com',
                'shine_ivy@hotmail.com',
            ),
            'source'  => 'single_manual',
            'user_id' => get_current_user_id(),
        )
    );

    if ( is_wp_error( $result ) ) {
        $redirect_url = add_query_arg(
            array(
                'ttcc_receipt_mail' => 'error',
                'ttcc_order_id'     => $order_id,
                'ttcc_mail_msg'     => rawurlencode( $result->get_error_message() ),
            ),
            admin_url( 'post.php?post=' . $order_id . '&action=edit' )
        );
        wp_safe_redirect( $redirect_url );
        exit;
    }

    $mail_to_text = '';
    if ( ! empty( $result['mail_to'] ) && is_array( $result['mail_to'] ) ) {
        $mail_to_text = implode( ', ', $result['mail_to'] );
    }

    $redirect_url = add_query_arg(
        array(
            'ttcc_receipt_mail' => ! empty( $result['success'] ) ? 'success' : 'error',
            'ttcc_order_id'     => $order_id,
            'ttcc_mail_msg'     => ! empty( $result['success'] )
                ? rawurlencode( 'PDF 已寄送到 ' . $mail_to_text )
                : rawurlencode( 'wp_mail 寄送失敗' ),
        ),
        admin_url( 'post.php?post=' . $order_id . '&action=edit' )
    );

    wp_safe_redirect( $redirect_url );
    exit;
}

add_action( 'admin_notices', 'ttcc_receipt_mail_admin_notice' );

function ttcc_receipt_mail_admin_notice() {
    if ( empty( $_GET['ttcc_receipt_mail'] ) || empty( $_GET['ttcc_mail_msg'] ) ) {
        return;
    }

    $status = sanitize_text_field( wp_unslash( $_GET['ttcc_receipt_mail'] ) );
    $msg    = sanitize_text_field( wp_unslash( $_GET['ttcc_mail_msg'] ) );

    if ( $status === 'success' ) {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
        return;
    }

    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
}

/**
 * TTCC 收據：臨時測試入口（僅管理員）
 * 用法：
 * /wp-admin/admin-post.php?action=ttcc_receipt_debug&order_id=24236
 */
add_action( 'admin_post_ttcc_receipt_debug', 'ttcc_receipt_debug_handler' );

function ttcc_receipt_debug_handler() {
    if ( ! current_user_can( 'manage_woocommerce' ) ) {
        wp_die( '權限不足' );
    }

    $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
    if ( ! $order_id ) {
        wp_die( '缺少 order_id' );
    }

    $data = ttcc_get_receipt_data( $order_id );

    if ( empty( $data ) || ! is_array( $data ) ) {
        wp_die( '找不到收據資料' );
    }

    nocache_headers();
    header( 'Content-Type: text/plain; charset=utf-8' );

    echo "TTCC Receipt Debug\n";
    echo "===================\n\n";
    print_r( $data );
    exit;
}

//===========================================================訂單列表
// 正確註冊收據編號欄位（支援 HPOS & 螢幕顯示選項）
// 加入欄位：HPOS 專用 hooks
add_filter( 'manage_woocommerce_page_wc-orders_columns', 'yess_add_serial_column_hpos' );
function yess_add_serial_column_hpos( $columns ) {
        $columns['custom_serial_number']     = '收據編號';
        $columns['ttcc_receipt_mail_status'] = '收據寄送';
        return $columns;
}

// 顯示欄位內容：HPOS 專用 hooks
add_action( 'manage_woocommerce_page_wc-orders_custom_column', 'yess_show_serial_column_hpos', 10, 2 );
function yess_show_serial_column_hpos( $column, $order ) {
    if ( $column === 'custom_serial_number' && is_a( $order, 'WC_Order' ) ) {
            $serial = $order->get_meta( '_custom_serial_number' );
            echo $serial ? esc_html( $serial ) : '—';
    }

    if ( $column === 'ttcc_receipt_mail_status' && is_a( $order, 'WC_Order' ) ) {
            $receipt_mail_log = $order->get_meta( 'ttcc_receipt_mail_sent_log', true );

            if ( empty( $receipt_mail_log ) || ! is_array( $receipt_mail_log ) ) {
                    echo '未寄送';
                    return;
            }

            $latest_log     = end( $receipt_mail_log );
            $success        = isset( $latest_log['success'] ) ? (int) $latest_log['success'] : null;
            $error_message  = ! empty( $latest_log['error_message'] ) ? trim( (string) $latest_log['error_message'] ) : '';

            if ( is_null( $success ) ) {
                    echo '未寄送';
            } elseif ( $success ) {
                    echo '成功';
            } else {
                    if ( $error_message === '此訂單沒有捐款人 Email，無法正式寄送收據' ) {
                            echo '失敗：缺捐款者email';
                    } else {
                            echo '失敗';
                    }
            }
    }
}

add_filter( 'woocommerce_shop_order_search_fields', function( $search_fields ) {
    $search_fields[] = '_custom_serial_number'; // 注意這裡要加底線 _
    return $search_fields;
} );

add_filter( 'bulk_actions-woocommerce_page_wc-orders', 'ttcc_add_receipt_bulk_action' );
function ttcc_add_receipt_bulk_action( $bulk_actions ) {
    $bulk_actions['ttcc_send_receipt_pdf_bulk'] = '批次寄送捐款收據';
    return $bulk_actions;
}

add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', 'ttcc_handle_receipt_bulk_action', 10, 3 );
function ttcc_handle_receipt_bulk_action( $redirect_to, $action, $order_ids ) {
    if ( $action !== 'ttcc_send_receipt_pdf_bulk' ) {
        return $redirect_to;
    }

    $success_count              = 0;
    $error_count                = 0;
    $missing_donor_email_count  = 0;

    if ( ! is_array( $order_ids ) ) {
        $order_ids = array();
    }

    foreach ( $order_ids as $order_id ) {
        $order_id = absint( $order_id );
        if ( ! $order_id ) {
            $error_count++;
            continue;
        }

        $result = ttcc_send_receipt_pdf_email(
            $order_id,
            array(
                'mode'    => 'live',
                'mail_to' => array(
                    'chia@mplus01.com',
                    'shine_ivy@hotmail.com',
                ),
                'source'  => 'bulk_manual',
                'user_id' => get_current_user_id(),
            )
        );

        if ( is_wp_error( $result ) ) {
            $error_count++;

            if ( $result->get_error_code() === 'ttcc_live_mail_to_empty' ) {
                $missing_donor_email_count++;
            }

            continue;
        }

        if ( ! empty( $result['success'] ) ) {
            $success_count++;
        } else {
            $error_count++;
        }
    }

    $redirect_to = add_query_arg(
        array(
            'ttcc_bulk_receipt_mail'                => 1,
            'ttcc_bulk_receipt_mail_success'        => $success_count,
            'ttcc_bulk_receipt_mail_error'          => $error_count,
            'ttcc_bulk_receipt_mail_missing_donor'  => $missing_donor_email_count,
        ),
        $redirect_to
    );

    return $redirect_to;
}

add_action( 'admin_notices', 'ttcc_bulk_receipt_mail_admin_notice' );
function ttcc_bulk_receipt_mail_admin_notice() {
    if ( empty( $_GET['ttcc_bulk_receipt_mail'] ) ) {
        return;
    }

    $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
    if ( $screen && $screen->id !== 'woocommerce_page_wc-orders' ) {
        return;
    }

    $success_count             = isset( $_GET['ttcc_bulk_receipt_mail_success'] ) ? absint( $_GET['ttcc_bulk_receipt_mail_success'] ) : 0;
    $error_count               = isset( $_GET['ttcc_bulk_receipt_mail_error'] ) ? absint( $_GET['ttcc_bulk_receipt_mail_error'] ) : 0;
    $missing_donor_email_count = isset( $_GET['ttcc_bulk_receipt_mail_missing_donor'] ) ? absint( $_GET['ttcc_bulk_receipt_mail_missing_donor'] ) : 0;

    $class   = $error_count > 0 ? 'notice notice-warning is-dismissible' : 'notice notice-success is-dismissible';
    $message = '批次寄送捐款收據完成：成功 ' . $success_count . ' 筆，失敗 ' . $error_count . ' 筆。';

    if ( $missing_donor_email_count > 0 ) {
        $message .= ' 缺捐款者 email：' . $missing_donor_email_count . ' 筆。';
    }

    echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $message ) . '</p></div>';
}

//=============================================================================================訂單可以修改建立時間
add_filter( 'woocommerce_rest_pre_insert_shop_order_object', function( $order, $request ) {
	if ( isset( $request['date_created'] ) ) {
		$order->set_date_created( wc_clean( $request['date_created'] ) );
	}
	if ( isset( $request['date_created_gmt'] ) ) {
		$order->set_date_created( get_date_from_gmt( wc_clean( $request['date_created_gmt'] ) ) );
	}
	return $order;
}, 10, 2 );


/**
 * 1. 註冊 "依付款方式" 報表分頁
 */
add_filter( 'woocommerce_admin_reports', 'db_native_add_gateway_report_tab' );
function db_native_add_gateway_report_tab( $reports ) {
    $reports['orders']['reports']['sales_by_gateway'] = array(
        'title'       => '依付款方式',
        'description' => '',
        'hide_title'  => true,
        'callback'    => 'db_native_render_gateway_report_page',
    );
    return $reports;
}

/**
 * 2. 渲染報表內容 (DB 直讀 + 原生 UI)
 */
function db_native_render_gateway_report_page() {
    global $wpdb;

    // --- A. 參數與日期處理 ---
    $current_range = ! empty( $_GET['range'] ) ? sanitize_text_field( $_GET['range'] ) : '7day';
    $start_date    = ! empty( $_GET['start_date'] ) ? sanitize_text_field( $_GET['start_date'] ) : date( 'Y-m-d', strtotime( '-6 days' ) );
    $end_date      = ! empty( $_GET['end_date'] ) ? sanitize_text_field( $_GET['end_date'] ) : date( 'Y-m-d' );
    $selected_gateway = ! empty( $_GET['filter_gateway'] ) ? sanitize_text_field( $_GET['filter_gateway'] ) : '';

    // 處理快速日期範圍
    if ( 'month' === $current_range ) {
        $start_date = date( 'Y-m-01' );
        $end_date   = date( 'Y-m-d' );
    } elseif ( 'last_month' === $current_range ) {
        $start_date = date( 'Y-m-01', strtotime( 'last month' ) );
        $end_date   = date( 'Y-m-t', strtotime( 'last month' ) );
    } elseif ( 'year' === $current_range ) {
        $start_date = date( 'Y-01-01' );
        $end_date   = date( 'Y-12-31' );
    }

    // --- B. 建立篩選選單 (直接從資料庫找有用過的付款方式) ---
    // 這裡我們繞過 API，直接問資料庫：「有哪些付款方式被使用過？」
    $gateways_sql = "
        SELECT DISTINCT pm.meta_value as slug, 
        (SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = pm.post_id AND meta_key = '_payment_method_title' LIMIT 1) as title
        FROM {$wpdb->postmeta} pm
        WHERE pm.meta_key = '_payment_method' AND pm.meta_value != ''
    ";
    $used_gateways = $wpdb->get_results( $gateways_sql );

    // --- C. 主報表查詢 (撈取每日數據) ---
    $filter_sql = $selected_gateway ? "AND pm_method.meta_value = '{$selected_gateway}'" : "";
    
    $sql = "
        SELECT 
            DATE(p.post_date) as date,
            COUNT(p.ID) as order_count,
            SUM(pm_total.meta_value) as total_sales
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm_method ON p.ID = pm_method.post_id AND pm_method.meta_key = '_payment_method'
        LEFT JOIN {$wpdb->postmeta} pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
        WHERE p.post_type = 'shop_order'
        AND p.post_status IN ('wc-completed', 'wc-processing', 'wc-on-hold')
        AND p.post_date >= %s AND p.post_date <= %s
        {$filter_sql}
        GROUP BY DATE(p.post_date)
        ORDER BY date ASC
    ";
    
    $results = $wpdb->get_results( $wpdb->prepare( $sql, $start_date . ' 00:00:00', $end_date . ' 23:59:59' ) );

    // --- D. 資料整理 (Chart.js 格式) ---
    $chart_labels = [];
    $chart_data = [];
    $total_sales = 0;
    $total_orders = 0;

    $period = new DatePeriod( new DateTime($start_date), new DateInterval('P1D'), (new DateTime($end_date))->modify('+1 day') );
    $data_map = [];
    if($results) {
        foreach($results as $row) {
            $data_map[$row->date] = $row;
            $total_sales += $row->total_sales;
            $total_orders += $row->order_count;
        }
    }

    foreach ($period as $dt) {
        $date_str = $dt->format("Y-m-d");
        $chart_labels[] = $date_str;
        $val = isset($data_map[$date_str]) ? $data_map[$date_str]->total_sales : 0;
        $chart_data[] = $val;
    }
    
    // 找出目前顯示的付款方式名稱
    $current_gateway_name = '所有付款方式';
    if($selected_gateway && $used_gateways) {
        foreach($used_gateways as $gw) {
            if($gw->slug == $selected_gateway) {
                $current_gateway_name = $gw->title ? $gw->title : $gw->slug;
                break;
            }
        }
    }

    // --- E. 輸出前端 (Chart.js + 原生 UI) ---
    echo '<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>';
    ?>

    <div id="poststuff" class="woocommerce-reports-wide">
        
        <div class="postbox" style="margin-bottom: 10px; border-bottom: 0; box-shadow: none; background: transparent;">
             <form method="get" action="" id="mainform">
                <input type="hidden" name="page" value="wc-reports" />
                <input type="hidden" name="tab" value="orders" />
                <input type="hidden" name="report" value="sales_by_gateway" />
                
                <div class="stats_range">
                    <ul>
                        <?php
                        $ranges = array( 'year' => '年', 'last_month' => '上個月', 'month' => '本月', '7day' => '過去七日' );
                        foreach ( $ranges as $key => $label ) {
                            $class = $current_range === $key ? 'current' : '';
                            echo '<li><a href="' . esc_url( add_query_arg( array( 'range' => $key, 'start_date' => '', 'end_date' => '' ) ) ) . '" class="' . $class . '">' . $label . '</a></li>';
                        }
                        ?>
                        <li class="custom">
                            自訂: 
                            <input type="text" size="11" placeholder="yyyy-mm-dd" value="<?php echo esc_attr( $start_date ); ?>" name="start_date" class="range_datepicker" />
                            <span>&ndash;</span>
                            <input type="text" size="11" placeholder="yyyy-mm-dd" value="<?php echo esc_attr( $end_date ); ?>" name="end_date" class="range_datepicker" />
                            
                            <select name="filter_gateway" style="vertical-align: top; height: 28px; line-height: 28px; margin-left: 10px;">
                                <option value="">(所有付款方式)</option>
                                <?php if($used_gateways): foreach ( $used_gateways as $gw ) : 
                                    $gw_name = $gw->title ? $gw->title : $gw->slug;
                                ?>
                                    <option value="<?php echo esc_attr( $gw->slug ); ?>" <?php selected( $selected_gateway, $gw->slug ); ?>>
                                        <?php echo esc_html( $gw_name ); ?>
                                    </option>
                                <?php endforeach; endif; ?>
                            </select>

                            <button type="submit" class="button">送出</button>
                        </li>
                    </ul>
                </div>
            </form>
        </div>

        <div class="postbox">
            <div class="inside">
                <div class="chart-container" style="display:flex;">
                    <div class="chart-sidebar" style="width: 220px; border-right: 1px solid #eee; margin-right: 20px; padding-right:20px;">
                        <ul class="chart-legend">
                            <li style="border-left: 5px solid #0073aa; padding: 10px; margin-bottom: 10px; background: #fff; border-color: #0073aa;">
                                <strong style="display:block; font-size:18px;"><?php echo wc_price( $total_sales ); ?></strong>
                                <span style="color: #777; font-size:12px;">期間總銷售額</span>
                            </li>
                            <li style="border-left: 5px solid #e5e5e5; padding: 10px; margin-bottom: 10px; background: #fff; border-color: #e5e5e5;">
                                <strong style="display:block; font-size:18px;"><?php echo number_format_i18n( $total_orders ); ?></strong>
                                <span style="color: #777; font-size:12px;">期間訂單量</span>
                            </li>
                             <li style="border-left: 5px solid #ffba00; padding: 10px; background: #fff; border-color: #ffba00;">
                                <strong style="display:block; font-size:14px;"><?php echo esc_html($current_gateway_name); ?></strong>
                                <span style="color: #777; font-size:12px;">目前檢視</span>
                            </li>
                        </ul>
                    </div>

                    <div class="chart-placeholder main" style="flex:1; height: 400px; position: relative;">
                        <canvas id="gatewaySalesChart"></canvas>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($){
        var ctx = document.getElementById('gatewaySalesChart').getContext('2d');
        var chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode( $chart_labels ); ?>,
                datasets: [{
                    label: '銷售額',
                    data: <?php echo json_encode( $chart_data ); ?>,
                    borderColor: '#0073aa', 
                    backgroundColor: 'rgba(0, 115, 170, 0.15)',
                    borderWidth: 2,
                    pointRadius: 4,
                    pointBackgroundColor: '#fff',
                    pointBorderColor: '#0073aa',
                    fill: true,
                    tension: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        callbacks: {
                            label: function(context) {
                                return context.parsed.y.toLocaleString();
                            }
                        }
                    }
                },
                scales: {
                    y: { beginAtZero: true, grid: { color: '#eee' } },
                    x: { grid: { display: false } }
                }
            }
        });
    });
    </script>
    <?php
}
