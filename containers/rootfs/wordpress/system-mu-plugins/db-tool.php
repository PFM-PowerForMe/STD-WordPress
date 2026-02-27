<?php
/**
 * Plugin Name: PFM 数据库深度工作台
 * Description: 专为云原生架构打造的底层数据库管理与深度诊断工具。
 * Version: 1.2.0
 * Author: PFM Architect
 */

// 拦截非法直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ========================================================================
 * 1. 初始化与菜单注册
 * ========================================================================
 */
function pfm_db_register_admin_menu() {
    add_management_page(
        __( '数据库工作台', 'pfm-db-tool' ),
        __( '🗄️ 数据库工作台', 'pfm-db-tool' ),
        'manage_options',
        'pfm-db-tool',
        'pfm_db_render_router_page'
    );
}
add_action( 'admin_menu', 'pfm_db_register_admin_menu' );

/**
 * ========================================================================
 * 2. 路由控制器与操作处理 (Routing & Action Handler)
 * ========================================================================
 */
function pfm_db_render_router_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( '无权访问此页面。', 'pfm-db-tool' ) );
    }

    global $wpdb;
    $notice_message = '';
    $notice_type    = 'success';

    // 💥 拦截并处理 "一键优化" 请求
    if ( isset( $_GET['action'], $_GET['table'], $_GET['_wpnonce'] ) && 'optimize_table' === $_GET['action'] ) {
        $target_table = sanitize_text_field( wp_unslash( $_GET['table'] ) );
        
        // 防线 1：校验 Nonce 令牌
        if ( wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'pfm_optimize_' . $target_table ) ) {
            
            // 防线 2：底层强制校验表名是否存在 (防止 SQL 注入)
            $table_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->esc_like( $target_table ) ) );
            
            if ( $table_exists === $target_table ) {
                // 执行真正的底层优化指令
                $wpdb->query( "OPTIMIZE TABLE `{$target_table}`" );
                $notice_message = sprintf( __( '✅ 数据表 <strong>%s</strong> 已成功完成碎片整理与物理优化，存储空间已释放！', 'pfm-db-tool' ), esc_html( $target_table ) );
            } else {
                $notice_type    = 'error';
                $notice_message = __( '❌ 架构级拦截：非法或不存在的表名，拒绝执行 SQL。', 'pfm-db-tool' );
            }
        } else {
            $notice_type    = 'error';
            $notice_message = __( '❌ 安全令牌 (Nonce) 已过期或无效，请刷新页面后重试。', 'pfm-db-tool' );
        }
    }

    // 获取当前 Tab 参数
    $current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'tables';
    $tabs = array(
        'tables' => __( '📊 数据表概览', 'pfm-db-tool' ),
        'health' => __( '🩺 核心健康诊断', 'pfm-db-tool' ),
    );

    // 渲染头部
    echo '<div class="wrap">';
    echo '<h1 class="wp-heading-inline">' . esc_html__( 'PFM 数据库深度工作台', 'pfm-db-tool' ) . '</h1>';
    echo '<hr class="wp-header-end">';

    // 渲染操作结果通知 (如果有)
    if ( ! empty( $notice_message ) ) {
        echo '<div class="notice notice-' . esc_attr( $notice_type ) . ' is-dismissible"><p>' . wp_kses_post( $notice_message ) . '</p></div>';
    }

    // 渲染导航栏
    echo '<h2 class="nav-tab-wrapper">';
    foreach ( $tabs as $tab_key => $tab_name ) {
        $active_class = ( $current_tab === $tab_key ) ? ' nav-tab-active' : '';
        $tab_url      = esc_url( admin_url( 'tools.php?page=pfm-db-tool&tab=' . $tab_key ) );
        echo sprintf( '<a href="%s" class="nav-tab%s">%s</a>', $tab_url, $active_class, esc_html( $tab_name ) );
    }
    echo '</h2>';

    // 路由分发
    if ( 'health' === $current_tab ) {
        pfm_db_view_health_diagnostics();
    } else {
        pfm_db_view_tables_overview();
    }

    echo '</div>'; // End wrap
}

/**
 * ========================================================================
 * 3. 视图渲染：数据表概览 (View: Tables Overview)
 * ========================================================================
 */
function pfm_db_view_tables_overview() {
    global $wpdb;
    $tables = $wpdb->get_results( "SHOW TABLE STATUS" );

    $total_size = 0;
    $total_rows = 0;

    echo '<div class="card" style="max-width: 100%; padding: 15px; margin-top: 20px;">';
    echo '<p>' . esc_html__( '当前 WordPress 数据库底层表结构与物理存储占用一览：', 'pfm-db-tool' ) . '</p>';
    
    echo '<div class="pfm-table-responsive" style="overflow-x: auto; margin-top: 15px; width: 100%;">';
    echo '<table class="widefat striped" style="min-width: 700px;">';
    echo '<thead>
            <tr>
                <th>' . esc_html__( '表名 (Name)', 'pfm-db-tool' ) . '</th>
                <th>' . esc_html__( '引擎 (Engine)', 'pfm-db-tool' ) . '</th>
                <th>' . esc_html__( '行数 (Rows)', 'pfm-db-tool' ) . '</th>
                <th>' . esc_html__( '数据体积 (Data)', 'pfm-db-tool' ) . '</th>
                <th>' . esc_html__( '碎片空洞 (Free)', 'pfm-db-tool' ) . '</th>
                <th>' . esc_html__( '底层操作 (Action)', 'pfm-db-tool' ) . '</th>
            </tr>
          </thead>
          <tbody>';

    if ( $tables ) {
        foreach ( $tables as $table ) {
            $data_size  = (int) $table->Data_length;
            $index_size = (int) $table->Index_length;
            $data_free  = (int) $table->Data_free;
            
            $total_size += ( $data_size + $index_size );
            $total_rows += (int) $table->Rows;

            // 生成带 Nonce 的安全优化链接
            $optimize_url = wp_nonce_url(
                admin_url( 'tools.php?page=pfm-db-tool&tab=tables&action=optimize_table&table=' . urlencode( $table->Name ) ),
                'pfm_optimize_' . $table->Name
            );

            echo '<tr>';
            echo '<td><strong>' . esc_html( $table->Name ) . '</strong></td>';
            echo '<td>' . esc_html( $table->Engine ) . '</td>';
            echo '<td>' . esc_html( number_format_i18n( $table->Rows ) ) . '</td>';
            echo '<td>' . esc_html( size_format( $data_size, 2 ) ) . '</td>';
            
            // 如果有碎片，标黄显示
            $free_color = ( $data_free > 0 ) ? 'color:#dba617; font-weight:bold;' : 'color:#646970;';
            echo '<td style="' . $free_color . '">' . esc_html( size_format( $data_free, 2 ) ) . '</td>';
            
            // 操作按钮
            echo '<td>';
            if ( $data_free > 0 ) {
                echo '<a href="' . esc_url( $optimize_url ) . '" class="button button-primary button-small">' . esc_html__( '一键优化 (释放空间)', 'pfm-db-tool' ) . '</a>';
            } else {
                echo '<a href="' . esc_url( $optimize_url ) . '" class="button button-small" style="color:#a7aaad;">' . esc_html__( '重新组织', 'pfm-db-tool' ) . '</a>';
            }
            echo '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody>';
    echo '<tfoot>
            <tr>
                <th><strong>' . esc_html__( '汇总 (Total)', 'pfm-db-tool' ) . '</strong></th>
                <th>-</th>
                <th><strong>' . esc_html( number_format_i18n( $total_rows ) ) . '</strong></th>
                <th colspan="3"><strong>' . esc_html__( '总物理空间占用:', 'pfm-db-tool' ) . ' ' . esc_html( size_format( $total_size, 2 ) ) . '</strong></th>
            </tr>
          </tfoot>';
          
    echo '</table>';
    echo '</div>'; // 关闭滚动层
    echo '</div>';
}

/**
 * ========================================================================
 * 4. 视图渲染：健康诊断 (View: Health Diagnostics)
 * ========================================================================
 */
function pfm_db_view_health_diagnostics() {
    global $wpdb;

    $autoload_query = "SELECT SUM(LENGTH(option_value)) as total_size FROM {$wpdb->options} WHERE autoload = 'yes'";
    $autoload_size  = (int) $wpdb->get_var( $autoload_query );
    $top_options    = $wpdb->get_results( "SELECT option_name, LENGTH(option_value) as size FROM {$wpdb->options} WHERE autoload = 'yes' ORDER BY size DESC LIMIT 20" );
    $memory_limit       = ini_get( 'memory_limit' );
    $max_execution_time = ini_get( 'max_execution_time' );
    $tables = $wpdb->get_results( "SHOW TABLE STATUS" );

    echo '<div style="margin-top: 20px;">';

    // 模块 1: Autoload 内存占用分析
    echo '<h2>' . esc_html__( '1. Autoload 内存占用分析 (502 排查重点)', 'pfm-db-tool' ) . '</h2>';
    echo '<div class="card" style="max-width: 100%; padding: 15px; margin-bottom: 20px;">';
    echo '<p><strong>' . esc_html__( '当前 Autoload 累计占用内存:', 'pfm-db-tool' ) . '</strong> ' . esc_html( size_format( $autoload_size, 2 ) ) . '</p>';

    if ( $autoload_size > 819200 ) {
        echo '<div class="notice notice-error inline"><p>⚠️ <strong>' . esc_html__( '架构警告:', 'pfm-db-tool' ) . '</strong> ' . esc_html__( 'Autoload 数据过于臃肿！极易导致内存溢出 (OOM) 与 502 错误，建议排查下方异常巨型记录。', 'pfm-db-tool' ) . '</p></div>';
    } else {
        echo '<div class="notice notice-success inline"><p>✅ <strong>' . esc_html__( '状态良好:', 'pfm-db-tool' ) . '</strong> ' . esc_html__( 'Autoload 内存占用处于健康范围内。', 'pfm-db-tool' ) . '</p></div>';
    }

    echo '<div class="pfm-table-responsive" style="overflow-x: auto; margin-top: 15px; width: 100%;">';
    echo '<table class="widefat striped" style="min-width: 500px;">';
    echo '<thead>
            <tr>
                <th>' . esc_html__( '字段名 (Option Name)', 'pfm-db-tool' ) . '</th>
                <th>' . esc_html__( '占用体积 (Size)', 'pfm-db-tool' ) . '</th>
                <th>' . esc_html__( '快捷操作 (Action)', 'pfm-db-tool' ) . '</th>
            </tr>
          </thead>
          <tbody>';

    if ( $top_options ) {
        foreach ( $top_options as $opt ) {
            $size_bytes = (int) $opt->size;
            $alert_style = ( $size_bytes > 102400 ) ? 'style="color:#d63638; font-weight:bold;"' : '';
            
            echo '<tr>';
            echo '<td><code>' . esc_html( $opt->option_name ) . '</code></td>';
            echo '<td ' . $alert_style . '>' . esc_html( size_format( $size_bytes, 2 ) ) . '</td>';
            echo '<td><a href="' . esc_url( admin_url( 'options.php' ) ) . '" class="button button-small" target="_blank">' . esc_html__( '前往底层配置排查', 'pfm-db-tool' ) . '</a></td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="3">' . esc_html__( '暂无记录', 'pfm-db-tool' ) . '</td></tr>';
    }
    echo '</tbody></table></div></div>';

    // 模块 2: PHP 环境边界
    echo '<h2>' . esc_html__( '2. PHP 运行环境物理边界', 'pfm-db-tool' ) . '</h2>';
    echo '<div class="card" style="max-width: 100%; padding: 15px; margin-bottom: 20px;">';
    echo '<ul style="list-style-type: disc; padding-left: 20px;">';
    echo '<li><strong>' . esc_html__( '内存分配上限 (Memory Limit):', 'pfm-db-tool' ) . '</strong> <code>' . esc_html( $memory_limit ) . '</code></li>';
    echo '<li><strong>' . esc_html__( '进程执行超时 (Max Execution Time):', 'pfm-db-tool' ) . '</strong> <code>' . esc_html( $max_execution_time ) . 's</code></li>';
    echo '</ul></div>';

    // 模块 3: MariaDB 数据碎片监控
    echo '<h2>' . esc_html__( '3. 数据碎片监控 (Data Free / Overhead)', 'pfm-db-tool' ) . '</h2>';
    echo '<div class="card" style="max-width: 100%; padding: 15px;">';
    echo '<p>' . esc_html__( '检测底层 InnoDB 空洞。如碎片体积过大，请点击“一键优化”进行物理碎片整理，以恢复 Buffer Pool 命中率。', 'pfm-db-tool' ) . '</p>';
    
    echo '<div class="pfm-table-responsive" style="overflow-x: auto; margin-top: 15px; width: 100%;">';
    echo '<table class="widefat striped" style="min-width: 600px;">';
    echo '<thead>
            <tr>
                <th>' . esc_html__( '表名 (Table)', 'pfm-db-tool' ) . '</th>
                <th>' . esc_html__( '有效体积 (Data Size)', 'pfm-db-tool' ) . '</th>
                <th>' . esc_html__( '空洞碎片 (Data Free)', 'pfm-db-tool' ) . '</th>
                <th>' . esc_html__( '底层操作 (Action)', 'pfm-db-tool' ) . '</th>
            </tr>
          </thead>
          <tbody>';

    $has_fragmentation = false;
    
    if ( $tables ) {
        foreach ( $tables as $table ) {
            $data_free = (int) $table->Data_free;
            if ( $data_free > 0 || ! empty( $table->Comment ) ) {
                $has_fragmentation = true;
                $data_size = (int) $table->Data_length;
                $free_alert_style = ( $data_free > 1048576 ) ? 'color:#d63638; font-weight:bold;' : 'color:#dba617; font-weight:bold;';
                
                // 生成带 Nonce 的安全优化链接
                $optimize_url = wp_nonce_url(
                    admin_url( 'tools.php?page=pfm-db-tool&tab=health&action=optimize_table&table=' . urlencode( $table->Name ) ),
                    'pfm_optimize_' . $table->Name
                );

                echo '<tr>';
                echo '<td><strong>' . esc_html( $table->Name ) . '</strong></td>';
                echo '<td>' . esc_html( size_format( $data_size, 2 ) ) . '</td>';
                echo '<td style="' . $free_alert_style . '">' . esc_html( size_format( $data_free, 2 ) ) . '</td>';
                echo '<td><a href="' . esc_url( $optimize_url ) . '" class="button button-primary">' . esc_html__( '一键优化', 'pfm-db-tool' ) . '</a></td>';
                echo '</tr>';
            }
        }
    }
    
    if ( ! $has_fragmentation ) {
         echo '<tr><td colspan="4" style="text-align:center; color:#00a32a; padding: 20px;">🎉 ' . esc_html__( '当前数据库物理存储极其健康，未检测到任何存储空洞。', 'pfm-db-tool' ) . '</td></tr>';
    }
    
    echo '</tbody></table></div></div></div>'; // End container
}
