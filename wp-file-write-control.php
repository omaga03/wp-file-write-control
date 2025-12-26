<?php
/*
Plugin Name: WP File Write Control (Security Dashboard)
Description: ระบบความปลอดภัยไฟล์ + API Secure + AJAX (Toggle Switch Fix UI)
Version: 7.0.9
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

                    /* --- COLOR UTILITIES --- */
                    .wfwc-btn-open {
                        background-color: #4f46e5 !important;
                        color: white !important;
                        border: 1px solid #4f46e5 !important;
                    }

                    .wfwc-btn-open:hover {
                        background-color: #4338ca !important;
                    }

                    .wfwc-btn-close {
                        background-color: #dc2626 !important;
                        color: white !important;
                        border: 1px solid #dc2626 !important;
                    }

                    .wfwc-btn-close:hover {
                        background-color: #b91c1c !important;
                    }

                    /* --- [NEW FIXED] Toggle Switch Style --- */
                    .wfwc-switch-container {
                        display: flex;
                        align-items: center;
                        gap: 10px;
                        margin-bottom: 15px;
                        cursor: pointer;
                        user-select: none;
                        position: relative; /* เพื่อให้จัดการ input ได้ง่าย */
                    }

                    /* [แก้ไข] ซ่อน Input แบบเด็ดขาด (ใช้ opacity แทน display:none เพื่อกันปัญหาบางธีม) */
                    .wfwc-switch-input {
                        position: absolute !important;
                        opacity: 0 !important;
                        width: 0 !important;
                        height: 0 !important;
                        margin: 0 !important;
                        pointer-events: none;
                    }

                    /* ตัวรางสวิตช์ */
                    .wfwc-switch-track {
                        position: relative;
                        width: 44px;
                        height: 24px;
                        background-color: #e2e8f0; /* สีเทาตอนปิด */
                        border-radius: 20px;
                        transition: all 0.3s ease;
                        border: 1px solid #cbd5e1;
                        box-shadow: inset 0 1px 2px rgba(0,0,0,0.05);
                    }

                    /* ปุ่มกลมๆ */
                    .wfwc-switch-knob {
                        position: absolute;
                        top: 2px;
                        left: 2px;
                        width: 18px;
                        height: 18px;
                        background-color: white;
                        border-radius: 50%;
                        transition: all 0.3s cubic-bezier(0.4, 0.0, 0.2, 1);
                        box-shadow: 0 2px 4px rgba(0,0,0,0.2);
                    }

                    /* สถานะ: เปิด (Checked) */
                    .wfwc-switch-input:checked + .wfwc-switch-track {
                        background-color: #f59e0b; /* สีส้ม Deep Mode */
                        border-color: #d97706;
                    }

                    .wfwc-switch-input:checked + .wfwc-switch-track .wfwc-switch-knob {
                        transform: translateX(20px);
                    }

                    /* สถานะ: Disabled (ตอนล็อค) */
                    .wfwc-switch-input:disabled + .wfwc-switch-track {
                        opacity: 0.6;
                        cursor: not-allowed;
                        filter: grayscale(0.5);
                    }
            
                    /* ข้อความข้างๆ */
                    .wfwc-switch-label {
                        font-size: 13px;
                        font-weight: 600;
                        color: #475569;
                    }
            
                    .wfwc-switch-container:hover .wfwc-switch-label {
                        color: #d97706;
                    }

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

                    /* [Card Layout Fix] ให้ปุ่มเท่ากัน */
                    .wfwc-cards {
                        display: grid;
                        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
                        gap: 20px;
                        margin-bottom: 40px;
                        align-items: stretch; /* [สำคัญ] บังคับให้การ์ดสูงเท่ากัน */
                    }

                    .wfwc-card {
                        border-radius: 12px;
                        padding: 20px;
                        box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
                        border: 1px solid rgba(0, 0, 0, 0.1);
                        position: relative;
                        transition: all 0.3s ease;
                
                        /* [สำคัญ] ใช้ Flexbox จัดเรียงแนวตั้ง */
                        display: flex;
                        flex-direction: column;
                        height: 100%; 
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

                    /* [Button Fix] ดันปุ่มลงล่างสุด */
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
                        margin-top: auto; /* [สำคัญ] คำสั่งนี้จะดันปุ่มไปติดขอบล่าง */
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

                    @keyframes wfwc-spin {
                        0% {
                            transform: rotate(0deg);
                        }

                        100% {
                            transform: rotate(360deg);
                        }
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

                        // [JS แก้ไข] Effect ปุ่มบันทึก (แก้ปัญหาปุ่มบีบ)
                        $('.wfwc-settings-box form').on('submit', function () {
                            var btn = $(this).find('button[type="submit"]');

                            // 1. จำความกว้างเดิมไว้เป็น "ขั้นต่ำ" (กันปุ่มหด แต่ยอมให้ขยายได้)
                            var originalWidth = btn.outerWidth();
                            btn.css('min-width', originalWidth);

                            // 2. เปลี่ยนข้อความและไอคอน (จัด HTML ให้สวยงาม)
                            // ใช้ <span> ห่อข้อความเพื่อให้จัดตำแหน่งง่าย
                            btn.html('<span class="dashicons dashicons-update wfwc-loading-icon"></span> <span>กำลังบันทึก...</span>');

                            // 3. ใส่ Class
                            btn.addClass('wfwc-btn-saving');
                        });

                        // 2. Button Action Logic (Modified for Checkbox/Switch)
                        $(document).on('click', '.wfwc-ajax-toggle', function (e) {
                            e.preventDefault();
                            var btn = $(this);
                            var type = btn.attr('data-type') || 'upload'; // เช่น upload, plugin, theme

                            // [CHANGE] Checkbox logic instead of Radio
                            var checkbox = $('input[name="wfwc_deep_check_' + type + '"]');
                            // ถ้าติ๊ก = Deep (all_recursive), ไม่ติ๊ก = Turbo (dir_only)
                            var selectedMode = checkbox.is(':checked') ? 'all_recursive' : 'dir_only';

                            var originalText = btn.text();
                            var allBtns = $(`.wfwc-ajax-toggle[data-type="${type}"]`);

                            allBtns.prop('disabled', true).css('opacity', 0.5).text('Working...');

                            $.post(ajaxurl, {
                                action: 'wfwc_toggle_upload',
                                wfwc_security: '<?php echo wp_create_nonce(self::NONCE_ACTION); ?>',
                                type: type,
                                mode: selectedMode // ส่งค่า Mode ของใครของมันไป
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

                                        // [JS] Disable/Enable checkbox based on new status
                                        // ถ้าสถานะใหม่คือ Opened (status=true) -> Disable Checkbox
                                        // ถ้าสถานะใหม่คือ Closed (status=false) -> Enable Checkbox
                                        var targetCheck = $('input[name="wfwc_deep_check_' + type + '"]');
                                        targetCheck.prop('disabled', status);
                                        if (!status) {
                                            // ถ้าปิดแล้ว ให้ Reset เป็น Unchecked (Default Turbo) ตามต้องการ
                                            targetCheck.prop('checked', false);
                                        }

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

        // ล้าง Cache สถานะไฟล์
        clearstatcache(true, $path);
        $perm_num = substr(sprintf('%o', fileperms($path)), -3);

        // [New] เช็คด้วยการ "ลองเขียนไฟล์จริง" (Real Write Test)
        // เพื่อความชัวร์ที่สุดว่า PHP เขียนได้จริงหรือไม่
        $test_file = $path . '/.wfwc_check.tmp';
        $is_writable = false;

        if (@file_put_contents($test_file, 'test') !== false) {
            $is_writable = true;
            @unlink($test_file); // เขียนได้แล้วลบทิ้ง
        } else {
            // Fallback: ถ้าเขียนไม่ได้ ลองเช็คฟังก์ชันพื้นฐาน
            $is_writable = is_writable($path);
        }

        $s = $this->state();
        $ttl_min = floor($this->get_timeout_seconds() / 60);
        $expire_ts = isset($s['expire_' . $type]) ? $s['expire_' . $type] : 0;

        return [
            'exists' => true,
            'type' => $type,
            'label' => $label,
            'path' => $path,
            'perm' => $perm_num,
            'is_open' => $is_writable,
            'color' => $is_writable ? '#16a34a' : '#dc2626',
            'bg' => $is_writable ? '#dcfce7' : '#fee2e2',
            'btn_text' => $is_writable ? '🔒 ปิดทันที' : "🔓 เปิด $ttl_min นาที",
            'btn_class' => $is_writable ? 'wfwc-btn-close' : 'wfwc-btn-open',
            'status_text' => $is_writable ? 'เปิด' : 'ปิด',
            'timer_text' => ($is_writable && $expire_ts > time()) ? date('H:i:s', $expire_ts) : ''
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
        // รับค่า Mode: ถ้าติ๊ก Checkbox จะส่งมาเป็น all_recursive, ถ้าไม่ติ๊ก JS จะส่ง dir_only
        $req_mode = sanitize_key($_POST['mode'] ?? 'dir_only');

        $s = $this->state(); // ดึงค่าจาก Database

        $info_current = $this->get_target_info($type);
        $should_open = !$info_current['is_open'];
        $dirs = $this->get_dirs_by_type($type);

        if ($should_open) {
            // [OPEN] เปิดสิทธิ์
            $this->chmod_dirs($dirs, 0775, $req_mode);

            // --- [จุดสำคัญ 1] บันทึกความทรงจำ ---
            // จำไว้ว่ารอบนี้เปิดด้วยโหมดอะไร (Turbo หรือ Deep)
            $s['mode_' . $type] = $req_mode;

            $s[$type] = true;
            $s['expire_' . $type] = time() + $this->get_timeout_seconds();

            if ($type == 'plugin' || $type == 'theme') {
                $this->chmod_dirs($this->upgrade_dirs(), 0775, 'dir_only');
                @chmod(ABSPATH . '.htaccess', 0644);
            }
            $this->log_activity("เปิด $type (Mode: $req_mode)");

        } else {
            // [CLOSE] ปิดสิทธิ์

            // --- [จุดสำคัญ 2] รื้อฟื้นความทรงจำ ---
            // ถ้าเคยจำไว้ว่าเปิดด้วย Deep ให้ปิดด้วย Deep (เพื่อความชัวร์)
            // แต่ถ้าไม่มีในความจำ (เช่น เปิดมาจากเวอร์ชั่นเก่า) ให้เชื่อตามที่หน้าจาส่งมา
            $closing_mode = isset($s['mode_' . $type]) ? $s['mode_' . $type] : $req_mode;

            $this->chmod_dirs($dirs, 0555, $closing_mode);

            // ล้างความจำ เตรียมไว้รอบหน้า
            unset($s['mode_' . $type]);

            $s[$type] = false;
            $s['expire_' . $type] = null;

            if ($type != 'upload' && !$s['plugin'] && !$s['theme']) {
                $this->chmod_dirs($this->upgrade_dirs(), 0555, 'dir_only');
                @chmod(ABSPATH . '.htaccess', 0444);
            }
            $this->log_activity("ปิด $type (Mode: $closing_mode)");
        }

        $this->save($s); // บันทึกลง DB

        // ... (ส่วน Verify และ Return JSON) ...
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

        $s = $this->state();
        $settings = $this->get_settings();

        foreach (['upload', 'plugin', 'theme'] as $type) {
            $info = $this->get_target_info($type);
            if (!$info['exists'])
                continue;

            $pill_cls = $info['is_open'] ? 'status-open' : 'status-closed';
            $icon = ($type == 'upload') ? '📤' : (($type == 'plugin') ? '🔌' : '🎨');

            // [Logic] Deep/Turbo Status
            // ถ้าเปิดอยู่: เช็คจาก DB ว่าเปิดด้วย Deep ไหม
            // ถ้าปิดอยู่: Default คือ Unchecked (Turbo)
            $saved_mode = isset($s['mode_' . $type]) ? $s['mode_' . $type] : 'dir_only';
            $is_deep_active = $info['is_open'] ? ($saved_mode === 'all_recursive') : false;

            // ถ้าเปิดอยู่ ให้ล็อค Checkbox (disabled)
            $disabled_attr = $info['is_open'] ? 'disabled' : '';

            ?>
                        <div id="wfwc-card-<?= $type ?>" class="wfwc-card <?= $info['is_open'] ? 'active' : 'inactive' ?>">
                            <div class="wfwc-card-top">
                                <span class="wfwc-card-icon"><?= $icon ?></span>
                                <span class="wfwc-status-pill <?= $pill_cls ?>"><?= $info['status_text'] ?> : <?= $info['perm'] ?></span>
                            </div>

                            <h3 class="wfwc-card-title"><?= $info['label'] ?></h3>

                            <?php if ($type === 'upload'): ?>
                                <label class="wfwc-switch-container" title="Apply to sub-folders and files">
                                    <input type="checkbox" class="wfwc-switch-input" name="wfwc_deep_check_<?= $type ?>" value="1" 
                                        <?php checked($is_deep_active, true); ?> 
                                        <?= $disabled_attr ?>>
                    
                                    <div class="wfwc-switch-track">
                                        <div class="wfwc-switch-knob"></div>
                                    </div>
                    
                                    <span class="wfwc-switch-label">🐢 Deep Mode</span>
                                </label>
                            <?php endif; ?>

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
        $defaults = [
            'enable_email' => 0,
            'email' => get_option('admin_email'),
            'timeout' => 10,
            'enable_api' => 0,
            'api_key' => '',
            'allowed_ips' => '',
            // 'enable_mode_selector' => 1 // Deprecated
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
            // [ใหม่] รับค่า Settings (ตัด mode_selector ออก)
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

        // 1. จัดการ Uploads
        if ($s['upload'] && $s['expire_upload'] && $now > $s['expire_upload']) {
            // [จุดสำคัญ] ดึงความจำมาดูว่าเปิดด้วยโหมดอะไร (ถ้าไม่มี ใช้ค่า Default คือ dir_only)
            $closing_mode = isset($s['mode_upload']) ? $s['mode_upload'] : 'dir_only';

            // สั่งปิดด้วยโหมดนั้น
            $this->chmod_dirs($this->upload_dirs(), 0555, $closing_mode);

            // Reset ค่า
            $s['upload'] = false;
            $s['expire_upload'] = null;
            unset($s['mode_upload']); // ล้างความจำทิ้ง

            $chg = true;
            $this->log_activity("Auto Disable Uploads (Mode: $closing_mode)");
        }

        // 2. จัดการ Plugins
        if ($s['plugin'] && $s['expire_plugin'] && $now > $s['expire_plugin']) {
            $closing_mode = isset($s['mode_plugin']) ? $s['mode_plugin'] : 'dir_only';

            $this->chmod_dirs($this->plugin_dirs(), 0555, $closing_mode);

            $s['plugin'] = false;
            $s['expire_plugin'] = null;
            unset($s['mode_plugin']);

            if (!$s['theme']) {
                $this->chmod_dirs($this->upgrade_dirs(), 0555, 'dir_only');
            }

            $chg = true;
            $this->log_activity("Auto Disable Plugins (Mode: $closing_mode)");
        }

        // 3. จัดการ Themes
        if ($s['theme'] && $s['expire_theme'] && $now > $s['expire_theme']) {
            $closing_mode = isset($s['mode_theme']) ? $s['mode_theme'] : 'dir_only';

            $this->chmod_dirs($this->theme_dirs(), 0555, $closing_mode);

            $s['theme'] = false;
            $s['expire_theme'] = null;
            unset($s['mode_theme']);

            if (!$s['plugin']) {
                $this->chmod_dirs($this->upgrade_dirs(), 0555, 'dir_only');
            }

            $chg = true;
            $this->log_activity("Auto Disable Themes (Mode: $closing_mode)");
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
     * [CORE] Helper: Change Permission (With Mode Selection)
     * ========================================= */
    // [แก้ไข] เพิ่ม Parameter $force_mode = null
    private function chmod_dirs($dirs, $mode, $force_mode = null)
    {
        if (empty($dirs) || !is_array($dirs))
            return;

        $target_file_mode = ($mode === 0555) ? 0444 : 0644;

        // เช็คว่า User เลือกโหมดไหนมา (ถ้าไม่ส่งมา ให้ใช้ dir_only เป็นค่า Default)
        $is_deep_mode = ($force_mode === 'all_recursive');

        foreach ($dirs as $dir) {
            if (!is_dir($dir))
                continue;

            if ($is_deep_mode)
                @set_time_limit(0);

            // 1. เปลี่ยน Folder แม่
            @chmod($dir, $mode);

            // 2. วนลูป
            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::SELF_FIRST
                );

                foreach ($iterator as $item) {
                    try {
                        if ($item->isDir()) {
                            @chmod($item->getPathname(), $mode);
                        } elseif ($item->isFile()) {
                            // [Condition] ทำงานเฉพาะ Deep Mode
                            if ($is_deep_mode) {
                                @chmod($item->getPathname(), $target_file_mode);
                            }
                        }
                    } catch (Exception $e) {
                        continue;
                    }
                }
            } catch (Exception $e) {
            }

            // 3. ย้ำ Folder แม่
            @chmod($dir, $mode);
            clearstatcache(true, $dir);
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
            $info = $this->get_target_info($t);

            if ($info['exists']) {
                $s = $this->state();

                // [Logic Modified] Check for Checkbox State
                $saved_mode = isset($s['mode_' . $t]) ? $s['mode_' . $t] : 'dir_only';
                $is_deep_active = $info['is_open'] ? ($saved_mode === 'all_recursive') : false;

                // ถ้าเปิดอยู่ ให้ล็อคปุ่ม
                $disabled_attr = $info['is_open'] ? 'disabled' : '';

                echo "<div id='wfwc-notice-bar' class='notice' style='background:{$info['bg']}; border-left: 5px solid {$info['color']}; display:flex; align-items:center; justify-content:space-between; padding:10px 20px; margin: 20px 0; box-shadow: 0 1px 1px rgba(0,0,0,.04); transition:0.3s;'>
                    
                    <div style='font-size:14px; color:#333; display:flex; align-items:center;'>
                        <strong>🔐 {$info['label']} Security:</strong> 
                        <span style='font-weight:bold; color:{$info['color']}; margin-left:5px;'>{$info['status_text']}</span>
                        <code style='background:#fff; padding:2px 6px; border-radius:4px; border:1px solid #ddd; margin-left:8px; font-size:11px;'>{$info['perm']}</code>
                    </div>

                    <div style='display:flex; align-items:center; gap:15px;'>
                        " . ($t === 'upload' ? "
                        <label class='wfwc-switch-container' style='margin-bottom:0;'>
                            <input type='checkbox' class='wfwc-switch-input' name='wfwc_deep_check_{$t}' value='1' 
                                " . checked($is_deep_active, true, false) . " 
                                $disabled_attr>
                            <div class='wfwc-switch-track' style='width:36px; height:20px;'>
                                <div class='wfwc-switch-knob' style='width:16px; height:16px; top:1px; left:1px;'></div>
                            </div>
                            <span class='wfwc-switch-label' style='font-size:12px; margin-left:5px;'>🐢 Deep</span>
                        </label>" : "") . "

                        <button class='button wfwc-ajax-toggle {$info['btn_class']}' data-type='{$info['type']}'>
                            {$info['btn_text']}
                        </button>
                    </div>

                </div>";
            }
        }
    }

    public function media_modal_control()
    {
        // ... (ส่วนนี้ถูกปิดการใช้งานใน Constructor แล้ว แต่คงไว้เผื่อเปิดใช้ในอนาคต) ...
    }

    public function add_meta_boxes()
    {
        // 1. เริ่มต้นด้วย post และ page
        $screens = ['post', 'page'];

        // 2. ค้นหา Post Type ทั้งหมดที่ลงท้ายด้วย _gallery
        $args = ['public' => true];
        $all_types = get_post_types($args, 'names');
        foreach ($all_types as $pt) {
            if (substr($pt, -8) === '_gallery') {
                $screens[] = $pt;
            }
        }

        // 3. สร้างกล่อง Meta Box
        foreach (array_unique($screens) as $s) {
            add_meta_box('wfwc_upload_control', 'File Write Control', [$this, 'render_meta_box'], $s, 'side', 'high');
        }
    }

    public function render_meta_box($post)
    {
        $info = $this->get_target_info('upload');
        if (!$info['exists']) {
            echo "Error";
            return;
        }

        $s = $this->state();

        $saved_mode = isset($s['mode_upload']) ? $s['mode_upload'] : 'dir_only';
        $is_deep_active = $info['is_open'] ? ($saved_mode === 'all_recursive') : false;

        $disabled_attr = $info['is_open'] ? 'disabled' : '';

        echo "<div id='wfwc-mb-box' style='margin-top:10px; background:{$info['bg']}; border: 2px solid {$info['color']}; padding:12px; text-align:center; border-radius:6px;'>
            
            <div style='font-size:13px; margin-bottom:12px; display:flex; justify-content:space-between; align-items:center;'>
                <strong>📤 Upload:</strong> 
                <span id='wfwc-mb-status' style='font-weight:bold; color:{$info['color']};'>
                    {$info['status_text']} ({$info['perm']})
                </span>
            </div>";

        echo "<div style='display:flex; justify-content:center; margin-bottom:10px;'>
                <label class='wfwc-switch-container' style='margin-bottom:0;'>
                    <input type='checkbox' class='wfwc-switch-input' name='wfwc_deep_check_upload' value='1' 
                        " . checked($is_deep_active, true, false) . " 
                        $disabled_attr>
                    <div class='wfwc-switch-track'>
                        <div class='wfwc-switch-knob'></div>
                    </div>
                    <span class='wfwc-switch-label' style='margin-left:8px;'>🐢 Deep Mode</span>
                </label>
              </div>";

        echo "<button class='button wfwc-ajax-toggle {$info['btn_class']}' data-type='upload' style='width:100%; margin-bottom:5px;'>
                {$info['btn_text']}
              </button>";

        $dsp = ($info['is_open'] && $info['timer_text']) ? 'block' : 'none';
        echo "<div id='wfwc-mb-timer' style='display:$dsp; font-size:11px; color:#b91c1c; background:rgba(255,255,255,0.6); padding:4px; border-radius:4px;'>
                ⏱️ Auto Close: {$info['timer_text']}
              </div>";

        echo "</div>";
    }

}

new WP_File_Write_Control();