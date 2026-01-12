<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('WFWC_GitHub_Updater')) {

    class WFWC_GitHub_Updater
    {
        private $slug;
        private $plugin_data;
        private $username;
        private $repo;
        private $plugin_file;
        private $github_response;
        private $access_token;

        public function __construct($plugin_file, $github_username, $github_repo, $access_token = '')
        {
            $this->plugin_file = $plugin_file;
            $this->username = $github_username;
            $this->repo = $github_repo;
            $this->access_token = $access_token;

            add_filter("pre_set_site_transient_update_plugins", [$this, "modify_transient"], 10, 1);
            add_filter("plugins_api", [$this, "plugin_popup"], 10, 3);
            add_filter("upgrader_post_install", [$this, "after_install"], 10, 3);
        }

        private function get_repository_info()
        {
            if (!empty($this->github_response)) {
                return;
            }

            $url = "https://api.github.com/repos/{$this->username}/{$this->repo}/releases/latest";
            $args = [];

            if (!empty($this->access_token)) {
                $args['headers'][] = "Authorization: token {$this->access_token}";
            }

            $response = wp_remote_get($url, $args);

            if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
                return;
            }

            $this->github_response = json_decode(wp_remote_retrieve_body($response));
        }

        public function modify_transient($transient)
        {
            if (!property_exists($transient, 'checked')) {
                return $transient;
            }

            if ($checked = $transient->checked) {
                $this->get_repository_info();

                if (empty($this->github_response)) {
                    return $transient;
                }

                $plugin_slug = plugin_basename($this->plugin_file);
                $version_checked = isset($checked[$plugin_slug]) ? $checked[$plugin_slug] : '0.0';

                // Check comparison
                if (version_compare($this->github_response->tag_name, $version_checked, '>')) {
                    $new_files = $this->github_response->zipball_url;

                    if (!empty($this->github_response->assets) && count($this->github_response->assets) > 0) {
                        $new_files = $this->github_response->assets[0]->browser_download_url;
                    }

                    $package = $new_files;

                    $obj = new stdClass();
                    $obj->slug = $plugin_slug;
                    $obj->new_version = $this->github_response->tag_name;
                    $obj->url = $this->github_response->html_url;
                    $obj->package = $package;

                    $transient->response[$plugin_slug] = $obj;
                }
            }

            return $transient;
        }

        public function plugin_popup($result, $action, $args)
        {
            if ($action !== 'plugin_information') {
                return $result;
            }

            $plugin_slug = plugin_basename($this->plugin_file);

            if ($args->slug !== $plugin_slug) {
                return $result;
            }

            $this->get_repository_info();

            if (empty($this->github_response)) {
                return $result;
            }

            $plugin_data = get_plugin_data($this->plugin_file);

            $obj = new stdClass();
            $obj->name = $plugin_data['Name'];
            $obj->slug = $plugin_slug;
            $obj->version = $this->github_response->tag_name;
            $obj->author = $plugin_data['AuthorName'];
            $obj->homepage = $this->github_response->html_url;
            $obj->requires = '5.0'; // Default
            $obj->tested = '6.7'; // Default
            $obj->downloaded = 0;
            $obj->last_updated = $this->github_response->published_at;
            $obj->sections = [
                'description' => $this->github_response->body
            ];
            $obj->download_link = $this->github_response->zipball_url;
            if (!empty($this->github_response->assets)) {
                $obj->download_link = $this->github_response->assets[0]->browser_download_url;
            }

            return $obj;
        }

        public function after_install($response, $hook_extra, $result)
        {
            // Future use: rename folder if needed
            return $result;
        }
    }
}
