<?php
/**
 * Plugin Name: Warranty Checker Form
 * Description: Provides a shortcode to display a warranty lookup form and handles AJAX requests.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Registers the warranty post type used for storing warranty data.
 */
function wcf_register_warranty_post_type() {
    register_post_type(
        'warranty_item',
        [
            'label' => __('Warranty Items', 'warranty-checker'),
            'public' => false,
            'show_ui' => true,
            'supports' => ['title'],
        ]
    );
}
add_action('init', 'wcf_register_warranty_post_type');

/**
 * Shortcode to render the warranty lookup form.
 */
function wcf_render_warranty_form($atts = []) {
    wp_enqueue_script('wcf-script');
    wp_enqueue_style('wcf-style');

    ob_start();
    ?>
    <form id="wcf-warranty-form" class="wcf-form" novalidate>
        <div class="wcf-field">
            <label for="wcf-serial"><?php esc_html_e('Serial number', 'warranty-checker'); ?></label>
            <input type="text" id="wcf-serial" name="serial" required placeholder="VD: SN123456" />
        </div>
        <div class="wcf-field">
            <label for="wcf-contact"><?php esc_html_e('Email or phone used for purchase', 'warranty-checker'); ?></label>
            <input type="text" id="wcf-contact" name="contact" required placeholder="ten@example.com" />
        </div>
        <?php wp_nonce_field('wcf_check_warranty', 'wcf_nonce'); ?>
        <button type="submit" class="wcf-submit"><?php esc_html_e('Check warranty', 'warranty-checker'); ?></button>
        <div id="wcf-message" class="wcf-message" aria-live="polite"></div>
    </form>
    <?php
    return ob_get_clean();
}
add_shortcode('warranty_check_form', 'wcf_render_warranty_form');

/**
 * Enqueue scripts and styles.
 */
function wcf_enqueue_assets() {
    wp_register_style(
        'wcf-style',
        plugins_url('assets/wcf-style.css', __FILE__),
        [],
        '1.0.0'
    );

    wp_register_script(
        'wcf-script',
        plugins_url('assets/wcf-script.js', __FILE__),
        ['jquery'],
        '1.0.0',
        true
    );

    wp_localize_script(
        'wcf-script',
        'wcfSettings',
        [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'successMessage' => __('Warranty lookup completed.', 'warranty-checker'),
            'errorMessage' => __('No warranty information was found. Please double-check your details.', 'warranty-checker'),
        ]
    );
}
add_action('wp_enqueue_scripts', 'wcf_enqueue_assets');

/**
 * Handle AJAX requests.
 */
function wcf_handle_warranty_lookup() {
    check_ajax_referer('wcf_check_warranty', 'nonce');

    $serial  = isset($_POST['serial']) ? sanitize_text_field(wp_unslash($_POST['serial'])) : '';
    $contact = isset($_POST['contact']) ? sanitize_text_field(wp_unslash($_POST['contact'])) : '';

    if (empty($serial) || empty($contact)) {
        wp_send_json_error([
            'message' => __('Serial number and contact information are required.', 'warranty-checker'),
        ]);
    }

    $query = new WP_Query(
        [
            'post_type'      => 'warranty_item',
            'posts_per_page' => 1,
            'meta_query'     => [
                'relation' => 'AND',
                [
                    'key'     => '_warranty_serial',
                    'value'   => $serial,
                    'compare' => '=',
                ],
                [
                    'key'     => '_warranty_contact',
                    'value'   => $contact,
                    'compare' => '=',
                ],
            ],
        ]
    );

    if (!$query->have_posts()) {
        wp_send_json_error([
            'message' => __('Không tìm thấy thông tin bảo hành. Vui lòng kiểm tra lại.', 'warranty-checker'),
        ]);
    }

    $post_id         = $query->posts[0]->ID;
    $purchase_date   = get_post_meta($post_id, '_warranty_purchase_date', true);
    $expiration_date = get_post_meta($post_id, '_warranty_expiration_date', true);
    $status          = get_post_meta($post_id, '_warranty_status', true);
    $notes           = get_post_meta($post_id, '_warranty_notes', true);

    wp_send_json_success(
        [
            'serial'          => $serial,
            'status'          => $status ?: __('Đang cập nhật', 'warranty-checker'),
            'purchaseDate'    => $purchase_date,
            'expirationDate'  => $expiration_date,
            'notes'           => $notes,
        ]
    );
}
add_action('wp_ajax_check_warranty', 'wcf_handle_warranty_lookup');
add_action('wp_ajax_nopriv_check_warranty', 'wcf_handle_warranty_lookup');

/**
 * Create default assets on plugin activation if they do not exist.
 */
function wcf_activate_plugin() {
    $assets_path = plugin_dir_path(__FILE__) . 'assets/';
    if (!file_exists($assets_path)) {
        wp_mkdir_p($assets_path);
    }

    $style_path = $assets_path . 'wcf-style.css';
    if (!file_exists($style_path)) {
        file_put_contents(
            $style_path,
            ".wcf-form{max-width:420px;margin:2rem auto;padding:1.5rem;border:1px solid #e2e8f0;border-radius:12px;background:#fff;box-shadow:0 10px 25px -15px rgba(15,23,42,.3)}\n"
            .".wcf-field{margin-bottom:1rem}\n"
            .".wcf-field label{display:block;font-weight:600;color:#1f2937;margin-bottom:.25rem}\n"
            .".wcf-field input{width:100%;padding:.65rem .75rem;border:1px solid #cbd5f5;border-radius:8px;font-size:1rem}\n"
            .".wcf-submit{background:#2563eb;color:#fff;border:none;padding:.75rem 1.5rem;border-radius:999px;font-weight:600;cursor:pointer;transition:background .2s}\n"
            .".wcf-submit:hover{background:#1d4ed8}\n"
            .".wcf-message{margin-top:1rem;font-size:.95rem}\n"
            .".wcf-message.wcf-success{color:#047857}\n"
            .".wcf-message.wcf-error{color:#dc2626}\n"
        );
    }

    $script_path = $assets_path . 'wcf-script.js';
    if (!file_exists($script_path)) {
        file_put_contents(
            $script_path,
            "(function($){$('#wcf-warranty-form').on('submit',function(event){event.preventDefault();var $form=$(this);var $message=$('#wcf-message');$message.removeClass('wcf-success wcf-error').text('');var data={action:'check_warranty',nonce:$form.find('input[name=\"wcf_nonce\"]').val(),serial:$('#wcf-serial').val(),contact:$('#wcf-contact').val()};$message.text('Đang kiểm tra...');$.post(wcfSettings.ajaxUrl,data).done(function(response){if(response.success){var info=response.data;var html='<p><strong>Serial:</strong> '+info.serial+'</p>'+'<p><strong>Trạng thái:</strong> '+info.status+'</p>';if(info.purchaseDate){html+='<p><strong>Ngày mua:</strong> '+info.purchaseDate+'</p>';}if(info.expirationDate){html+='<p><strong>Hạn bảo hành:</strong> '+info.expirationDate+'</p>';}if(info.notes){html+='<p><strong>Ghi chú:</strong> '+info.notes+'</p>';} $message.html(html).addClass('wcf-success');}else{$message.text(response.data.message || wcfSettings.errorMessage).addClass('wcf-error');}}).fail(function(){ $message.text('Có lỗi xảy ra, vui lòng thử lại sau.').addClass('wcf-error');});});})(jQuery);"
        );
    }
}
register_activation_hook(__FILE__, 'wcf_activate_plugin');

?>
