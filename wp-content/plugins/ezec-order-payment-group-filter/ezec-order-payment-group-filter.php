<?php
/**
 * Plugin Name: EZEC Order Payment Group Filter
 * Description: 在 WooCommerce 訂單列表加入「線上 / 非線上付款」篩選。
 * Version: 1.0.0
 * Author: EZEC
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 依你目前站台實際 payment_method 代碼整理：
 * 線上：linepay-tw, ry_newebpay_credit, newebpay
 * 非線上：其餘全部都算（bacs / postal_manual / other / 空白...）
 */
function ezec_opgf_get_online_methods() {
	return array(
		'linepay-tw',
		'ry_newebpay_credit',
		'newebpay',
	);
}

function ezec_opgf_get_filter_value() {
	return isset( $_GET['payment_group'] ) ? sanitize_text_field( wp_unslash( $_GET['payment_group'] ) ) : '';
}

/**
 * HPOS 訂單列表：加下拉選單
 */
add_action( 'woocommerce_order_list_table_restrict_manage_orders', 'ezec_opgf_render_filter_hpos' );
function ezec_opgf_render_filter_hpos() {
	$value = ezec_opgf_get_filter_value();
	?>
	<select name="payment_group" id="dropdown_payment_group">
		<option value=""><?php echo esc_html__( '全部付款', 'ezec' ); ?></option>
		<option value="online" <?php selected( $value, 'online' ); ?>><?php echo esc_html__( '線上付款', 'ezec' ); ?></option>
		<option value="offline" <?php selected( $value, 'offline' ); ?>><?php echo esc_html__( '非線上付款', 'ezec' ); ?></option>
	</select>
	<?php
}

/**
 * 傳統訂單列表（非 HPOS）：加下拉選單
 */
add_action( 'restrict_manage_posts', 'ezec_opgf_render_filter_legacy' );
function ezec_opgf_render_filter_legacy( $post_type ) {
	if ( 'shop_order' !== $post_type ) {
		return;
	}

	$value = ezec_opgf_get_filter_value();
	?>
	<select name="payment_group" id="dropdown_payment_group">
		<option value=""><?php echo esc_html__( '全部付款', 'ezec' ); ?></option>
		<option value="online" <?php selected( $value, 'online' ); ?>><?php echo esc_html__( '線上付款', 'ezec' ); ?></option>
		<option value="offline" <?php selected( $value, 'offline' ); ?>><?php echo esc_html__( '非線上付款', 'ezec' ); ?></option>
	</select>
	<?php
}

/**
 * HPOS：把自訂 payment_group 轉成 WooCommerce 訂單查詢參數
 */
add_filter( 'woocommerce_order_query_args', 'ezec_opgf_handle_hpos_query_args' );
function ezec_opgf_handle_hpos_query_args( $query_args ) {
	if ( is_admin() && ! empty( $_GET['page'] ) && 'wc-orders' === $_GET['page'] ) {
		$payment_group  = ezec_opgf_get_filter_value();
		$online_methods = ezec_opgf_get_online_methods();

		if ( 'online' === $payment_group ) {
			if ( ! isset( $query_args['field_query'] ) || ! is_array( $query_args['field_query'] ) ) {
				$query_args['field_query'] = array();
			}

			$query_args['field_query'][] = array(
				'field'   => 'payment_method',
				'value'   => $online_methods,
				'compare' => 'IN',
			);
		} elseif ( 'offline' === $payment_group ) {
			if ( ! isset( $query_args['field_query'] ) || ! is_array( $query_args['field_query'] ) ) {
				$query_args['field_query'] = array();
			}

			$query_args['field_query'][] = array(
				'field'   => 'payment_method',
				'value'   => $online_methods,
				'compare' => 'NOT IN',
			);
		}
	}

	return $query_args;
}

/**
 * 傳統 orders（posts/postmeta）：把自訂 payment_group 轉成 meta_query
 */
add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', 'ezec_opgf_handle_legacy_query_args', 10, 2 );
function ezec_opgf_handle_legacy_query_args( $query, $query_vars ) {
	if ( empty( $query_vars['payment_group'] ) ) {
		return $query;
	}

	$payment_group  = sanitize_text_field( $query_vars['payment_group'] );
	$online_methods = ezec_opgf_get_online_methods();

	if ( ! isset( $query['meta_query'] ) || ! is_array( $query['meta_query'] ) ) {
		$query['meta_query'] = array();
	}

	if ( 'online' === $payment_group ) {
		$query['meta_query'][] = array(
			'key'     => '_payment_method',
			'value'   => $online_methods,
			'compare' => 'IN',
		);
	} elseif ( 'offline' === $payment_group ) {
		$query['meta_query'][] = array(
			'key'     => '_payment_method',
			'value'   => $online_methods,
			'compare' => 'NOT IN',
		);
	}

	return $query;
}

/**
 * 讓 payment_group 進入 WC_Order_Query 查詢變數
 */
add_filter( 'request', 'ezec_opgf_pass_payment_group_request_var' );
function ezec_opgf_pass_payment_group_request_var( $vars ) {
	if ( isset( $_GET['payment_group'] ) ) {
		$vars['payment_group'] = sanitize_text_field( wp_unslash( $_GET['payment_group'] ) );
	}
	return $vars;
}