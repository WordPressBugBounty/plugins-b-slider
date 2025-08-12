<?php

if (! defined('ABSPATH')) {
    exit;
}

if (! defined('FS_LITE_SLUG')) {
    define('FS_LITE_SLUG', 'bblocksdk');
}

if (! defined('FS_LITE_VERSION')) {
    define('FS_LITE_VERSION', time());
}

if (! defined('FS_LITE_DIR')) {
    define('FS_LITE_DIR', plugin_dir_path(__FILE__));
}
