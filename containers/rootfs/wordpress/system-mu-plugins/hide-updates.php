<?php
/*
Plugin Name: Cloud-Native Update Hider
Description: 隐藏更新
Author: PFM Architect
Version: 1.0
*/

// 移除侧边栏 "仪表盘 -> 更新" 菜单
add_action('admin_menu', function () {
    remove_submenu_page('index.php', 'update-core.php');
}, 999);

// 隐藏顶部 Admin Bar 的更新小红点（双圆圈图标）
add_action('wp_before_admin_bar_render', function () {
    global $wp_admin_bar;
    $wp_admin_bar->remove_menu('updates');
});

// 彻底禁用后台关于 WordPress 核心更新的提示框
add_action('admin_head', function () {
    remove_action('admin_notices', 'update_nag', 3);
    remove_action('admin_notices', 'maintenance_nag', 10);
});

// 站点健康：屏蔽“更新”检测
add_filter('site_status_tests', function ($tests) {

    if (isset($tests['async']['background_updates'])) {
        unset($tests['async']['background_updates']);
    }

    if (isset($tests['direct']['wordpress_version'])) {
        unset($tests['direct']['wordpress_version']);
    }

    return $tests;
});
