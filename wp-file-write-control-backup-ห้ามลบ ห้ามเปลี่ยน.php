<?php
/*
Plugin Name: WP File Write Control (Security Dashboard)
Description: แสดงสถานะความปลอดภัยเว็บไซต์ + ควบคุมสิทธิ์เขียนไฟล์ + ตรวจ Permission แบบเข้าใจง่าย
Version: 3.2.0
Author: IT Admin
*/

if (!defined('ABSPATH')) exit;

class WP_File_Write_Control {

    const OPTION_KEY   = 'wfwc_state';
    const TTL_SECONDS  = 600;
    const NONCE_ACTION = 'wfwc_nonce';
    const CRON_HOOK    = 'wfwc_auto_disable';
    const MENU_SLUG    = 'wfwc-control';

    public function __construct() {

        if (is_admin()) {
            add_action('admin_init', [$this, 'check_auto_disable']);
            add_action('admin_init', [$this, 'enqueue_styles']);
            add_action('wp_dashboard_setup', [$this, 'dashboard_widget']);
            add_action('admin_notices', [$this, 'plugins_notice']);
            add_action('admin_menu', [$this, 'admin_menu']);
            add_action('admin_post_wfwc_action', [$this, 'handle_action']);
        }

        add_filter('user_has_cap', [$this, 'block_caps'], 10, 1);
        add_action(self::CRON_HOOK, [$this, 'auto_disable']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
    }

    public function enqueue_styles() {
        $css = <<<'CSS'
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

        .wfwc-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px 30px;
            border-radius: 12px;
            margin-bottom: 40px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.2);
        }

        .wfwc-header h1 {
            margin: 0 0 10px 0;
            font-size: 32px;
            font-weight: 700;
            letter-spacing: -0.5px;
        }

        .wfwc-header p {
            margin: 0;
            font-size: 15px;
            opacity: 0.95;
            line-height: 1.5;
        }

        .wfwc-section-title {
            font-size: 18px;
            font-weight: 600;
            color: #1a1a1a;
            margin: 40px 0 25px 0;
            padding-bottom: 15px;
            border-bottom: 2px solid #e8e8e8;
        }

        .wfwc-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .wfwc-card {
            background: white;
            border-radius: 12px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08);
            border: 1px solid #e8e8e8;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .wfwc-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }

        .wfwc-card:hover {
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            transform: translateY(-4px);
        }

        .wfwc-card-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 20px;
        }

        .wfwc-card-icon {
            font-size: 40px;
            line-height: 1;
        }

        .wfwc-status-indicator {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .wfwc-status-indicator.active {
            background: #d4f5d4;
            color: #1b5e20;
        }

        .wfwc-status-indicator.inactive {
            background: #ffe8e8;
            color: #b71c1c;
        }

        .wfwc-status-indicator::before {
            content: "";
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: currentColor;
        }

        .wfwc-card-title {
            font-size: 18px;
            font-weight: 700;
            color: #1a1a1a;
            margin: 0 0 8px 0;
        }

        .wfwc-card-desc {
            font-size: 13px;
            color: #666;
            margin: 0 0 20px 0;
            line-height: 1.6;
        }

        .wfwc-card-action {
            display: flex;
            gap: 10px;
            margin-bottom: 12px;
        }

        .wfwc-btn {
            flex: 1;
            padding: 11px 16px;
            border: none;
            border-radius: 8px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-height: 42px;
        }

        .wfwc-btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
        }

        .wfwc-btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(102, 126, 234, 0.4);
        }

        .wfwc-btn-primary:active {
            transform: translateY(0);
        }

        .wfwc-btn-danger {
            background: #ff5252;
            color: white;
            box-shadow: 0 4px 12px rgba(255, 82, 82, 0.3);
        }

        .wfwc-btn-danger:hover {
            background: #ff1744;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(255, 82, 82, 0.4);
        }

        .wfwc-btn-danger:active {
            transform: translateY(0);
        }

        .wfwc-timer {
            font-size: 12px;
            color: #ff6b6b;
            padding: 10px 12px;
            background: #fff5f5;
            border-radius: 8px;
            border-left: 3px solid #ff6b6b;
            font-weight: 500;
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

        .wfwc-table thead {
            background: linear-gradient(90deg, #f5f7ff 0%, #f8f5ff 100%);
            border-bottom: 2px solid #e8e8e8;
        }

        .wfwc-table th {
            padding: 16px;
            text-align: left;
            font-weight: 600;
            color: #1a1a1a;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .wfwc-table td {
            padding: 14px 16px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 13px;
        }

        .wfwc-table tbody tr {
            transition: background-color 0.2s ease;
        }

        .wfwc-table tbody tr:hover {
            background: #f8f9ff;
        }

        .wfwc-table tbody tr.wfwc-risk {
            background: #fff5f5;
        }

        .wfwc-table tbody tr.wfwc-risk:hover {
            background: #fff0f0;
        }

        .wfwc-badge {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .wfwc-badge.ok {
            background: #d4f5d4;
            color: #1b5e20;
        }

        .wfwc-badge.risk {
            background: #ffe8e8;
            color: #b71c1c;
        }

        .wfwc-badge.type {
            background: #e3f2fd;
            color: #0d47a1;
            font-weight: 700;
        }

        .wfwc-badge.perm {
            background: #f5f5f5;
            color: #333;
            font-family: "Courier New", monospace;
            font-weight: 600;
        }

        .notice.wfwc-notice {
            border-left: 4px solid #667eea !important;
            border-radius: 8px !important;
            background: #f8f9ff !important;
        }

        .notice.wfwc-notice h2 {
            margin-top: 0 !important;
            color: #667eea;
        }

        .notice.wfwc-notice .wfwc-cards {
            margin-top: 20px;
        }

        .dashboard-widget-notice .wfwc-cards {
            margin: 0;
        }

        .wfwc-empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #999;
        }

        .wfwc-empty-state p {
            font-size: 14px;
            margin: 10px 0;
        }

        @media (max-width: 768px) {
            .wfwc-header {
                padding: 30px 20px;
            }

            .wfwc-header h1 {
                font-size: 24px;
            }

            .wfwc-cards {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .wfwc-card {
                padding: 20px;
            }

            .wfwc-table th,
            .wfwc-table td {
                padding: 10px;
                font-size: 12px;
            }

            .wfwc-btn {
                font-size: 12px;
                padding: 10px 12px;
            }
        }
        CSS;
        wp_enqueue_style('wfwc-style', false);
        wp_add_inline_style('wfwc-style', $css);
    }

    /* =========================
     * STATE
     ========================= */
    private function state() {
        return wp_parse_args(get_option(self::OPTION_KEY, []), [
            'upload' => false,
            'plugin' => false,
            'expire' => null,
        ]);
    }

    private function save($s) {
        update_option(self::OPTION_KEY, $s);
    }

    /* =========================
     * DIRS
     ========================= */
    private function upload_dirs() {
        return [WP_CONTENT_DIR . '/uploads'];
    }

    private function plugin_dirs() {
        return [
            WP_CONTENT_DIR . '/plugins',
            WP_CONTENT_DIR . '/themes',
            WP_CONTENT_DIR . '/upgrade',
        ];
    }

    private function chmod_dirs($dirs, $mode) {
        foreach ($dirs as $dir) {
            if (is_dir($dir)) @chmod($dir, $mode);
        }
    }

    /* =========================
     * ACTIONS
     ========================= */
    public function handle_action() {

        if (!current_user_can('manage_options')) wp_die('Denied');
        check_admin_referer(self::NONCE_ACTION);

        $s = $this->state();
        $action = sanitize_key($_POST['do'] ?? '');

        switch ($action) {
            case 'enable_upload':
                $this->chmod_dirs($this->upload_dirs(), 0775);
                $s['upload'] = true;
                break;

            case 'disable_upload':
                $this->chmod_dirs($this->upload_dirs(), 0555);
                $s['upload'] = false;
                break;

            case 'enable_plugin':
                $this->chmod_dirs($this->plugin_dirs(), 0775);
                $s['plugin'] = true;
                break;

            case 'disable_plugin':
                $this->chmod_dirs($this->plugin_dirs(), 0555);
                $s['plugin'] = false;
                break;
        }

        if ($s['upload'] || $s['plugin']) {
            $s['expire'] = time() + self::TTL_SECONDS;
            wp_schedule_single_event($s['expire'], self::CRON_HOOK);
        } else {
            $s['expire'] = null;
            wp_clear_scheduled_hook(self::CRON_HOOK);
        }

        $this->save($s);
        wp_safe_redirect(wp_get_referer());
        exit;
    }

    public function auto_disable() {
        $this->chmod_dirs($this->upload_dirs(), 0555);
        $this->chmod_dirs($this->plugin_dirs(), 0555);
        $this->save(['upload'=>false,'plugin'=>false,'expire'=>null]);
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public function check_auto_disable() {
        $s = $this->state();
        if ($s['expire'] && time() > $s['expire']) {
            $this->auto_disable();
        }
    }

    public function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    /* =========================
     * SECURITY
     ========================= */
    public function block_caps($caps) {
        $s = $this->state();
        if (!$s['upload'])  $caps['upload_files'] = false;
        if (!$s['plugin']) {
            foreach ([
                'install_plugins','update_plugins','delete_plugins',
                'edit_plugins','edit_themes','update_themes',
                'delete_themes','switch_themes'
            ] as $cap) {
                $caps[$cap] = false;
            }
        }
        return $caps;
    }

    /* =========================
     * UI COMPONENTS
     ========================= */
    private function control_ui() {
        $s = $this->state();
        $expire_time = $s['expire'] ? date('H:i:s', $s['expire']) : null;
        ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 25px; margin-bottom: 40px;">
            <div style="background: white; border-radius: 12px; padding: 30px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border: 1px solid #e8e8e8; position: relative; overflow: hidden; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);" class="wfwc-card-hover">
                <div style="position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);"></div>
                
                <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px;">
                    <div style="font-size: 40px; line-height: 1;">📤</div>
                    <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; letter-spacing: 0.3px; background: <?= $s['upload'] ? '#d4f5d4' : '#ffe8e8' ?>; color: <?= $s['upload'] ? '#1b5e20' : '#b71c1c' ?>;">
                        <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: currentColor;"></span>
                        <?= $s['upload'] ? 'เปิด' : 'ปิด' ?>
                    </span>
                </div>
                
                <h3 style="font-size: 18px; font-weight: 700; color: #1a1a1a; margin: 0 0 8px 0;">Upload Files</h3>
                <p style="font-size: 13px; color: #666; margin: 0 0 20px 0; line-height: 1.6;">ควบคุมการอัปโหลดไฟล์ไปยังโฟลเดอร์ uploads</p>

                <form method="post" action="<?= admin_url('admin-post.php') ?>" style="margin: 0;">
                    <input type="hidden" name="action" value="wfwc_action">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <button style="width: 100%; padding: 11px 16px; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 42px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);" 
                        name="do" value="<?= $s['upload'] ? 'disable_upload' : 'enable_upload' ?>" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(102, 126, 234, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(102, 126, 234, 0.3)';">
                        <?= $s['upload'] ? '🔒 ปิด' : '🔓 เปิด 10 นาที' ?>
                    </button>
                </form>

                <?php if ($s['upload'] && $expire_time): ?>
                    <div style="font-size: 12px; color: #ff6b6b; padding: 10px 12px; background: #fff5f5; border-radius: 8px; border-left: 3px solid #ff6b6b; font-weight: 500; margin-top: 12px;">⏱️ ปิดอัตโนมัติ: <?= $expire_time ?></div>
                <?php endif; ?>
            </div>

            <div style="background: white; border-radius: 12px; padding: 30px; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border: 1px solid #e8e8e8; position: relative; overflow: hidden; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);" class="wfwc-card-hover">
                <div style="position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);"></div>
                
                <div style="display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: 20px;">
                    <div style="font-size: 40px; line-height: 1;">🔌</div>
                    <span style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; letter-spacing: 0.3px; background: <?= $s['plugin'] ? '#d4f5d4' : '#ffe8e8' ?>; color: <?= $s['plugin'] ? '#1b5e20' : '#b71c1c' ?>;">
                        <span style="display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: currentColor;"></span>
                        <?= $s['plugin'] ? 'เปิด' : 'ปิด' ?>
                    </span>
                </div>
                
                <h3 style="font-size: 18px; font-weight: 700; color: #1a1a1a; margin: 0 0 8px 0;">Plugin & Theme</h3>
                <p style="font-size: 13px; color: #666; margin: 0 0 20px 0; line-height: 1.6;">ควบคุมการติดตั้งและแก้ไข Plugin & Theme</p>

                <form method="post" action="<?= admin_url('admin-post.php') ?>" style="margin: 0;">
                    <input type="hidden" name="action" value="wfwc_action">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <button style="width: 100%; padding: 11px 16px; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; transition: all 0.2s ease; display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 42px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);" 
                        name="do" value="<?= $s['plugin'] ? 'disable_plugin' : 'enable_plugin' ?>" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(102, 126, 234, 0.4)';" onmouseout="this.style.transform='translateY(0)'; this.style.boxShadow='0 4px 12px rgba(102, 126, 234, 0.3)';">
                        <?= $s['plugin'] ? '🔒 ปิด' : '🔓 เปิด 10 นาที' ?>
                    </button>
                </form>

                <?php if ($s['plugin'] && $expire_time): ?>
                    <div style="font-size: 12px; color: #ff6b6b; padding: 10px 12px; background: #fff5f5; border-radius: 8px; border-left: 3px solid #ff6b6b; font-weight: 500; margin-top: 12px;">⏱️ ปิดอัตโนมัติ: <?= $expire_time ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /* =========================
     * AUDIT TABLE
     ========================= */
    private function audit_table() {

        $paths = [
            'Root'       => ABSPATH,
            'wp-content' => WP_CONTENT_DIR,
            'uploads'    => WP_CONTENT_DIR.'/uploads',
            'plugins'    => WP_CONTENT_DIR.'/plugins',
            'themes'     => WP_CONTENT_DIR.'/themes',
            'upgrade'    => WP_CONTENT_DIR.'/upgrade',
            'wp-config'  => ABSPATH.'wp-config.php',
            '.htaccess'  => ABSPATH.'.htaccess',
        ];
        ?>

        <div style="background: white; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0, 0, 0, 0.08); border: 1px solid #e8e8e8;">
            <table style="width: 100%; border-collapse: collapse;">
                <thead style="background: linear-gradient(90deg, #f5f7ff 0%, #f8f5ff 100%); border-bottom: 2px solid #e8e8e8;">
                <tr>
                    <th style="padding: 16px; text-align: left; font-weight: 600; color: #1a1a1a; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">📁 Path</th>
                    <th style="padding: 16px; text-align: left; font-weight: 600; color: #1a1a1a; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">🏷️ Type</th>
                    <th style="padding: 16px; text-align: left; font-weight: 600; color: #1a1a1a; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">🔢 Permission</th>
                    <th style="padding: 16px; text-align: left; font-weight: 600; color: #1a1a1a; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">💡 แนะนำ</th>
                    <th style="padding: 16px; text-align: left; font-weight: 600; color: #1a1a1a; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px;">✓ สถานะ</th>
                </tr>
                </thead>
                <tbody>

                <?php foreach ($paths as $name=>$path):

                    $exists = file_exists($path);
                    $type = $exists ? (is_dir($path)?'DIR':'FILE') : '-';
                    $perm = $exists ? substr(sprintf('%o', fileperms($path)), -3) : '-';

                    $risk = false;
                    if ($type==='DIR' && $perm>775) $risk=true;
                    if ($type==='FILE' && $perm>644) $risk=true;

                    ?>

                    <tr style="background: <?= $risk ? '#fff5f5' : 'transparent' ?>; border-bottom: 1px solid #f0f0f0; transition: background-color 0.2s ease;">
                        <td style="padding: 14px 16px; font-size: 13px;"><strong><?= esc_html($name) ?></strong></td>
                        <td style="padding: 14px 16px; font-size: 13px;"><span style="display: inline-block; padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; letter-spacing: 0.3px; background: #e3f2fd; color: #0d47a1;"><?= $type ?></span></td>
                        <td style="padding: 14px 16px; font-size: 13px;"><span style="display: inline-block; padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; letter-spacing: 0.3px; background: #f5f5f5; color: #333; font-family: 'Courier New', monospace;"><?= $perm ?></span></td>
                        <td style="padding: 14px 16px; font-size: 13px;"><?= $type==='DIR'?'755/775':'644/444' ?></td>
                        <td style="padding: 14px 16px; font-size: 13px;">
                            <span style="display: inline-block; padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; letter-spacing: 0.3px; background: <?= $risk ? '#ffe8e8' : '#d4f5d4' ?>; color: <?= $risk ? '#b71c1c' : '#1b5e20' ?>;">
                                <?= $risk?'⚠️ เสี่ยง':'✓ OK' ?>
                            </span>
                        </td>
                    </tr>

                <?php endforeach; ?>

                </tbody>
            </table>
        </div>
        <?php
    }

    /* =========================
     * DASHBOARD
     ========================= */
    public function dashboard_widget() {

        wp_add_dashboard_widget(
            'wfwc_status',
            '🔐 Website Security Status',
            function () {
                echo '<div class="dashboard-widget-notice">';
                $this->control_ui();
                echo '</div>';
            }
        );

        global $wp_meta_boxes;
        if (isset($wp_meta_boxes['dashboard']['normal']['core']['wfwc_status'])) {
            $widget = $wp_meta_boxes['dashboard']['normal']['core']['wfwc_status'];
            unset($wp_meta_boxes['dashboard']['normal']['core']['wfwc_status']);
            $wp_meta_boxes['dashboard']['normal']['core'] =
                ['wfwc_status'=>$widget] + $wp_meta_boxes['dashboard']['normal']['core'];
        }
    }

    /* =========================
     * PLUGINS PAGE
     ========================= */
    public function plugins_notice() {
        if ($GLOBALS['pagenow']!=='plugins.php') return;
        echo '<div style="border-left: 4px solid #667eea; border-radius: 8px; background: #f8f9ff; padding: 20px; margin: 20px 0;"><h2 style="margin-top: 0 !important; color: #667eea;">🔐 File Write Control</h2>';
        $this->control_ui();
        echo '<hr style="margin: 30px 0 25px 0; border: none; border-top: 1px solid #e8e8e8;">';
        echo '<h3 style="margin-top: 0;">📊 Permission Audit</h3>';
        $this->audit_table();
        echo '</div>';
    }

    /* =========================
     * MENU
     ========================= */
    public function admin_menu() {
        add_menu_page(
            'File Write Control',
            '🔐 File Write Control',
            'manage_options',
            self::MENU_SLUG,
            [$this,'menu_page'],
            'dashicons-lock',
            3
        );
    }

    public function menu_page() {
        ?>
        <div style="background: #f8f9fa; padding: 20px 0; min-height: 100vh;">
            <div style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
                <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 40px 30px; border-radius: 12px; margin-bottom: 40px; box-shadow: 0 10px 30px rgba(102, 126, 234, 0.2);">
                    <h1 style="margin: 0 0 10px 0; font-size: 32px; font-weight: 700; letter-spacing: -0.5px;">🔐 File Write Control</h1>
                    <p style="margin: 0; font-size: 15px; opacity: 0.95; line-height: 1.5;">ควบคุมสิทธิ์เขียนไฟล์และตรวจสอบสถานะความปลอดภัยของเว็บไซต์ WordPress ของคุณ</p>
                </div>

                <h2 style="font-size: 18px; font-weight: 600; color: #1a1a1a; margin: 40px 0 25px 0; padding-bottom: 15px; border-bottom: 2px solid #e8e8e8;">⚙️ ควบคุมการเข้าถึง</h2>
                <?php $this->control_ui(); ?>

                <h2 style="font-size: 18px; font-weight: 600; color: #1a1a1a; margin: 40px 0 25px 0; padding-bottom: 15px; border-bottom: 2px solid #e8e8e8;">📊 ตรวจสอบ Permission ไฟล์</h2>
                <?php $this->audit_table(); ?>
            </div>
        </div>
        <?php
    }
}

new WP_File_Write_Control();