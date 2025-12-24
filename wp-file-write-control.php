<?php
/*
Plugin Name: WP File Write Control (Security Dashboard)
Description: ระบบความปลอดภัยไฟล์ + API Secure + AJAX
Version: 7.0.5
Author: IT Admin+RDI Omaga
*/

if (!defined('ABSPATH'))
    exit;

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
            // add_action('print_media_templates', [$this, 'media_modal_control']);

            // Server Hardening
            add_action('admin_init', [$this, 'harden_upload_folder']);
        }

        add_filter('user_has_cap', [$this, 'block_caps'], 10, 1);
        add_filter('wp_handle_upload_prefilter', [$this, 'block_file_uploads']);
        add_action(self::CRON_HOOK, [$this, 'auto_disable']);

        // API Hooks
        add_filter('rest_pre_dispatch', [$this, 'api_temp_unlock'], 10, 3);
        add_action('shutdown', [$this, 'api_temp_lock']);
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

            .wfwc-wrapper {
                background: #f8f9fa;
                padding: 20px 0;
            }

            .wfwc-container {
                max-width: 1200px;
                margin: 0 auto;
                padding: 0 20px;
            }

            /* --- COLOR UTILITIES (บังคับสีปุ่ม) --- */
            /* ปุ่มเปิด (สีน้ำเงิน) */
            .wfwc-btn-open {
                background-color: #4f46e5 !important;
                color: white !important;
                border: 1px solid #4f46e5 !important;
            }

            .wfwc-btn-open:hover {
                background-color: #4338ca !important;
            }

            /* ปุ่มปิด (สีแดง) */
            .wfwc-btn-close {
                background-color: #dc2626 !important;
                color: white !important;
                border: 1px solid #dc2626 !important;
            }

            .wfwc-btn-close:hover {
                background-color: #b91c1c !important;
            }

            /* ปรับแต่งปุ่มในแถบแจ้งเตือน (Notice) และ Meta Box */
            .wfwc-ajax-toggle {
                transition: 0.3s;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }

            /* Status Pills */
            .wfwc-status-pill {
                padding: 4px 12px;
                border-radius: 20px;
                font-size: 13px;
                font-weight: bold;
                color: white;
                display: inline-block;
                box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
            }

            .wfwc-status-pill.status-open {
                background: #16a34a;
            }

            .wfwc-status-pill.status-closed {
                background: #dc2626;
            }

            /* Card Styles */
            .wfwc-cards {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                gap: 20px;
                margin-bottom: 40px;
            }

            .wfwc-card {
                border-radius: 12px;
                padding: 20px;
                box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
                border: 1px solid rgba(0, 0, 0, 0.1);
                position: relative;
                transition: all 0.3s ease;
            }

            .wfwc-card.active {
                background: #dcfce7 !important;
                border-color: #86efac;
            }

            .wfwc-card.inactive {
                background: #fee2e2 !important;
                border-color: #fca5a5;
            }

            .wfwc-card-top {
                display: flex;
                justify-content: space-between;
                align-items: start;
                margin-bottom: 15px;
            }

            .wfwc-card-icon {
                font-size: 36px;
            }

            .wfwc-card-title {
                font-size: 16px;
                font-weight: 600;
                margin: 0 0 15px 0;
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

            /* Main Button Layout */
            .wfwc-btn {
                width: 100%;
                padding: 12px;
                border: none;
                border-radius: 8px;
                font-weight: 600;
                cursor: pointer;
                transition: 0.2s;
                display: block;
                text-align: center;
                font-size: 14px;
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                text-decoration: none;
            }

            /* Other Styles */
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

            .wfwc-switch {
                position: relative;
                display: inline-block;
                width: 36px;
                height: 20px;
            }

            .wfwc-switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }

            .slider {
                position: absolute;
                cursor: pointer;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background-color: #ccc;
                transition: .4s;
                border-radius: 20px;
            }

            .slider:before {
                position: absolute;
                content: "";
                height: 14px;
                width: 14px;
                left: 3px;
                bottom: 3px;
                background-color: white;
                transition: .4s;
                border-radius: 50%;
            }

            input:checked+.slider {
                background-color: #2196F3;
            }

            input:checked+.slider:before {
                transform: translateX(16px);
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
                box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
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

            .dashboard-widget-notice .wfwc-cards {
                grid-template-columns: 1fr 1fr 1fr !important;
                gap: 5px;
                margin-bottom: 10px;
            }

            .dashboard-widget-notice .wfwc-card {
                padding: 10px;
            }

            .dashboard-widget-notice .wfwc-btn {
                padding: 5px;
                font-size: 11px;
            }

            .wfwc-modal-bar {
                padding: 0 20px;
                display: flex;
                align-items: center;
                justify-content: space-between;
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 50px;
                z-index: 9999;
                box-sizing: border-box;
                border-bottom: 1px solid #ddd;
            }

            .media-modal-content.has-wfwc-bar .media-frame-title {
                top: 50px !important;
            }

            .media-modal-content.has-wfwc-bar .media-frame-router {
                top: 100px !important;
            }

            .media-modal-content.has-wfwc-bar .media-frame-content {
                top: 134px !important;
            }

            #wfwc-notice-bar {
                transition: 0.3s;
                border-left-width: 5px !important;
            }
        </style>
        <script>
            jQuery(document).ready(function ($) {

                // [1] ย้ายการ์ดเปิด-ปิดไปไว้ในช่อง Featured Image ทันทีที่โหลดหน้า
                if ($('#postimagediv').length > 0) {
                    // ดึงเนื้อหาจากการ์ด Meta Box เดิมไปใส่ไว้บนสุดของช่อง Featured Image
                    $('#wfwc_upload_control .inside').contents().appendTo('#postimagediv .inside');
                    $('#postimagediv .inside #wfwc-mb-box').css({ 'margin-bottom': '15px', 'display': 'block' });
                    // ซ่อนกล่อง Meta Box เดิมที่ว่างแล้ว
                    $('#wfwc_upload_control').hide();
                }

                // 2. Button Action Logic
                // [ปรับปรุง] ส่วนจัดการการคลิกปุ่มและเงื่อนไขการ Reload หน้าจอ
                $(document).on('click', '.wfwc-ajax-toggle', function (e) {
                    e.preventDefault();
                    var btn = $(this);
                    var type = btn.attr('data-type') || 'upload';
                    var originalText = btn.text();
                    var allBtns = $(`.wfwc-ajax-toggle[data-type="${type}"]`);

                    allBtns.prop('disabled', true).css('opacity', 0.5).text('Wait...');

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

                            // ตรวจสอบว่าปัจจุบันอยู่หน้าเขียนโพสต์หรือไม่
                            var pathName = window.location.pathname;
                            var isPostPage = pathName.indexOf('post-new.php') !== -1 || pathName.indexOf('post.php') !== -1;

                            if (isPostPage) {
                                // เฉพาะหน้าเขียนโพสต์: อัปเดตตัวเลขและสีทันที (ไม่เปลี่ยนหน้า)
                                updateAllUploadUI(status, timeoutLabel, expireTime, newPerm);
                                allBtns.prop('disabled', false).css('opacity', 1);
                            } else {
                                // หน้าอื่นๆ ทั้งหมด: บังคับรีโหลดหน้าใหม่เพื่อให้ข้อมูลเป็นปัจจุบัน
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

                // ฟังก์ชันอัปเดตหน้าจอสำหรับหน้าเขียนโพสต์
                function updateAllUploadUI(status, timeoutLabel, expireTime, newPerm) {
                    var bg = status ? '#dcfce7' : '#fee2e2';
                    var border = status ? '#16a34a' : '#dc2626';
                    var color = status ? '#16a34a' : '#dc2626';

                    // อัปเดตข้อความใน Meta Box พร้อมเลขสิทธิ์ล่าสุด
                    var txtStatusLong = (status ? 'เปิด (Allowed)' : 'ปิด (Locked)') + ' (' + newPerm + ')';
                    var txtBtn = status ? '🔒 ปิดทันที' : '🔓 เปิด ' + timeoutLabel;

                    $('#wfwc-mb-box').css({ 'background': bg, 'border-color': border });
                    $('#wfwc-mb-status').text(txtStatusLong).css('color', color);

                    if (status) {
                        $('#wfwc-mb-timer').text('⏱️ ปิดอัตโนมัติ: ' + expireTime).slideDown();
                    } else {
                        $('#wfwc-mb-timer').slideUp();
                    }

                    // สลับคลาสปุ่มในหน้าเขียนโพสต์
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
     * [CORE] 1. Centralized Status Checker (หัวใจหลัก)
     * ========================================= */
    private function get_target_info($type)
    {
        // 1. ระบุ Path
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

        // ถ้าหาโฟลเดอร์ไม่เจอ
        if (!$path || !is_dir($path)) {
            return ['exists' => false];
        }

        // 2. [สำคัญ] ล้าง Cache ทิ้งเดี๋ยวนี้! และอ่านค่าจริงจาก Disk
        clearstatcache(true, $path);

        // 3. อ่าน Permission จริง (เช่น 775, 555)
        $perm_num = substr(sprintf('%o', fileperms($path)), -3);

        // 4. ตัดสินจาก "ความสามารถในการเขียนจริง" (Writeable)
        // ไม่สน Database ว่าจำอะไรไว้ ให้เชื่อไฟล์จริงเท่านั้น
        $is_writable = is_writable($path);

        // 5. เตรียมข้อมูล Timeout (ถ้ามี)
        $s = $this->state();
        $ttl_min = floor($this->get_timeout_seconds() / 60);
        $expire_ts = isset($s['expire_' . $type]) ? $s['expire_' . $type] : 0;

        // 6. Logic ของปุ่ม (ถ้าเขียนได้ = สถานะเปิด -> ปุ่มต้องโชว์ปิด)
        return [
            'exists' => true,
            'type' => $type,
            'label' => $label,
            'path' => $path,
            'perm' => $perm_num,
            'is_open' => $is_writable,     // True = เขียนได้ (เขียว)
            'color' => $is_writable ? '#16a34a' : '#dc2626',
            'bg' => $is_writable ? '#dcfce7' : '#fee2e2',
            'btn_text' => $is_writable ? '🔒 ปิดทันที' : "🔓 เปิด $ttl_min นาที",
            'btn_class' => $is_writable ? 'wfwc-btn-close' : 'wfwc-btn-open',
            'status_text' => $is_writable ? 'เปิด' : 'ปิด',
            'timer_text' => ($is_writable && $expire_ts > time()) ? date('H:i:s', $expire_ts) : ''
        ];
    }

    /* =========================================
     * [CORE] AJAX HANDLER
     * ========================================= */
    public function ajax_toggle_upload()
    {
        check_ajax_referer(self::NONCE_ACTION, 'wfwc_security');
        if (!current_user_can('manage_options'))
            wp_send_json_error('Denied');

        $type = sanitize_key($_POST['type'] ?? 'upload');

        // 1. เช็คสถานะปัจจุบัน (Real-time)
        $info_current = $this->get_target_info($type);

        // 2. กำหนดเป้าหมาย: ถ้าเปิดอยู่ -> ต้องปิด, ถ้าปิด -> ต้องเปิด
        $should_open = !$info_current['is_open'];
        $dirs = $this->get_dirs_by_type($type); // Helper function (ดูด้านล่าง)

        // 3. สั่งเปลี่ยนสิทธิ์ (Action)
        if ($should_open) {
            $this->chmod_dirs($dirs, 0775); // หรือ 0777

            // Update DB
            $s = $this->state();
            $s[$type] = true;
            $s['expire_' . $type] = time() + $this->get_timeout_seconds();

            // Handle Upgrade dir
            if ($type == 'plugin' || $type == 'theme') {
                $this->chmod_dirs($this->upgrade_dirs(), 0775);
                @chmod(ABSPATH . '.htaccess', 0644);
            }
            $this->log_activity("เปิด $type (AJAX)");
        } else {
            $this->chmod_dirs($dirs, 0555);

            // Update DB
            $s = $this->state();
            $s[$type] = false;
            $s['expire_' . $type] = null;

            if ($type != 'upload' && !$s['plugin'] && !$s['theme']) {
                $this->chmod_dirs($this->upgrade_dirs(), 0555);
                @chmod(ABSPATH . '.htaccess', 0444);
            }
            $this->log_activity("ปิด $type (AJAX)");
        }

        // Save Cron
        if ($s['upload'] || $s['plugin'] || $s['theme']) {
            if (!wp_next_scheduled(self::CRON_HOOK))
                wp_schedule_event(time(), 'every_minute', self::CRON_HOOK);
        } else {
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }
        $this->save($s);

        // =========================================================
        // วนลูปเช็คสถานะไฟล์จริง 10 รอบ (รอบละ 0.1 วิ)
        // =========================================================
        $final_info = [];
        $max_retries = 10;

        for ($i = 0; $i < $max_retries; $i++) {
            // พัก 0.1 วินาที เพื่อให้ OS ทำงานทัน
            usleep(100000);

            // สั่งล้าง Cache อีกรอบ เพื่อความชัวร์
            if (isset($dirs[0]))
                clearstatcache(true, $dirs[0]);

            // อ่านค่าใหม่
            $check_info = $this->get_target_info($type);

            // ถ้าค่าเปลี่ยนเป็นตามที่เราสั่งแล้ว -> หยุดรอทันที
            if ($check_info['is_open'] === $should_open) {
                $final_info = $check_info;
                break;
            }

            // ถ้าถึงรอบสุดท้ายแล้ว ยังไม่เปลี่ยน ก็ต้องส่งค่าเท่าที่มีไป
            if ($i === $max_retries - 1) {
                $final_info = $check_info;
            }
        }
        // =========================================================

        wp_send_json_success([
            'status' => $final_info['is_open'],
            'new_perm' => $final_info['perm'],
            'timeout_label' => floor($this->get_timeout_seconds() / 60) . ' นาที',
            'expire_time' => $final_info['timer_text'],
            'btn_text' => $final_info['btn_text'],
            'btn_class' => $final_info['btn_class'],
            'color' => $final_info['color'],
            'bg' => $final_info['bg']
        ]);
    }

    // Helper สำหรับดึง Dir ตาม Type
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
        foreach (['upload', 'plugin', 'theme'] as $type) {
            // เรียกใช้สมองกลาง (ทำให้ทุกหน้าเหมือนกันเป๊ะ)
            $info = $this->get_target_info($type);
            if (!$info['exists'])
                continue;

            $pill_cls = $info['is_open'] ? 'status-open' : 'status-closed';
            $icon = ($type == 'upload') ? '📤' : (($type == 'plugin') ? '🔌' : '🎨');

            ?>
            <div id="wfwc-card-<?= $type ?>" class="wfwc-card <?= $info['is_open'] ? 'active' : 'inactive' ?>">
                <div class="wfwc-card-top">
                    <span class="wfwc-card-icon"><?= $icon ?></span>
                    <span class="wfwc-status-pill <?= $pill_cls ?>"><?= $info['status_text'] ?> : <?= $info['perm'] ?></span>
                </div>
                <h3 class="wfwc-card-title"><?= $info['label'] ?>
                    <code
                        style="font-size:12px; background:#fff; padding:2px 5px; border-radius:4px; border:1px solid #ddd;"><?= $info['perm'] ?></code>
                </h3>
                <button class="wfwc-btn wfwc-ajax-toggle <?= $info['btn_class'] ?>" data-type="<?= $type ?>">
                    <?= $info['btn_text'] ?>
                </button>
                <?php if ($info['timer_text']): ?>
                    <div class="wfwc-timer">⏱️ Auto Close: <?= $info['timer_text'] ?></div>
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

        // ปรับหัวตารางเป็น Device / IP
        echo '<div class="wfwc-table-container"><table class="wfwc-table"><thead><tr><th>Time</th><th>User</th><th>Action</th>' . (!$is_widget ? '<th>Device / IP</th>' : '') . '</tr></thead><tbody>';

        foreach ($logs as $l) {
            $act = $l['action'];

            // ดึงค่า Device และ IP 
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

            // แสดงผล: เอา Device ไว้บรรทัดบน, IP ไว้บรรทัดล่าง (ตัวเล็ก)
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
        // [แก้ไข 1] ใช้ Path เดียวกับที่ระบบใช้สั่งงานจริง (เพื่อความแม่นยำ)
        $u = wp_upload_dir();
        $upload_path = $u['basedir'];

        // [แก้ไข 2] ล้าง Cache สถานะไฟล์แบบ Force เพื่อให้ได้ค่าล่าสุดจริงๆ
        clearstatcache(true);

        $paths = [
            'Root Path (/)' => ABSPATH,
            'wp-content' => WP_CONTENT_DIR,
            'wp-config.php' => ABSPATH . 'wp-config.php',
            '.htaccess' => ABSPATH . '.htaccess',
            'Uploads' => $upload_path, // ใช้ค่า Dynamic จากระบบ
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

            // Logic สีและการแสดงผล
            $row_style = $w ? 'background-color: #fee2e2;' : 'background-color: #d1fae5;';
            $status_html = $w
                ? '<span style="color:#991b1b; font-weight:bold;">🔓 Writable (ความเสี่ยง)</span>'
                : '<span style="color:#065f46; font-weight:bold;">✓ Locked (ปลอดภัย)</span>';

            // เพิ่ม Tooltip บอก Path จริง เพื่อให้ตรวจสอบง่ายขึ้น
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
                        <div class="wfwc-form-group">
                            <div class="wfwc-form-header">
                                <label class="wfwc-switch">
                                    <input type="checkbox" name="wfwc_enable_email" value="1" <?php checked($settings['enable_email'], 1); ?>><span class="slider"></span></label>
                                <label>Enable Email Notification</label>
                            </div>
                            <div style="display:flex; gap:10px;">
                                <input type="email" name="wfwc_email" class="wfwc-form-control"
                                    value="<?= esc_attr($settings['email']) ?>" placeholder="admin@example.com">
                                <button type="submit" id="wfwc-btn-test"
                                    formaction="<?= admin_url('admin-post.php?action=wfwc_test_email') ?>" class="button">✉️
                                    Test</button>
                            </div>
                        </div>
                        <div class="wfwc-form-group"><label>⏱️ Auto-Disable Timeout (นาที)</label><input type="number"
                                name="wfwc_timeout" class="wfwc-form-control" value="<?= esc_attr($settings['timeout']) ?>"
                                min="1" max="1440"></div>
                        <div style="border-top:1px solid #eee; margin: 20px 0;"></div>
                        <div class="wfwc-form-group">
                            <div class="wfwc-form-header"><label class="wfwc-switch"><input type="checkbox"
                                        name="wfwc_enable_api" value="1" <?php checked($settings['enable_api'], 1); ?>><span
                                        class="slider"></span></label><label>Enable API Access</label></div>
                            <div style="margin-bottom:15px;"><label
                                    style="font-size:13px; display:block; margin-bottom:5px;">Secret Key (Header:
                                    <code>X-WFWC-SECRET</code>)</label>
                                <div style="display:flex; gap:10px;"><input type="text" name="wfwc_api_key"
                                        class="wfwc-form-control" value="<?= esc_attr($settings['api_key']) ?>"
                                        placeholder="Ex. sk_..."><button class="button" id="wfwc-gen-key">🎲 Gen</button></div>
                            </div>
                            <div><label style="font-size:13px; display:block; margin-bottom:5px;">Allowed IPs
                                    (Whitelist)</label><textarea name="wfwc_allowed_ips" class="wfwc-form-control" rows="3"
                                    placeholder="192.168.1.1"><?= esc_textarea($settings['allowed_ips']) ?></textarea></div>
                        </div>
                        <button type="submit" class="wfwc-btn wfwc-btn-primary" style="width:auto; padding: 12px 30px;">💾
                            บันทึกการตั้งค่า</button>
                    </form>
                </div>

                <?php
                // [ส่วนเพิ่มสำหรับ Debug IP]
                echo '<div class="wfwc-section-title">🕵️ Debug IP Headers (ข้อมูลสำหรับ Admin เท่านั้น)</div>';
                echo '<div class="wfwc-settings-box" style="background:#f0f9ff; font-family:monospace; font-size:12px; color:#333;">';
                $debug_keys = [
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
                foreach ($debug_keys as $key) {
                    $val = $_SERVER[$key] ?? '<span style="color:#ccc;">- Not Set -</span>';
                    echo "<strong>$key:</strong> $val<br>";
                }
                echo '</div>';
                ?>

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
        // รายชื่อ Header ที่เป็นไปได้ทั้งหมด เรียงตามความน่าเชื่อถือ
        $headers = [
            'HTTP_CF_CONNECTING_IP',  // Cloudflare
            'HTTP_X_REAL_IP',         // Nginx / FastCGI (เจอบ่อยในโฮสต์มหาลัย)
            'HTTP_X_FORWARDED_FOR',   // Proxy มาตรฐาน
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'             // ค่าพื้นฐาน (ถ้าไม่เจออะไรเลย)
        ];

        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                // กรณีมาเป็นลิสต์ "IP1, IP2, IP3" ให้เอาตัวแรกสุด
                $ip_array = explode(',', $_SERVER[$header]);
                $ip = trim($ip_array[0]);

                // ตรวจสอบรูปแบบ IP ว่าถูกต้องไหม (รองรับทั้ง IPv4 และ IPv6)
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? 'Unknown');
    }

    private function get_settings()
    {
        $defaults = ['enable_email' => 0, 'email' => get_option('admin_email'), 'timeout' => 10, 'enable_api' => 0, 'api_key' => '', 'allowed_ips' => ''];
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
            $this->log_activity('Auto Disable Uploads');
        }
        if ($s['plugin'] && $s['expire_plugin'] && $now > $s['expire_plugin']) {
            $this->chmod_dirs($this->plugin_dirs(), 0555);
            $s['plugin'] = false;
            $s['expire_plugin'] = null;
            if (!$s['theme'])
                $this->chmod_dirs($this->upgrade_dirs(), 0555);
            $chg = true;
            $this->log_activity('Auto Disable Plugins');
        }
        if ($s['theme'] && $s['expire_theme'] && $now > $s['expire_theme']) {
            $this->chmod_dirs($this->theme_dirs(), 0555);
            $s['theme'] = false;
            $s['expire_theme'] = null;
            if (!$s['plugin'])
                $this->chmod_dirs($this->upgrade_dirs(), 0555);
            $chg = true;
            $this->log_activity('Auto Disable Themes');
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
                    $check = wp_check_filetype_and_ext($file['tmp_name'], $file['name']);
                    if (in_array($check['ext'], $allowed_extensions) && $check['proper_filename'] === false)
                        $is_safe = true;
                }
            } else {
                $disp = $request->get_header('content-disposition');
                if ($disp && preg_match('/filename="(.+?)"/', $disp, $matches)) {
                    $ext = strtolower(pathinfo($matches[1], PATHINFO_EXTENSION));
                    if (in_array($ext, $allowed_extensions))
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
        // ถ้าเป็นการกดปุ่มผ่าน AJAX ห้ามยุ่งเด็ดขาด ให้ปุ่มจัดการตัวเอง
        if (defined('DOING_AJAX') && DOING_AJAX) {
            return;
        }

        // ดึงค่าสถานะล่าสุดแบบไม่ผ่าน Cache
        $s = $this->state();

        // ถ้าสถานะในระบบยังเป็น "เปิด" หรือมีเวลา "Expire" เหลืออยู่ ห้ามล็อค
        if (!empty($s['upload']) && $s['upload'] === true) {
            return;
        }
        if (!empty($s['expire_upload']) && $s['expire_upload'] > time()) {
            return;
        }

        // สั่งล็อคเฉพาะเมื่อระบบมั่นใจว่าต้องปิดจริงๆ
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

    public function deactivate()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function block_caps($caps)
    {
        $s = $this->state();
        if (!$s['plugin']) {
            $caps['install_plugins'] = false;
            $caps['update_plugins'] = false;
            $caps['delete_plugins'] = false;
            $caps['edit_plugins'] = false;
        }
        if (!$s['theme']) {
            $caps['install_themes'] = false;
            $caps['update_themes'] = false;
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

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown Device';
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

        // 1. บันทึก Log ลง Database (บันทึกทุกกรณี ทั้งเปิดและปิด)
        $entry = ['time' => $time, 'user' => $username, 'action' => $action, 'ip' => $ip, 'device' => $device, 'full_ua' => $ua];
        $logs = get_option(self::LOG_KEY, []);
        if (!is_array($logs))
            $logs = [];
        array_unshift($logs, $entry);
        if (count($logs) > 20)
            array_pop($logs);
        update_option(self::LOG_KEY, $logs);

        // 2. ส่ง Email (แก้ไข: ไม่ส่งถ้าเป็นการ "ปิด")
        // เช็คว่าในข้อความ $action มีคำว่า "ปิด" หรือ "disable" หรือไม่
        $is_closing = (strpos($action, 'ปิด') !== false || stripos($action, 'disable') !== false);

        if (
            !empty($settings['enable_email']) &&      // ต้องเปิดระบบ Email ไว้
            is_email($settings['email']) &&           // อีเมลต้องถูกต้อง
            strpos($action, 'API') === false &&       // ไม่ใช่งาน API
            !$is_closing                              // <--- เพิ่มเงื่อนไข: ต้องไม่ใช่การปิด
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
        // ส่งกลับเฉพาะโฟลเดอร์หลัก uploads เท่านั้น ไม่เอาโฟลเดอร์ย่อย
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

    /* =========================================
     * [CORE] Helper: Change Permission (Turbo Mode)
     * พยายามใช้ Command Line เพื่อความเร็วสูงสุด
     * ========================================= */
    private function chmod_dirs($dirs, $mode)
    {
        if (empty($dirs) || !is_array($dirs))
            return;

        // คำนวณเลขสิทธิ์สำหรับ Command Line (String)
        $dir_mode_oct = sprintf('%o', $mode);           // "775" หรือ "555"
        $file_mode_oct = ($mode === 0555) ? '444' : '644'; // "444" หรือ "644"

        // คำนวณเลขสิทธิ์สำหรับ PHP (Integer)
        $target_file_mode = ($mode === 0555) ? 0444 : 0644;

        foreach ($dirs as $dir) {
            if (!is_dir($dir))
                continue;

            // ---------------------------------------------------------
            // วิธีที่ 1: เร็วที่สุด (Turbo) - ใช้คำสั่ง Linux 'find' & 'chmod'
            // ---------------------------------------------------------
            // เช็คว่า Server อนุญาตให้ใช้ exec() หรือไม่
            if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {

                // 1. เปลี่ยนสิทธิ์ Folder ทั้งหมดในรวดเดียว
                // คำสั่ง: find /path -type d -exec chmod 775 {} +
                $cmd_dir = "find " . escapeshellarg($dir) . " -type d -exec chmod $dir_mode_oct {} + 2>&1";
                @exec($cmd_dir);

                // 2. เปลี่ยนสิทธิ์ File ทั้งหมดในรวดเดียว
                // คำสั่ง: find /path -type f -exec chmod 644 {} +
                $cmd_file = "find " . escapeshellarg($dir) . " -type f -exec chmod $file_mode_oct {} + 2>&1";
                @exec($cmd_file);

                // ล้าง Cache และข้ามไปโฟลเดอร์ถัดไปทันที (ไม่ต้องทำ Loop PHP)
                clearstatcache(true, $dir);
                continue;
            }

            // ---------------------------------------------------------
            // วิธีที่ 2: ช้าแต่ชัวร์ (Fallback) - ใช้ PHP Loop ทีละไฟล์
            // (กรณีโฮสต์ปิด exec หรือเป็น Windows)
            // ---------------------------------------------------------
            @set_time_limit(300); // ขอเวลาเพิ่มเป็น 5 นาที

            // เปลี่ยนโฟลเดอร์แม่
            $this->smart_chmod($dir, $mode);

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $item) {
                    // หยุดถ้าระบบใช้เวลานานเกินไป (ป้องกัน Error 504)
                    // แต่เราใส่ set_time_limit ไว้ช่วยแล้ว

                    if ($item->isDir()) {
                        $this->smart_chmod($item->getPathname(), $mode);
                    } elseif ($item->isFile()) {
                        $this->smart_chmod($item->getPathname(), $target_file_mode);
                    }
                }
            } catch (Exception $e) {
                continue;
            }

            clearstatcache(true, $dir);
        }
    }

    /* =========================================
     * [HELPER] Smart Chmod (เช็คก่อนเปลี่ยน)
     * ========================================= */
    private function smart_chmod($path, $target_mode)
    {
        // อ่านค่าปัจจุบัน (Bitwise Operation)
        $current_perms = fileperms($path) & 0777;

        // ถ้าค่าไม่ตรง ค่อยสั่งเปลี่ยน (ลดภาระ Server)
        if ($current_perms !== $target_mode) {
            @chmod($path, $target_mode);
        }
    }

    public function render_admin_notices()
    {
        global $pagenow;
        $t = '';
        if ($pagenow === 'upload.php')
            $t = 'upload';
        elseif ($pagenow === 'plugins.php')
            $t = 'plugin';
        elseif ($pagenow === 'themes.php')
            $t = 'theme';

        if ($t) {
            // เรียกใช้สมองกลาง
            $info = $this->get_target_info($t);
            if ($info['exists']) {
                echo "<div id='wfwc-notice-bar' class='notice' style='background:{$info['bg']}; border-left: 5px solid {$info['color']}; display:flex; align-items:center; justify-content:space-between; padding:15px 20px; margin: 20px 0;'>
                    <div style='font-size:14px; color:#333;'>
                        <strong>🔐 {$info['label']} Security:</strong> 
                        <span style='font-weight:bold; color:{$info['color']}; margin-left:5px;'>{$info['status_text']}</span>
                        <code style='background:#fff; padding:2px 6px; border-radius:4px; border:1px solid #ddd; margin-left:10px; font-size:11px;'>Perm: {$info['perm']}</code>
                    </div>
                    <button class='button wfwc-ajax-toggle {$info['btn_class']}' data-type='{$info['type']}'>
                        {$info['btn_text']}
                    </button>
                </div>";
            }
        }
    }

    public function media_modal_control()
    {
        $s = $this->state();
        $ttl_min = floor($this->get_timeout_seconds() / 60);
        $bg = $s['upload'] ? '#dcfce7' : '#fee2e2';
        $txt = $s['upload'] ? 'เปิด (Allowed)' : 'ปิด (Locked)';
        $btn = $s['upload'] ? 'Close' : "Open $ttl_min Min";
        // ใช้ Class สีของเรา
        $btn_cls = $s['upload'] ? 'wfwc-btn-close' : 'wfwc-btn-open';

        ?>
        <script type="text/html" id="tmpl-wfwc-modal-bar">
                                                                                            <div id="wfwc-modal-bar" class="wfwc-modal-bar" style="background:<?php echo $bg; ?>; transition:0.3s;">
                                                                                                <span>Upload Security: <strong id="wfwc-modal-status"><?php echo $txt; ?></strong></span>
                                                                                                <button class="button wfwc-ajax-toggle <?php echo $btn_cls; ?>" data-type="upload"><?php echo $btn; ?></button>
                                                                                            </div>
                                                                                        </script>
        <?php
    }

    public function add_meta_boxes()
    {
        foreach (['post', 'page'] as $s)
            add_meta_box('wfwc_upload_control', 'File Write Control', [$this, 'render_meta_box'], $s, 'side', 'high');
    }

    public function render_meta_box($post)
    {
        // เรียกใช้สมองกลาง
        $info = $this->get_target_info('upload');

        if (!$info['exists']) {
            echo "Error";
            return;
        }

        echo "<div id='wfwc-mb-box' style='background:{$info['bg']}; border: 2px solid {$info['color']}; padding:12px; text-align:center; border-radius:4px;'>
            <div style='font-size:13px; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center;'>
                <strong>📤 Upload:</strong> 
                <span id='wfwc-mb-status' style='font-weight:bold; color:{$info['color']};'>
                    {$info['status_text']} ({$info['perm']})
                </span>
            </div>";

        echo "<button class='button wfwc-ajax-toggle {$info['btn_class']}' data-type='upload' style='width:100%;'>
                {$info['btn_text']}
              </button>";

        $dsp = ($info['is_open'] && $info['timer_text']) ? 'block' : 'none';
        echo "<div id='wfwc-mb-timer' style='display:$dsp; margin-top:8px; font-size:11px; color:#b91c1c; background:rgba(255,255,255,0.5); padding:4px; border-radius:4px;'>
                ⏱️ Auto Close: {$info['timer_text']}
              </div>";
        echo "</div>";
    }



}

new WP_File_Write_Control();