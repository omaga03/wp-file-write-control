<?php
/*
Plugin Name: WP File Write Control (Security Dashboard)
Description: ระบบความปลอดภัยไฟล์ + API Secure + AJAX (Beautiful Full Width Bar UI)
Version: 7.1.8
Author: IT Admin+RDI Omaga
*/

if (!defined('ABSPATH')) {
    exit;
}

register_uninstall_hook(__FILE__, ['WP_File_Write_Control', 'uninstall_cleanup']);

class WP_File_Write_Control
{
    const OPTION_KEY = 'wfwc_state';
    const SETTINGS_KEY = 'wfwc_settings';
    const LOG_KEY = 'wfwc_activity_logs';
    const NONCE_ACTION = 'wfwc_nonce_action';
    const CRON_HOOK = 'wfwc_auto_disable';
    const MENU_SLUG = 'wfwc-control';

    public function __construct()
    {
        if (is_admin()) {
            add_action('admin_init', [$this, 'check_auto_disable']);
            add_action('admin_head', [$this, 'print_admin_styles']);
            add_action('admin_init', [$this, 'restrict_admin_pages']);

            add_action('wp_dashboard_setup', [$this, 'dashboard_widget']);
            add_action('admin_notices', [$this, 'render_admin_notices']);
            add_action('admin_menu', [$this, 'admin_menu']);

            add_action('admin_post_wfwc_action', [$this, 'handle_action']);
            add_action('admin_post_wfwc_save_settings', [$this, 'handle_save_settings']);
            add_action('admin_post_wfwc_test_email', [$this, 'handle_test_email']);

            add_action('add_meta_boxes', [$this, 'add_meta_boxes']);
            add_action('wp_ajax_wfwc_toggle_upload', [$this, 'ajax_toggle_upload']);

            // Server Hardening
            add_action('admin_init', [$this, 'harden_upload_folder']);
        }

        add_filter('user_has_cap', [$this, 'block_caps'], 10, 1);
        add_filter('wp_handle_upload_prefilter', [$this, 'block_file_uploads']);
        add_action(self::CRON_HOOK, [$this, 'auto_disable']);

        // Safety: Intercept upgrades if locked
        add_filter('upgrader_pre_install', [$this, 'prevent_update_if_locked'], 10, 2);

        // API Hooks
        add_filter('rest_pre_dispatch', [$this, 'api_temp_unlock'], 10, 3);
        add_action('shutdown', [$this, 'api_temp_lock']);

        // GitHub Updater
        if (is_admin()) {
            $this->init_github_updater();
        }
    }

    public function init_github_updater()
    {
        require_once plugin_dir_path(__FILE__) . 'includes/class-wfwc-github-updater.php';
        if (class_exists('WFWC_GitHub_Updater')) {
            // Specify your repo details here
            new WFWC_GitHub_Updater(
                __FILE__,
                'omaga03',
                'wp-file-write-control'
            );
        }
    }

    /* =========================================
     * [CORE] CSS & JS & BLOCKING LOGIC
     * ========================================= */
    public function print_admin_styles()
    {
        $s = $this->state();
        $ttl_min = floor($this->get_timeout_seconds() / 60);

        echo "<script>
            var wfwc_vars = " . json_encode([
                'upload_status' => $s['upload'],
                'text_on' => "🔒 ปิดทันที",
                'text_off' => "🔓 เปิด $ttl_min นาที",
                'label_on' => "เปิด (Allowed)",
                'label_off' => "ปิด (Locked)"
            ]) . ";
        </script>";
        ?>
        <style>
            * {
                box-sizing: border-box;
            }

            /* --- Base Styles --- */
            .wfwc-wrapper {
                background: #f8f9fa;
                padding: 20px 0;
            }

            .wfwc-container {
                max-width: 1200px;
                margin: 0 auto;
                padding: 0 20px;
            }

            /* Buttons */
            .wfwc-btn-open {
                background-color: #4f46e5 !important;
                color: white !important;
                border: 1px solid #4f46e5 !important;
            }

            .wfwc-btn-open:hover {
                background-color: #4338ca !important;
                border-color: #4338ca !important;
                transform: translateY(-1px);
                box-shadow: 0 4px 8px rgba(79, 70, 229, 0.3) !important;
            }

            .wfwc-btn-close {
                background-color: #dc2626 !important;
                color: white !important;
                border: 1px solid #dc2626 !important;
            }

            .wfwc-btn-close:hover {
                background-color: #b91c1c !important;
                border-color: #b91c1c !important;
                transform: translateY(-1px);
                box-shadow: 0 4px 8px rgba(220, 38, 38, 0.3) !important;
            }

            /* Switch */
            .wfwc-switch-container {
                display: flex;
                align-items: center;
                gap: 8px;
                cursor: pointer;
                user-select: none;
                position: relative;
            }

            .wfwc-switch-input {
                position: absolute !important;
                opacity: 0 !important;
                width: 0 !important;
                height: 0 !important;
                margin: 0 !important;
                pointer-events: none;
            }

            .wfwc-switch-track {
                position: relative;
                width: 40px;
                height: 22px;
                background-color: #e2e8f0;
                border-radius: 20px;
                transition: all 0.3s ease;
                border: 1px solid #cbd5e1;
                box-shadow: inset 0 1px 2px rgba(0, 0, 0, 0.05);
            }

            .wfwc-switch-knob {
                position: absolute;
                top: 2px;
                left: 2px;
                width: 16px;
                height: 16px;
                background-color: white;
                border-radius: 50%;
                transition: all 0.3s cubic-bezier(0.4, 0.0, 0.2, 1);
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
            }

            .wfwc-switch-input:checked+.wfwc-switch-track {
                background-color: #f59e0b;
                border-color: #d97706;
            }

            .wfwc-switch-input:checked+.wfwc-switch-track .wfwc-switch-knob {
                transform: translateX(18px);
            }

            .wfwc-switch-input:disabled+.wfwc-switch-track {
                opacity: 0.6;
                cursor: not-allowed;
                filter: grayscale(0.5);
            }

            .wfwc-switch-label {
                font-size: 12px;
                font-weight: 600;
                color: #64748b;
                margin-top: 1px;
            }

            .wfwc-switch-container:hover .wfwc-switch-label {
                color: #d97706;
            }

            /* Cards Grid */
            .wfwc-cards {
                display: grid;
                grid-template-columns: repeat(3, minmax(0, 1fr));
                /* 1 แถว 3 คอลัมน์ - ป้องกันล้น */
                gap: 15px;
                margin-bottom: 40px;
                align-items: stretch;
                width: 100%;
                box-sizing: border-box;
            }

            .wfwc-card {
                border-radius: 12px;
                padding: 18px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
                border: 2px solid rgba(0, 0, 0, 0.08);
                position: relative;
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                display: flex;
                flex-direction: column;
                height: 100%;
                box-sizing: border-box;
                overflow: hidden;
            }

            .wfwc-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
            }

            .wfwc-card.active {
                background: linear-gradient(135deg, #dcfce7 0%, #bbf7d0 100%) !important;
                border-color: #86efac !important;
                box-shadow: 0 2px 8px rgba(34, 197, 94, 0.15);
            }

            .wfwc-card.active:hover {
                box-shadow: 0 4px 16px rgba(34, 197, 94, 0.25);
            }

            .wfwc-card.inactive {
                background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%) !important;
                border-color: #fca5a5 !important;
                box-shadow: 0 2px 8px rgba(239, 68, 68, 0.15);
            }

            .wfwc-card.inactive:hover {
                box-shadow: 0 4px 16px rgba(239, 68, 68, 0.25);
            }

            .wfwc-card-top {
                display: flex;
                justify-content: space-between;
                align-items: start;
                margin-bottom: 12px;
            }

            .wfwc-card-icon {
                font-size: 32px;
                width: 40px;
                height: 40px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: rgba(255, 255, 255, 0.8);
                border-radius: 10px;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                transition: all 0.3s ease;
            }

            .wfwc-card.active .wfwc-card-icon {
                background: rgba(255, 255, 255, 0.9);
                box-shadow: 0 2px 6px rgba(34, 197, 94, 0.2);
            }

            .wfwc-card.inactive .wfwc-card-icon {
                background: rgba(255, 255, 255, 0.9);
                box-shadow: 0 2px 6px rgba(239, 68, 68, 0.2);
            }

            .wfwc-card-title {
                font-size: 15px;
                font-weight: 600;
                margin: 0 0 12px 0;
                color: #333;
            }

            .wfwc-timer {
                font-size: 12px;
                color: #b91c1c;
                padding: 8px;
                background: rgba(255, 255, 255, 0.6);
                border-radius: 6px;
                font-weight: 600;
                margin-top: 10px;
                text-align: center;
            }

            .wfwc-btn {
                width: 100%;
                padding: 12px;
                border: none;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                display: block;
                text-align: center;
                font-size: 14px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                text-decoration: none;
                margin-top: auto;
            }

            .wfwc-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 6px 12px rgba(0, 0, 0, 0.15);
            }

            .wfwc-btn:active {
                transform: translateY(0);
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }

            .wfwc-status-pill {
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 13px;
                font-weight: bold;
                color: white;
                display: inline-block;
                box-shadow: 0 2px 6px rgba(0, 0, 0, 0.15);
                transition: all 0.3s ease;
            }

            .wfwc-status-pill.status-open {
                background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
            }

            .wfwc-status-pill.status-closed {
                background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%);
            }

            /* Single-line control bar (post-new, page, gallery, etc.) */
            #wfwc-mb-box {
                display: flex;
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                padding: 12px 20px;
                box-sizing: border-box;
                border-radius: 12px;
                border-left-width: 6px;
                border-left-style: solid;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
                transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            }

            #wfwc-mb-box:hover {
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.12);
            }

            .wfwc-info-group {
                display: flex;
                align-items: center;
                gap: 12px;
            }

            .wfwc-info-icon-wrapper {
                background: rgba(255, 255, 255, 0.85);
                border-radius: 10px;
                width: 40px;
                height: 40px;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
                transition: all 0.3s ease;
            }

            #wfwc-mb-box:hover .wfwc-info-icon-wrapper {
                transform: scale(1.05);
                box-shadow: 0 3px 6px rgba(0, 0, 0, 0.15);
            }

            .wfwc-action-group {
                display: flex;
                align-items: center;
                gap: 16px;
                margin-left: auto;
            }

            /* Settings & Table */
            .wfwc-settings-box {
                background: white;
                padding: 30px;
                border-radius: 12px;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
                border: 1px solid #e5e7eb;
            }

            .wfwc-section-title {
                font-size: 18px;
                font-weight: 600;
                margin: 40px 0 25px 0;
                border-bottom: 2px solid #e8e8e8;
                padding-bottom: 15px;
            }

            .wfwc-form-group {
                margin-bottom: 25px;
            }

            .wfwc-form-header {
                display: flex;
                align-items: center;
                gap: 10px;
                margin-bottom: 8px;
            }

            .wfwc-form-control {
                width: 100%;
                padding: 10px;
                border: 1px solid #d1d5db;
                border-radius: 6px;
            }

            .wfwc-table-container {
                background: white;
                border-radius: 12px;
                overflow: hidden;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
                border: 1px solid #e8e8e8;
            }

            .wfwc-table {
                width: 100%;
                border-collapse: collapse;
            }

            .wfwc-table th {
                background: #f3f4f6;
                padding: 12px;
                text-align: left;
                font-size: 12px;
                color: #4b5563;
            }

            .wfwc-table td {
                padding: 12px;
                border-bottom: 1px solid #f3f4f6;
                font-size: 13px;
            }

            .wfwc-header {
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                padding: 30px;
                border-radius: 12px;
                margin-bottom: 30px;
                box-shadow: 0 8px 20px rgba(102, 126, 234, 0.35);
                position: relative;
                overflow: hidden;
            }

            .wfwc-header::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: linear-gradient(135deg, rgba(255, 255, 255, 0.1) 0%, rgba(255, 255, 255, 0) 100%);
                pointer-events: none;
            }

            .wfwc-header h1 {
                margin: 0 0 5px 0;
                font-size: 28px;
                color: white;
            }

            .wfwc-email-alert {
                background: rgba(255, 255, 255, 0.15);
                display: inline-flex;
                align-items: center;
                gap: 8px;
                padding: 6px 12px;
                border-radius: 6px;
                margin-top: 15px;
                font-size: 13px;
                color: #fff;
            }

            .wfwc-loading-icon {
                display: inline-block;
                animation: wfwc-spin 1s linear infinite;
                margin-right: 8px;
                font-size: 20px;
                width: 20px;
                height: 20px;
            }

            .wfwc-btn-saving {
                opacity: 0.8 !important;
                cursor: wait !important;
                pointer-events: none;
                position: relative;
                display: inline-flex !important;
                align-items: center !important;
                justify-content: center !important;
                white-space: nowrap !important;
                min-width: 160px;
            }

            @keyframes wfwc-spin {
                0% {
                    transform: rotate(0deg);
                }

                100% {
                    transform: rotate(360deg);
                }
            }

            /* =================================================================
                                                                                                                                                                                                                                                     * [NEW] BEAUTIFUL FULL WIDTH BAR (Media Edit)
                                                                                                                                                                                                                                                     * ================================================================= */
            #wfwc-custom-media-bar {
                display: flex;
                width: 100%;
                flex-basis: 100%;
                margin-bottom: 25px;
                box-sizing: border-box;
            }

            #wfwc-custom-media-bar #wfwc-mb-box {
                padding: 12px 24px !important;
                margin: 0 !important;
                width: 100% !important;
                box-sizing: border-box !important;

                border-left-width: 6px !important;
                border-top: 1px solid #e2e8f0;
                border-right: 1px solid #e2e8f0;
                border-bottom: 1px solid #e2e8f0;
                border-radius: 8px !important;
                box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03) !important;
                background: #fff;
            }

            /* --- Left Side: Info --- */

            .wfwc-info-text {
                display: flex;
                flex-direction: column;
            }

            .wfwc-info-title {
                font-size: 15px;
                font-weight: 700;
                line-height: 1.2;
                color: #1e293b;
                display: flex;
                align-items: center;
                gap: 8px;
            }

            .wfwc-info-subtitle {
                font-size: 12px;
                color: #64748b;
                margin-top: 2px;
            }

            /* --- Right Side: Actions --- */
            #wfwc-custom-media-bar .wfwc-action-group {
                display: flex !important;
                align-items: center !important;
                gap: 20px !important;
                padding-left: 20px;
                border-left: 1px solid #f1f5f9;
                /* Divider เส้นบางๆ */
            }

            /* Timer Badge Style */
            #wfwc-mb-timer {
                font-size: 12px !important;
                font-weight: 600 !important;
                padding: 4px 10px !important;
                border-radius: 20px !important;
                display: flex;
                align-items: center;
                gap: 5px;
                white-space: nowrap;
            }

            #wfwc-custom-media-bar .wfwc-btn {
                width: auto !important;
                min-width: 140px !important;
                margin: 0 !important;
                padding: 9px 24px !important;
                font-size: 14px !important;
                font-weight: 600 !important;
                border-radius: 6px !important;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1) !important;
                transition: transform 0.1s, box-shadow 0.2s;
            }

            #wfwc-custom-media-bar .wfwc-btn:active {
                transform: translateY(1px);
                box-shadow: none !important;
            }

            /* ปรับปุ่ม Switch ใน Bar */
            #wfwc-custom-media-bar .wfwc-switch-container {
                margin-bottom: 0 !important;
                background: #f8fafc;
                padding: 5px 10px 5px 5px;
                border-radius: 30px;
                border: 1px solid #e2e8f0;
            }

            /* Responsive สำหรับ Dashboard Widget - จอมือถือเท่านั้น */
            @media screen and (max-width: 600px) {
                .wfwc-cards {
                    grid-template-columns: 1fr;
                    gap: 15px;
                }

                .wfwc-card {
                    padding: 15px;
                }

                .wfwc-card-icon {
                    font-size: 28px;
                    width: 36px;
                    height: 36px;
                }
            }
        </style>
        <?php
        // [New] Visually disable update buttons if locked
        if (!$s['plugin']) {
            echo '<style>
                .plugins-php .update-message a.update-link, 
                .plugins-php .plugin-update-tr .update-link,
                .plugins-php .plugin-update-tr .button-link,
                .update-core-php #update-plugins-table .button:not(.wfwc-ajax-toggle) {
                    pointer-events: none !important;
                    opacity: 0.6 !important;
                    text-decoration: none !important;
                    cursor: not-allowed !important;
                    color: #a7aaad !important;
                }
                .update-core-php #update-plugins-table .check-column input {
                    display: none !important;
                }
                .plugins-php .update-message:after {
                    content: " (Unlock to Update)";
                    font-size: smaller;
                    color: #d63638;
                }
            </style>';
        }
        if (!$s['theme']) {
            echo '<style>
                .themes-php .theme-update, 
                .themes-php .update-message,
                .update-core-php #update-themes-table .button:not(.wfwc-ajax-toggle) {
                    display: block !important; /* Ensure msg is shown */
                }
                .themes-php .update-message a,
                .themes-php .theme-update a,
                .update-core-php #update-themes-table .button:not(.wfwc-ajax-toggle) {
                    pointer-events: none !important;
                    opacity: 0.6 !important;
                    cursor: not-allowed !important;
                    color: #a7aaad !important;
                }
                .update-core-php #update-themes-table .check-column input {
                    display: none !important;
                }
            </style>';
        }
        ?>
        <script>
            jQuery(document).ready(function ($) {

                // [1] Sidebar Post Edit: ย้ายไปปกติ
                if ($('#postimagediv').length > 0) {
                    $('#wfwc_upload_control .inside').contents().appendTo('#postimagediv .inside');
                    $('#postimagediv .inside #wfwc-mb-box').css({ 'margin-bottom': '15px', 'display': 'block' });
                    $('#wfwc_upload_control').hide();
                }

                // [2] Media Edit: ย้ายไปเป็นแถบยาว (Full Width Row)
                if ($('.cm-media-preview').length > 0) {
                    var $customWrapper = $('<div id="wfwc-custom-media-bar"></div>');
                    $customWrapper.insertBefore('.cm-media-preview');
                    $('#wfwc_upload_control .inside').contents().appendTo($customWrapper);
                    $('.cm-media-preview').parent().css('flex-wrap', 'wrap');
                    $('#wfwc_upload_control').hide();
                }

                // Effect ปุ่มบันทึก Settings
                $('.wfwc-settings-box form').on('submit', function () {
                    var btn = $(this).find('button[type="submit"]');
                    var originalWidth = btn.outerWidth();
                    btn.css('min-width', originalWidth);
                    btn.html('<span class="dashicons dashicons-update wfwc-loading-icon"></span> <span>กำลังบันทึก...</span>');
                    btn.addClass('wfwc-btn-saving');
                });

                // Toggle Action
                $(document).on('click', '.wfwc-ajax-toggle', function (e) {
                    e.preventDefault();
                    var btn = $(this);
                    var type = btn.attr('data-type') || 'upload';
                    var originalText = btn.text();
                    var allBtns = $(`.wfwc-ajax-toggle[data-type="${type}"]`);

                    allBtns.prop('disabled', true).css('opacity', 0.5).text('Working...');

                    $.post(ajaxurl, {
                        action: 'wfwc_toggle_upload',
                        wfwc_security: '<?php echo wp_create_nonce(self::NONCE_ACTION); ?>',
                        type: type
                    }, function (res) {
                        if (res.success) {
                            var status = res.data.status;
                            var timeoutLabel = res.data.timeout_label;
                            var expireTime = res.data.expire_time;
                            var newPerm = res.data.new_perm;

                            var pathName = window.location.pathname;
                            var isPostPage = pathName.indexOf('post-new.php') !== -1 || pathName.indexOf('post.php') !== -1;

                            if (isPostPage) {
                                updateAllUploadUI(status, timeoutLabel, expireTime, newPerm);
                                allBtns.prop('disabled', false).css('opacity', 1);
                            } else {
                                location.reload();
                            }
                        } else {
                            alert('Error: ' + (res.data || 'Unknown'));
                            allBtns.prop('disabled', false).css('opacity', 1).text(originalText);
                        }
                    }).fail(function () {
                        alert('Request Failed. Please try again.');
                        allBtns.prop('disabled', false).css('opacity', 1).text(originalText);
                    });
                });

                function updateAllUploadUI(status, timeoutLabel, expireTime, newPerm) {
                    var bg = status ? '#f0fdf4' : '#fef2f2'; // พื้นหลังอ่อนๆ สวยๆ
                    var border = status ? '#16a34a' : '#dc2626'; // สี Border หลัก
                    var color = status ? '#16a34a' : '#dc2626'; // สีตัวอักษรหลัก
                    var iconColor = status ? '#16a34a' : '#ef4444';

                    var txtStatusShort = status ? 'Allowed' : 'Locked';
                    var txtBtn = status ? '🔒 ปิดทันที' : '🔓 เปิด ' + timeoutLabel;

                    // Update Main Box
                    $('#wfwc-mb-box').css({ 'background': bg, 'border-left-color': border });

                    // Update Text & Icons
                    $('#wfwc-mb-status-title').text(txtStatusShort).css('color', color);
                    $('#wfwc-mb-perm').text('Permission: ' + newPerm);
                    $('.wfwc-info-icon-wrapper span').css('color', iconColor); // เปลี่ยนสีไอคอน

                    // Update Timer
                    if (status) {
                        $('#wfwc-mb-timer').html('<span class="dashicons dashicons-clock"></span> ' + expireTime)
                            .css({ 'display': 'flex', 'color': '#b91c1c', 'background': '#fee2e2' }).slideDown();
                    } else {
                        $('#wfwc-mb-timer').slideUp();
                    }

                    // Update Button
                    $(`.wfwc-ajax-toggle[data-type="upload"]`).text(txtBtn)
                        .removeClass('wfwc-btn-open wfwc-btn-close')
                        .addClass(status ? 'wfwc-btn-close' : 'wfwc-btn-open');
                }

                $('#wfwc-gen-key').click(function (e) { e.preventDefault(); var c = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#%^&*', p = ''; for (var i = 0; i < 32; i++)p += c.charAt(Math.floor(Math.random() * c.length)); $('input[name="wfwc_api_key"]').val('sk_' + p); });
                function tgl() {
                    var e = $('input[name="wfwc_enable_email"]').is(':checked');
                    var a = $('input[name="wfwc_enable_api"]').is(':checked');
                    $('input[name="wfwc_email"], #wfwc-btn-test').prop('disabled', !e).css('opacity', e ? 1 : 0.5);
                    $('input[name="wfwc_api_key"],textarea[name="wfwc_allowed_ips"]').prop('disabled', !a).css('opacity', a ? 1 : 0.5);
                    $('#wfwc-gen-key').prop('disabled', !a);
                }
                $('input[name="wfwc_enable_email"],input[name="wfwc_enable_api"]').change(tgl); tgl();

                if (typeof wp !== 'undefined' && wp.media) {
                    wp.media.view.Modal.prototype.on('open', function () {
                        setTimeout(function () {
                            $('.media-modal-content').addClass('has-wfwc-bar');
                            if ($('#wfwc-modal-bar').length === 0) {
                                $('.media-modal-content').prepend(wp.template('wfwc-modal-bar')());
                            }
                        }, 100);
                    });
                }
            });
        </script>
        <?php
        $s = $this->state();
        if (!$s['plugin'])
            echo '<style>.plugins-php .page-title-action, .upload-plugin, #plugin-information-footer { display: none !important; }</style>';
        if (!$s['theme'])
            echo '<style>.themes-php .page-title-action, .upload-theme { display: none !important; }</style>';
        if (!$s['upload'])
            echo '<style>.upload-php .page-title-action, .upload-php .add-new-h2, .media-new-php .page-title-action, #insert-media-button, .wp-media-buttons, .media-upload-form, a[href*="media-new.php"] { display: none !important; }</style>';
    }

    /* =========================================
     * [CORE] 1. Centralized Status Checker (Updated: Real Write Test)
     * ========================================= */
    private function get_target_info($type)
    {
        $dirs = [];
        $label = '';
        if ($type === 'upload') {
            $dirs = $this->upload_dirs();
            $label = 'Uploads';
        } elseif ($type === 'plugin') {
            $dirs = $this->plugin_dirs();
            $label = 'Plugins';
        } elseif ($type === 'theme') {
            $dirs = $this->theme_dirs();
            $label = 'Themes';
        }

        $path = isset($dirs[0]) ? $dirs[0] : '';

        if (!$path || !is_dir($path)) {
            return ['exists' => false];
        }

        clearstatcache(true, $path);
        $perm_num = substr(sprintf('%o', fileperms($path)), -3);

        $test_file = $path . '/.wfwc_check.tmp';
        $is_writable = false;

        if (@file_put_contents($test_file, 'test') !== false) {
            $is_writable = true;
            @unlink($test_file);
        } else {
            $is_writable = is_writable($path);
        }

        $s = $this->state();
        $ttl_min = floor($this->get_timeout_seconds() / 60);
        $expire_ts = isset($s['expire_' . $type]) ? $s['expire_' . $type] : 0;

        // Colors & Texts (Better Logic)
        $is_open = $is_writable;

        return [
            'exists' => true,
            'type' => $type,
            'label' => $label,
            'path' => $path,
            'perm' => $perm_num,
            'is_open' => $is_open,
            // สีพื้นหลังแบบ Soft
            'bg' => $is_open ? '#f0fdf4' : '#fef2f2',
            // สี Border/Text เข้ม
            'color' => $is_open ? '#16a34a' : '#dc2626',
            // สี Icon
            'icon_color' => $is_open ? '#16a34a' : '#ef4444',

            'btn_text' => $is_open ? '🔒 ปิดทันที' : "🔓 เปิด $ttl_min นาที",
            'btn_class' => $is_open ? 'wfwc-btn-close' : 'wfwc-btn-open',
            'status_title' => $is_open ? 'Allowed' : 'Locked',
            'timer_text' => ($is_open && $expire_ts > time()) ? date('H:i:s', $expire_ts) : ''
        ];
    }

    public function ajax_toggle_upload()
    {
        check_ajax_referer(self::NONCE_ACTION, 'wfwc_security');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Denied');
        }

        @set_time_limit(0);

        $type = sanitize_key($_POST['type'] ?? 'upload');

        $s = $this->state();

        $info_current = $this->get_target_info($type);
        $should_open = !$info_current['is_open'];
        $dirs = $this->get_dirs_by_type($type);

        if ($should_open) {
            // Always operate in directory-only mode (deep mode removed)
            $this->chmod_dirs($dirs, 0775);
            $s[$type] = true;
            $s['expire_' . $type] = time() + $this->get_timeout_seconds();

            if ($type == 'plugin' || $type == 'theme') {
                $this->chmod_dirs($this->upgrade_dirs(), 0775);
                @chmod(ABSPATH . '.htaccess', 0644);
            }
            $this->log_activity("เปิด $type");

        } else {
            $this->chmod_dirs($dirs, 0555);
            $s[$type] = false;
            $s['expire_' . $type] = null;

            if ($type != 'upload' && !$s['plugin'] && !$s['theme']) {
                $this->chmod_dirs($this->upgrade_dirs(), 0555);
                @chmod(ABSPATH . '.htaccess', 0444);
            }
            $this->log_activity("ปิด $type");
        }

        $this->save($s);

        usleep(500000);
        if (isset($dirs[0])) {
            clearstatcache(true, $dirs[0]);
        }
        $final_check = $this->get_target_info($type);

        if ($final_check['is_open'] !== $should_open) {
            if ($should_open)
                wp_send_json_error("⚠️ Server Busy: กรุณากด 'เปิด' ซ้ำอีกครั้ง");
            else
                wp_send_json_error("⚠️ System Locked: กรุณากด 'ปิด' ซ้ำอีกครั้ง");
        }

        wp_send_json_success([
            'status' => $final_check['is_open'],
            'new_perm' => $final_check['perm'],
            'timeout_label' => floor($this->get_timeout_seconds() / 60) . ' นาที',
            'expire_time' => $final_check['timer_text'],
            'btn_text' => $final_check['btn_text'],
            'btn_class' => $final_check['btn_class'],
            'color' => $final_check['color'],
            'bg' => $final_check['bg']
        ]);
    }

    private function get_dirs_by_type($type)
    {
        if ($type == 'upload')
            return $this->upload_dirs();
        if ($type == 'plugin')
            return $this->plugin_dirs();
        if ($type == 'theme')
            return $this->theme_dirs();
        return [];
    }

    /* =========================================
     * UI RENDERING
     * ========================================= */
    private function control_ui()
    {
        echo '<div class="wfwc-cards">';

        $s = $this->state();
        $settings = $this->get_settings();

        foreach (['upload', 'plugin', 'theme'] as $type) {
            $info = $this->get_target_info($type);
            if (!$info['exists'])
                continue;

            $pill_cls = $info['is_open'] ? 'status-open' : 'status-closed';
            // ใช้ Dashicons ให้เหมือนกันทุกส่วน
            $icon_class = ($type == 'upload') ? 'dashicons-upload' : (($type == 'plugin') ? 'dashicons-admin-plugins' : 'dashicons-admin-appearance');
            $icon_color = $info['icon_color'];

            // ใช้ Logic เดิมสำหรับหน้า Settings (Card UI)
            ?>
            <div id="wfwc-card-<?= $type ?>" class="wfwc-card <?= $info['is_open'] ? 'active' : 'inactive' ?>">
                <div class="wfwc-card-top">
                    <span class="wfwc-card-icon">
                        <span class="dashicons <?= $icon_class ?>"
                            style="font-size: 24px; width: 24px; height: 24px; color: <?= $icon_color ?>;"></span>
                    </span>
                    <span class="wfwc-status-pill <?= $pill_cls ?>"><?= $info['status_title'] ?> : <?= $info['perm'] ?></span>
                </div>

                <h3 class="wfwc-card-title"><?= $info['label'] ?></h3>

                <button class="wfwc-btn wfwc-ajax-toggle <?= $info['btn_class'] ?>" data-type="<?= $type ?>">
                    <?= $info['btn_text'] ?>
                </button>

                <?php if ($info['timer_text']): ?>
                    <div class="wfwc-timer">⏱️ Auto: <?= $info['timer_text'] ?></div>
                <?php endif; ?>
            </div>
            <?php
        }
        echo '</div>';
    }

    private function render_log_history($limit = 10, $is_widget = false)
    {
        $logs = array_slice(get_option(self::LOG_KEY, []), 0, $limit);
        if (!$is_widget)
            echo '<div class="wfwc-section-title">📜 Logs (Activity History)</div>';

        echo '<div class="wfwc-table-container"><table class="wfwc-table"><thead><tr><th>Time</th><th>User</th><th>Action</th>' . (!$is_widget ? '<th>Device / IP</th>' : '') . '</tr></thead><tbody>';

        foreach ($logs as $l) {
            $act = $l['action'];
            $device = isset($l['device']) ? $l['device'] : 'Unknown';
            $ip = isset($l['ip']) ? $l['ip'] : '-';
            $row_style = 'background-color: #ffffff;';
            $act_html = $act;

            if (strpos($act, 'เปิด') !== false || stripos($act, 'enable') !== false) {
                $row_style = 'background-color: #d1fae5;';
                $act_html = '<span style="color:#065f46; font-weight:bold;">✅ ' . $act . '</span>';
            } elseif (strpos($act, 'ปิด') !== false || stripos($act, 'disable') !== false || stripos($act, 'ban') !== false) {
                $row_style = 'background-color: #fee2e2;';
                $act_html = '<span style="color:#991b1b; font-weight:bold;">🔒 ' . $act . '</span>';
            } elseif (stripos($act, 'settings') !== false) {
                $row_style = 'background-color: #dbeafe;';
                $act_html = '<span style="color:#1e40af;">⚙️ ' . $act . '</span>';
            }

            echo "<tr style='$row_style'>
                    <td>{$l['time']}</td>
                    <td><strong>{$l['user']}</strong></td>
                    <td>{$act_html}</td>" .
                (!$is_widget ? "<td>
                        <div style='font-size:12px; font-weight:bold; color:#333;'>{$device}</div>
                        <div style='font-size:11px; color:#888; font-family:monospace;'>IP: {$ip}</div>
                    </td>" : '') .
                "</tr>";
        }
        echo '</tbody></table></div>';
    }

    private function audit_table()
    {
        $u = wp_upload_dir();
        $upload_path = $u['basedir'];
        clearstatcache(true);

        $paths = [
            'Root Path (/)' => ABSPATH,
            'wp-content' => WP_CONTENT_DIR,
            'wp-config.php' => ABSPATH . 'wp-config.php',
            '.htaccess' => ABSPATH . '.htaccess',
            'Uploads' => $upload_path,
            'Plugins' => WP_CONTENT_DIR . '/plugins',
            'Themes' => WP_CONTENT_DIR . '/themes',
            'Upgrade (Temp)' => WP_CONTENT_DIR . '/upgrade'
        ];

        echo '<div class="wfwc-table-container"><table class="wfwc-table"><thead><tr><th>Path / File</th><th>Perm</th><th>Status</th></tr></thead><tbody>';

        foreach ($paths as $name => $path):
            if (!file_exists($path)) {
                echo "<tr><td><strong>$name</strong></td><td colspan='2' style='color:#999;'>- Not Found -</td></tr>";
                continue;
            }

            $w = is_writable($path);
            $perm = substr(sprintf('%o', fileperms($path)), -3);
            $row_style = $w ? 'background-color: #fee2e2;' : 'background-color: #d1fae5;';
            $status_html = $w
                ? '<span style="color:#991b1b; font-weight:bold;">🔓 Writable (ความเสี่ยง)</span>'
                : '<span style="color:#065f46; font-weight:bold;">✓ Locked (ปลอดภัย)</span>';

            echo "<tr style='$row_style'>
                    <td>
                        <strong>" . esc_html($name) . "</strong><br>
                        <small style='color:#666; font-size:11px;'>" . esc_html($path) . "</small>
                    </td>
                    <td style='font-family:monospace; font-weight:bold;'>$perm</td>
                    <td>$status_html</td>
                  </tr>";
        endforeach;

        echo '</tbody></table></div>';
    }

    /* =========================================
     * HANDLERS & HELPERS
     * ========================================= */
    public function dashboard_widget()
    {
        wp_add_dashboard_widget('wfwc_widget', 'File Security', [$this, 'dashboard_widget_content']);
    }
    public function dashboard_widget_content()
    {
        echo '<div class="dashboard-widget-notice">';
        $this->control_ui();
        $this->render_log_history(5, true);
        echo '</div>';
    }
    public function admin_menu()
    {
        add_menu_page('File Write Control', 'File Write Control', 'manage_options', self::MENU_SLUG, [$this, 'admin_page'], 'dashicons-lock', 3);
    }

    public function admin_page()
    {
        $settings = $this->get_settings();
        ?>
        <div class="wfwc-wrapper">
            <div class="wfwc-container">

                <div class="wfwc-header">
                    <h1>🔐 File Write Control</h1>
                    <p>ระบบควบคุมความปลอดภัยและตรวจสอบสิทธิ์ไฟล์</p><?php if ($settings['enable_email']): ?>
                        <div class="wfwc-email-alert">🔔 Alert Email: <?= esc_html($settings['email']) ?></div><?php endif; ?>
                </div>

                <div class="wfwc-section-title">🎛️ Control Panel</div>
                <?php $this->control_ui(); ?>

                <div class="wfwc-section-title">🔍 Audit</div>
                <?php $this->audit_table(); ?>
                <?php $this->render_log_history(10); ?>

                <div class="wfwc-section-title">⚙️ Settings</div>
                <div class="wfwc-settings-box">
                    <form method="post" action="<?= admin_url('admin-post.php') ?>">
                        <input type="hidden" name="action" value="wfwc_save_settings">
                        <?php wp_nonce_field('wfwc_save_settings_nonce'); ?>

                        <div class="wfwc-form-group"
                            style="background:#f8fafc; padding:15px; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:20px;">
                            <div class="wfwc-form-header">
                                <label class="wfwc-switch">
                                    <input type="checkbox" name="wfwc_enable_email" value="1" <?php checked($settings['enable_email'], 1); ?>>
                                    <span class="slider"></span>
                                </label>
                                <label style="font-weight:bold; color:#334155;">Enable Email Notification</label>
                            </div>
                            <div style="margin-top:10px; margin-left:46px;">
                                <div style="font-size:12px; color:#64748b; margin-bottom:5px;">
                                    ระบุอีเมลผู้ดูแลระบบเพื่อรับแจ้งเตือนเมื่อมีการเปิดสิทธิ์ไฟล์</div>
                                <div style="display:flex; gap:10px;">
                                    <input type="email" name="wfwc_email" class="wfwc-form-control"
                                        value="<?= esc_attr($settings['email']) ?>" placeholder="admin@example.com">
                                    <button type="submit" id="wfwc-btn-test"
                                        formaction="<?= admin_url('admin-post.php?action=wfwc_test_email') ?>" class="button">✉️
                                        Test</button>
                                </div>
                            </div>
                        </div>

                        <div class="wfwc-form-group"
                            style="background:#f8fafc; padding:15px; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:20px;">
                            <div class="wfwc-form-header">
                                <span style="font-size:18px; margin-right:10px; color:#334155;">⏱️</span>
                                <label style="font-weight:bold; color:#334155;">Auto-Disable Timeout</label>
                            </div>
                            <div style="margin-top:5px; margin-left:38px;">
                                <div style="font-size:12px; color:#64748b; margin-bottom:5px;">ระยะเวลา (นาที)
                                    ที่ระบบจะทำการปิดสิทธิ์ไฟล์ให้อัตโนมัติเมื่อครบกำหนด</div>
                                <input type="number" name="wfwc_timeout" class="wfwc-form-control"
                                    value="<?= esc_attr($settings['timeout']) ?>" min="1" max="1440" style="max-width:150px;">
                            </div>
                        </div>

                        <div class="wfwc-form-group"
                            style="background:#f8fafc; padding:15px; border:1px solid #e2e8f0; border-radius:8px; margin-bottom:20px;">
                            <div class="wfwc-form-header">
                                <label class="wfwc-switch">
                                    <input type="checkbox" name="wfwc_enable_api" value="1" <?php checked($settings['enable_api'], 1); ?>>
                                    <span class="slider"></span>
                                </label>
                                <label style="font-weight:bold; color:#334155;">Enable API Access</label>
                            </div>

                            <div style="margin-top:15px; margin-left:46px;">
                                <div style="margin-bottom:15px;">
                                    <label
                                        style="font-size:12px; font-weight:bold; color:#475569; display:block; margin-bottom:4px;">
                                        Secret Key (Header: <code>X-WFWC-SECRET</code>)
                                    </label>
                                    <div style="display:flex; gap:10px;">
                                        <input type="text" name="wfwc_api_key" class="wfwc-form-control"
                                            value="<?= esc_attr($settings['api_key']) ?>" placeholder="Ex. sk_...">
                                        <button class="button" id="wfwc-gen-key">🎲 Gen</button>
                                    </div>
                                </div>

                                <div>
                                    <label
                                        style="font-size:12px; font-weight:bold; color:#475569; display:block; margin-bottom:4px;">
                                        Allowed IPs (Whitelist)
                                    </label>
                                    <textarea name="wfwc_allowed_ips" class="wfwc-form-control" rows="3"
                                        placeholder="192.168.1.1"><?= esc_textarea($settings['allowed_ips']) ?></textarea>
                                    <div style="font-size:11px; color:#9ca3af; margin-top:2px;">ระบุ IP ที่อนุญาตให้ใช้งาน API
                                        (บรรทัดละ 1 IP)</div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="wfwc-btn wfwc-btn-open" style="width:auto; padding: 12px 30px;">
                            💾 บันทึกการตั้งค่า
                        </button>
                    </form>
                </div>

            </div>
        </div>
        <?php
    }

    public function harden_upload_folder()
    {
        $upload_dir = $this->upload_dirs()[0];
        $htaccess_file = $upload_dir . '/.htaccess';
        $rules = "<FilesMatch \"\.(php|php5|php7|phtml|pl|py|jsp|asp|htm|html|shtml|sh|cgi)$\">\n    Order Allow,Deny\n    Deny from all\n</FilesMatch>\nOptions -ExecCGI";
        if (!file_exists($htaccess_file) || trim(file_get_contents($htaccess_file)) !== trim($rules)) {
            @file_put_contents($htaccess_file, $rules);
        }
    }

    public static function uninstall_cleanup()
    {
        delete_option('wfwc_state');
        delete_option('wfwc_settings');
        delete_option('wfwc_activity_logs');
        wp_clear_scheduled_hook('wfwc_auto_disable');
        $u = wp_upload_dir();
        $htaccess = $u['basedir'] . '/.htaccess';
        if (file_exists($htaccess)) {
            @unlink($htaccess);
        }
    }

    private function get_client_ip()
    {
        $headers = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_REAL_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip_array = explode(',', $_SERVER[$header]);
                $ip = trim($ip_array[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'Unknown');
    }

    private function get_settings()
    {
        $defaults = [
            'enable_email' => 0,
            'email' => get_option('admin_email'),
            'timeout' => 10,
            'enable_api' => 0,
            'api_key' => '',
            'allowed_ips' => '',
        ];
        return wp_parse_args(get_option(self::SETTINGS_KEY, []), $defaults);
    }

    private function get_timeout_seconds()
    {
        $s = $this->get_settings();
        return (intval($s['timeout']) > 0 ? intval($s['timeout']) : 10) * 60;
    }

    public function handle_action()
    {
        if (!current_user_can('manage_options'))
            wp_die('Denied');
        check_admin_referer(self::NONCE_ACTION);
        $s = $this->state();
        $act = sanitize_key($_POST['do'] ?? '');
        $ttl = $this->get_timeout_seconds();
        $now = time();
        if ($act == 'enable_upload') {
            $this->chmod_dirs($this->upload_dirs(), 0775);
            $s['upload'] = true;
            $s['expire_upload'] = $now + $ttl;
            $this->log_activity('เปิด Uploads');
        } elseif ($act == 'disable_upload') {
            $this->chmod_dirs($this->upload_dirs(), 0555);
            $s['upload'] = false;
            $s['expire_upload'] = null;
            $this->log_activity('ปิด Uploads');
        } elseif ($act == 'enable_plugin') {
            $this->chmod_dirs($this->plugin_dirs(), 0775);
            $this->chmod_dirs($this->upgrade_dirs(), 0775);
            $s['plugin'] = true;
            $s['expire_plugin'] = $now + $ttl;
            $this->log_activity('เปิด Plugins');
        } elseif ($act == 'disable_plugin') {
            $this->chmod_dirs($this->plugin_dirs(), 0555);
            $s['plugin'] = false;
            $s['expire_plugin'] = null;
            if (!$s['theme'])
                $this->chmod_dirs($this->upgrade_dirs(), 0555);
            $this->log_activity('ปิด Plugins');
        } elseif ($act == 'enable_theme') {
            $this->chmod_dirs($this->theme_dirs(), 0775);
            $this->chmod_dirs($this->upgrade_dirs(), 0775);
            $s['theme'] = true;
            $s['expire_theme'] = $now + $ttl;
            $this->log_activity('เปิด Themes');
        } elseif ($act == 'disable_theme') {
            $this->chmod_dirs($this->theme_dirs(), 0555);
            $s['theme'] = false;
            $s['expire_theme'] = null;
            if (!$s['plugin'])
                $this->chmod_dirs($this->upgrade_dirs(), 0555);
            $this->log_activity('ปิด Themes');
        }
        if ($s['upload'] || $s['plugin'] || $s['theme']) {
            if (!wp_next_scheduled(self::CRON_HOOK))
                wp_schedule_event(time(), 'every_minute', self::CRON_HOOK);
        } else {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
        $this->save($s);
        wp_safe_redirect(wp_get_referer());
        exit;
    }

    public function handle_save_settings()
    {
        if (!current_user_can('manage_options'))
            wp_die('Denied');
        check_admin_referer('wfwc_save_settings_nonce');

        $input = [
            'enable_email' => isset($_POST['wfwc_enable_email']) ? 1 : 0,
            'email' => sanitize_email($_POST['wfwc_email']),
            'timeout' => absint($_POST['wfwc_timeout']),
            'enable_api' => isset($_POST['wfwc_enable_api']) ? 1 : 0,
            'api_key' => sanitize_text_field($_POST['wfwc_api_key']),
            'allowed_ips' => sanitize_textarea_field($_POST['wfwc_allowed_ips'])
        ];

        update_option(self::SETTINGS_KEY, $input);
        $this->log_activity('Updated Settings');
        $this->harden_upload_folder();
        wp_safe_redirect(add_query_arg('settings-updated', 'true', wp_get_referer()));
        exit;
    }

    public function handle_test_email()
    {
        if (!current_user_can('manage_options'))
            wp_die('Denied');
        check_admin_referer('wfwc_save_settings_nonce');
        $settings = $this->get_settings();
        $status = 'invalid_email';
        if (is_email($settings['email'])) {
            $sent = wp_mail($settings['email'], "[WFWC] Test", "Email OK.");
            $status = $sent ? 'success' : 'failed';
        }
        wp_safe_redirect(add_query_arg('test-email', $status, wp_get_referer()));
        exit;
    }

    public function auto_disable()
    {
        $s = $this->state();
        $now = time();
        $chg = false;

        if ($s['upload'] && $s['expire_upload'] && $now > $s['expire_upload']) {
            $this->chmod_dirs($this->upload_dirs(), 0555);
            $s['upload'] = false;
            $s['expire_upload'] = null;
            $chg = true;
            $this->log_activity("Auto Disable Uploads");
        }

        if ($s['plugin'] && $s['expire_plugin'] && $now > $s['expire_plugin']) {
            $this->chmod_dirs($this->plugin_dirs(), 0555);
            $s['plugin'] = false;
            $s['expire_plugin'] = null;
            if (!$s['theme']) {
                $this->chmod_dirs($this->upgrade_dirs(), 0555);
            }
            $chg = true;
            $this->log_activity("Auto Disable Plugins");
        }

        if ($s['theme'] && $s['expire_theme'] && $now > $s['expire_theme']) {
            $this->chmod_dirs($this->theme_dirs(), 0555);
            $s['theme'] = false;
            $s['expire_theme'] = null;
            if (!$s['plugin']) {
                $this->chmod_dirs($this->upgrade_dirs(), 0555);
            }
            $chg = true;
            $this->log_activity("Auto Disable Themes");
        }

        if ($chg)
            $this->save($s);

        if (!$s['upload'] && !$s['plugin'] && !$s['theme'])
            wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function api_temp_unlock($result, $server, $request)
    {
        $route = $request->get_route();
        if (strpos($route, '/wp/v2/media') !== false && $request->get_method() === 'POST') {
            $user_ip = $this->get_client_ip();
            if (get_transient('wfwc_ban_' . $user_ip))
                return new WP_Error('wfwc_banned', 'IP Banned', ['status' => 403]);
            $settings = $this->get_settings();
            if (empty($settings['enable_api']) || empty($settings['api_key']))
                return $result;
            if (!current_user_can('upload_files'))
                return $result;
            if ($request->get_header('x-wfwc-secret') !== $settings['api_key']) {
                $fails = (int) get_transient('wfwc_fail_' . $user_ip);
                $fails++;
                set_transient('wfwc_fail_' . $user_ip, $fails, 300);
                if ($fails >= 5) {
                    set_transient('wfwc_ban_' . $user_ip, true, 3600);
                    $this->log_activity("SYSTEM BAN: IP $user_ip");
                }
                return $result;
            }
            if (!empty($settings['allowed_ips'])) {
                $allowed = array_filter(array_map('trim', explode("\n", $settings['allowed_ips'])));
                if (!empty($allowed) && !in_array($user_ip, $allowed))
                    return $result;
            }
            $is_safe = false;
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf'];
            if (!empty($_FILES)) {
                foreach ($_FILES as $file) {
                    if (empty($file['name']) || empty($file['tmp_name']))
                        continue;
                    $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
                    $ext = strtolower($check['ext'] ?? '');
                    if (!empty($ext) && in_array($ext, $allowed_extensions)) {
                        $is_safe = true;
                        break;
                    }
                }
            } else {
                $disp = $request->get_header('content-disposition');
                if ($disp && preg_match('/filename="(.+?)"/', $disp, $matches)) {
                    $ext = strtolower(pathinfo($matches[1], PATHINFO_EXTENSION));
                    if (!empty($ext) && in_array($ext, $allowed_extensions))
                        $is_safe = true;
                }
            }
            if (!$is_safe) {
                $this->log_activity("API Blocked: Unsafe File");
                return $result;
            }
            $this->chmod_dirs($this->upload_dirs(), 0775);
        }
        return $result;
    }

    public function api_temp_lock()
    {
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        $s = $this->state();

        if (!empty($s['upload']) && $s['upload'] === true) {
            return;
        }
        if (!empty($s['expire_upload']) && $s['expire_upload'] > time()) {
            return;
        }

        $dirs = $this->upload_dirs();
        if (is_dir($dirs[0])) {
            @chmod($dirs[0], 0555);
            clearstatcache(true, $dirs[0]);
        }
    }

    public function check_auto_disable()
    {
        $this->auto_disable();
    }

    public function prevent_update_if_locked($true, $hook_extra)
    {
        $s = $this->state();
        if (isset($hook_extra['plugin']) && !$s['plugin']) {
            return new WP_Error('wfwc_locked', '⚠️ <b>Safety Block:</b> Please UNLOCK "Plugins" in File Write Control before updating.');
        }
        if (isset($hook_extra['theme']) && !$s['theme']) {
            return new WP_Error('wfwc_locked', '⚠️ <b>Safety Block:</b> Please UNLOCK "Themes" in File Write Control before updating.');
        }
        return $true;
    }

    public function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function block_caps($caps)
    {
        $s = $this->state();
        if (!$s['plugin']) {
            // $caps['install_plugins'] = false; // Allow visibility (Add New button will appear but page is restricted)
            // $caps['update_plugins'] = false; // Allow visibility
            $caps['delete_plugins'] = false;
            $caps['edit_plugins'] = false;
        }
        if (!$s['theme']) {
            // $caps['install_themes'] = false; // Allow visibility
            // $caps['update_themes'] = false; // Allow visibility
            $caps['delete_themes'] = false;
            $caps['edit_themes'] = false;
        }
        return $caps;
    }

    public function block_file_uploads($file)
    {
        $s = $this->state();
        if (!$s['upload'])
            $file['error'] = 'File uploads disabled by Security.';
        return $file;
    }

    public function restrict_admin_pages()
    {
        $s = $this->state();
        global $pagenow;
        if (!$s['plugin'] && $pagenow == 'plugin-install.php')
            wp_die('Denied');
        if (!$s['theme'] && $pagenow == 'theme-install.php')
            wp_die('Denied');
        if (!$s['upload'] && $pagenow == 'media-new.php')
            wp_die('Denied');
    }

    private function log_activity($action)
    {
        $settings = $this->get_settings();
        $user = wp_get_current_user();
        $username = $user->exists() ? $user->user_login : 'System/API';
        $ip = $this->get_client_ip();
        $ua = sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Device');
        $device = 'Unknown';
        if (strpos($ua, 'Chrome') !== false)
            $device = 'Chrome';
        elseif (strpos($ua, 'Firefox') !== false)
            $device = 'Firefox';
        elseif (strpos($ua, 'Safari') !== false)
            $device = 'Safari';
        elseif (strpos($ua, 'Edge') !== false)
            $device = 'Edge';
        if (strpos($ua, 'Mobile') !== false)
            $device .= ' (Mobile)';
        elseif (strpos($ua, 'Win') !== false)
            $device .= ' (Windows)';
        elseif (strpos($ua, 'Mac') !== false)
            $device .= ' (Mac)';
        elseif (strpos($ua, 'Linux') !== false)
            $device .= ' (Linux)';

        $time = current_time('d/m/Y H:i:s');
        $entry = ['time' => $time, 'user' => $username, 'action' => $action, 'ip' => $ip, 'device' => $device, 'full_ua' => $ua];
        $logs = get_option(self::LOG_KEY, []);
        if (!is_array($logs))
            $logs = [];
        array_unshift($logs, $entry);
        if (count($logs) > 20)
            array_pop($logs);
        update_option(self::LOG_KEY, $logs);

        $is_closing = (strpos($action, 'ปิด') !== false || stripos($action, 'disable') !== false);
        $is_opening = (strpos($action, 'เปิด') !== false || stripos($action, 'enable') !== false || stripos($action, 'open') !== false);

        // ส่งอีเมลเมื่อเปิด (enable) และ Email Notification เปิดอยู่
        if (
            ($settings['enable_email'] == 1 || $settings['enable_email'] === true || $settings['enable_email'] === '1') &&
            is_email($settings['email']) &&
            strpos($action, 'API') === false &&
            $is_opening
        ) {
            $subject = "[Security] มีการแจ้งเตือน: {$action}";
            $message = "🔔 มีการเปลี่ยนแปลงสถานะความปลอดภัยไฟล์\n";
            $message .= "----------------------------------------\n";
            $message .= "📌 การกระทำ: {$action}\n";
            $message .= "📅 วันที่/เวลา: {$time}\n";
            $message .= "👤 ผู้ดำเนินการ: {$username}\n";
            $message .= "💻 อุปกรณ์: {$device}\n";
            $message .= "🌐 IP Address: {$ip}\n";
            $message .= "📝 User Agent: {$ua}\n";
            $message .= "----------------------------------------\n";
            $message .= "ตรวจสอบรายละเอียด: " . admin_url('admin.php?page=' . self::MENU_SLUG);

            wp_mail($settings['email'], $subject, $message);
        }
    }

    private function state()
    {
        return wp_parse_args(get_option(self::OPTION_KEY, []), ['upload' => false, 'plugin' => false, 'theme' => false, 'expire_upload' => null, 'expire_plugin' => null, 'expire_theme' => null]);
    }

    private function save($s)
    {
        update_option(self::OPTION_KEY, $s);
    }

    private function upload_dirs()
    {
        $u = wp_upload_dir();
        return [$u['basedir']];
    }

    private function plugin_dirs()
    {
        return [WP_CONTENT_DIR . '/plugins'];
    }

    private function theme_dirs()
    {
        return [WP_CONTENT_DIR . '/themes'];
    }

    private function upgrade_dirs()
    {
        return [WP_CONTENT_DIR . '/upgrade'];
    }

    private function chmod_dirs($dirs, $mode)
    {
        if (empty($dirs) || !is_array($dirs))
            return;

        foreach ($dirs as $dir) {
            if (!is_dir($dir))
                continue;

            @chmod($dir, $mode);

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $item) {
                    try {
                        if ($item->isDir()) {
                            @chmod($item->getPathname(), $mode);
                        }
                    } catch (Exception $e) {
                        continue;
                    }
                }
            } catch (Exception $e) {
            }

            @chmod($dir, $mode);
            clearstatcache(true, $dir);
        }
    }

    public function render_admin_notices()
    {
        global $pagenow;

        $targets = [];
        if ($pagenow === 'upload.php') {
            $targets[] = 'upload';
        } elseif ($pagenow === 'plugins.php') {
            $targets[] = 'plugin';
        } elseif ($pagenow === 'themes.php') {
            $targets[] = 'theme';
        } elseif ($pagenow === 'update-core.php') {
            // Show both Plugin and Theme controls on Update Core page
            $targets[] = 'plugin';
            $targets[] = 'theme';
        }

        if (!empty($targets)) {
            foreach ($targets as $t) {
                $info = $this->get_target_info($t);

                if ($info['exists']) {
                    echo "<div id='wfwc-notice-bar-{$t}' class='notice' style='background:{$info['bg']}; border-left: 5px solid {$info['color']}; display:flex; align-items:center; justify-content:space-between; padding:10px 20px; margin: 20px 0 0 0; box-shadow: 0 1px 1px rgba(0,0,0,.04); transition:0.3s;'>
                        
                        <div style='font-size:14px; color:#333; display:flex; align-items:center;'>
                            <strong>🔐 {$info['label']} Security:</strong> 
                            <span style='font-weight:bold; color:{$info['color']}; margin-left:5px;'>{$info['status_title']}</span>
                            <code style='background:#fff; padding:2px 6px; border-radius:4px; border:1px solid #ddd; margin-left:8px; font-size:11px;'>{$info['perm']}</code>
                        </div>

                        <div style='display:flex; align-items:center; gap:15px;'>
                            " . ($info['timer_text'] ? "<div class='wfwc-timer' style='margin:0; font-size:12px; padding:4px 8px; background:#fff; border:1px solid #ddd;'>⏱️ {$info['timer_text']}</div>" : "") . "
                            <button class='button wfwc-ajax-toggle {$info['btn_class']}' data-type='{$info['type']}'>
                                {$info['btn_text']}
                            </button>
                        </div>

                    </div>";
                }
            }
        }

        // [Move Bars] Logic for update-core.php
        if ($pagenow === 'update-core.php') {
            ?>
            <script>
                jQuery(document).ready(function ($) {

                    function injectToTable(barId, tableId) {
                        var bar = $('#' + barId);
                        var table = $('#' + tableId);

                        // Check if bar exists
                        if (bar.length) {
                            if (table.length) {
                                // Remove 'notice' class to prevent WP from moving it, and other conflicting classes
                                bar.removeClass('notice notice-info notice-error notice-success is-dismissible');

                                // Create new row
                                var newRow = $('<tr class="wfwc-control-row"><td colspan="100%" style="padding:0; border-bottom:1px solid #ccd0d4; background:#fff;"></td></tr>');

                                // Try to find thead to insert BEFORE the first header row (Select All)
                                var thead = table.find('thead');
                                if (thead.length) {
                                    thead.prepend(newRow);
                                } else {
                                    table.find('tbody').prepend(newRow);
                                }

                                // Move bar into the new cell
                                bar.css({
                                    'margin': '0',
                                    'box-shadow': 'none',
                                    'border': 'none',
                                    'border-radius': '0',
                                    'padding': '10px 15px',
                                    'width': 'auto',
                                    'max-width': 'none'
                                }).detach().appendTo(newRow.find('td'));
                            } else {
                                // If table does not exist (no updates), hide the bar
                                bar.hide();
                            }
                        }
                    }

                    // Run immediately and also on window load to be safe
                    injectToTable('wfwc-notice-bar-plugin', 'update-plugins-table');
                    injectToTable('wfwc-notice-bar-theme', 'update-themes-table');

                });
            </script>
            <?php
        }
    }

    public function add_meta_boxes()
    {
        $screens = ['post', 'page', 'attachment'];
        $args = ['public' => true];
        $all_types = get_post_types($args, 'names');
        foreach ($all_types as $pt) {
            if (substr($pt, -8) === '_gallery') {
                $screens[] = $pt;
            }
        }

        foreach (array_unique($screens) as $s) {
            add_meta_box('wfwc_upload_control', 'File Write Control', [$this, 'render_meta_box'], $s, 'side', 'high');
        }
    }

    public function render_meta_box($post)
    {
        $info = $this->get_target_info('upload');
        if (!$info['exists']) {
            echo '<div class="notice notice-error"><p>ไม่สามารถตรวจสอบสถานะ Upload directory ได้</p></div>';
            return;
        }

        // [BEAUTIFUL LAYOUT] 
        // ซ้าย: Icon + Title (Status)
        // ขวา: Group Action (Timer + Switch + Button)
        echo "<div id='wfwc-mb-box' style='background:{$info['bg']}; border-left-color:{$info['color']}; transition:0.3s;'>
            
            <div class='wfwc-info-group'>
                <div class='wfwc-info-icon-wrapper'>
                    <span class='dashicons dashicons-shield' style='font-size:20px; height:20px; width:20px; color:{$info['icon_color']};'></span>
                </div>
                
                <div class='wfwc-info-text'>
                    <div class='wfwc-info-title' id='wfwc-mb-status-title' style='color:{$info['color']};'>
                        {$info['status_title']}
                    </div>
                    <div class='wfwc-info-subtitle' id='wfwc-mb-perm'>
                        Permission: {$info['perm']}
                    </div>
                </div>
            </div>";

        echo "<div class='wfwc-action-group'>";

        // 1. Timer
        $dsp = ($info['is_open'] && $info['timer_text']) ? 'flex' : 'none';
        echo "<div id='wfwc-mb-timer' style='display:$dsp; font-size:12px; font-weight:600; color:#b91c1c; background:#fee2e2; padding:4px 10px; border-radius:20px; align-items:center; gap:5px; white-space:nowrap;'>
                <span class='dashicons dashicons-clock' style='font-size:14px; width:14px; height:14px;'></span> {$info['timer_text']}
              </div>";

        // 2. Button
        echo "<button class='button wfwc-ajax-toggle {$info['btn_class']}' data-type='upload'>
                {$info['btn_text']}
              </button>";

        echo "</div>"; // End .wfwc-action-group
        echo "</div>"; // End #wfwc-mb-box
    }

}

new WP_File_Write_Control();