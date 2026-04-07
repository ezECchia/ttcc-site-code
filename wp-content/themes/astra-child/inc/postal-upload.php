<?php
// 避免直接存取
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. 註冊選單
add_action('admin_menu', 'ttcc_add_postal_upload_submenu', 30);
function ttcc_add_postal_upload_submenu() {
    $hook = add_submenu_page(
        'woocommerce',
        '上傳郵政劃撥',
        '郵政劃撥上傳',
        'manage_woocommerce',
        'upload-postal',
        'ttcc_render_postal_upload_form'
    );

    // 只在進入這個頁面時，才載入底下的 JavaScript
    add_action( "admin_footer-$hook", 'ttcc_postal_upload_js' );
}

// 2. 渲染 HTML 表單 (不含 JS)
function ttcc_render_postal_upload_form() {
    ?>
    <div class="wrap">
        <h1>上傳郵政劃撥 Excel (建立訂單並寄發電子收據)</h1>
        
        <div class="notice notice-info inline">
            <p>
                ℹ️ <strong>說明：</strong><br>
                1. 系統將根據 Excel 內容自動建立訂單。<br>
                2. 若 Excel 中有填寫 <code>流水號</code>，將直接使用該號碼。<br>
                3. 若有填寫 <code>email</code>，系統會自動寄送收據 PDF；若無 Email 則只建立訂單。
            </p>
        </div>

        <p>
            📥 <a href="https://www.ttcc.org.tw/wp-content/uploads/2025/08/郵政劃撥範例.xlsx" class="button">下載 Excel 範例格式</a>
        </p>

        <form id="postal-upload-form" enctype="multipart/form-data" style="background:#fff; padding:20px; box-shadow:0 1px 3px rgba(0,0,0,.1); max-width:600px;">
            <table class="form-table">
                <tr>
                    <th scope="row"><label for="recipient_email">測試/備用 Email</label></th>
                    <td>
                        <input type="email" name="recipient_email" id="recipient_email" class="regular-text" value="cancercare@ttcc.org.tw">
                        <p class="description">此欄位目前僅供系統紀錄或除錯用，實際收據會優先寄給 Excel 內的 Email。</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="postal_excel">上傳 Excel 檔</label></th>
                    <td>
                        <input type="file" name="postal_excel" id="postal_excel" accept=".xlsx,.xls" required>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" id="submit-btn" class="button button-primary button-hero">開始匯入</button>
            </p>
        </form>

        <div id="postal-upload-result" style="margin-top: 20px; max-width:600px;"></div>
    </div>
    <?php
}

// 3. 獨立的 JavaScript 邏輯
function ttcc_postal_upload_js() {
    ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('postal-upload-form');
        const resultBox = document.getElementById('postal-upload-result');
        const submitBtn = document.getElementById('submit-btn');

        if (!form) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            // UI 鎖定
            submitBtn.disabled = true;
            submitBtn.innerText = '⏳ 處理中，請勿關閉視窗...';
            resultBox.innerHTML = '<div class="notice notice-warning"><p>檔案上傳與處理中，約需 10~30 秒，請稍候。</p></div>';

            const formData = new FormData(form);

            try {
                // n8n Webhook URL
                // const webhookUrl = 'https://hook.ezec.com.tw/webhook-test/4d572a7c-1335-4247-bb82-531bc91ce764';
                const webhookUrl = 'https://hook.ezec.com.tw/webhook/4d572a7c-1335-4247-bb82-531bc91ce764';
                
                const res = await fetch(webhookUrl, {
                    method: 'POST',
                    body: formData
                });
                
                const json = await res.json();

                if (json.status === 'ok' || json.success) {
                    const start = json.receipt_start || '無';
                    const end = json.receipt_end || '無';
                    const count = json.processed_count || '若干';

                    resultBox.innerHTML = `
                        <div class="notice notice-success">
                            <p><strong>✅ 匯入成功！</strong></p>
                            <ul>
                                <li>已處理訂單數：${count} 筆</li>
                                <li>匯入的流水號範圍：<strong>${start} ～ ${end}</strong></li>
                                <li>系統已自動針對有 Email 的捐款者寄出收據。</li>
                            </ul>
                        </div>`;
                } else {
                    throw new Error(json.message || 'Unknown error');
                }
            } catch (err) {
                console.error(err);
                resultBox.innerHTML = `<div class="notice notice-error"><p>❌ 處理失敗：${err.message}</p><p>請檢查 Excel 格式或聯繫管理員。</p></div>`;
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerText = '開始匯入';
            }
        });
    });
    </script>
    <?php
}

// 4. (選用) 禁止這些自動匯入的訂單發送 WooCommerce 預設的「新訂單/訂單完成」信件給客戶
// 避免客戶收到兩封信 (一封 WC 系統信，一封 n8n 收據)
add_filter( 'woocommerce_email_recipient_customer_completed_order', 'ttcc_disable_wc_email_for_postal_import', 10, 2 );
add_filter( 'woocommerce_email_recipient_customer_processing_order', 'ttcc_disable_wc_email_for_postal_import', 10, 2 );
// 如果有需要，也可以連同 "暫保留 (On-hold)" 的信件一起阻擋：
add_filter( 'woocommerce_email_recipient_customer_on_hold_order', 'ttcc_disable_wc_email_for_postal_import', 10, 2 );

function ttcc_disable_wc_email_for_postal_import( $recipient, $order ) {
    // 確保有訂單物件
    if ( ! $order ) return $recipient;

    // 1. 取得 _created_via 的 Meta 值 (對應 n8n 設定)
    $created_via = $order->get_meta( '_created_via' );

    // 2. 嚴謹判斷：只有當來源標記為 'system_import' 時，才阻擋寄信
    // 這樣就不會影響到一般前台下單，或是管理員手動新增的訂單
    if ( $created_via === 'system_import' ) {
        return ''; // 回傳空字串 = 不寄送
    }

    // 其他情況維持原樣
    return $recipient;
}
?>