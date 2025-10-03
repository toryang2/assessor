<?php
/**
 * Assessor Theme Functions
 * A clean WordPress theme for the React Property Assessor application
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Remove WordPress admin bar for non-admin users
if (!current_user_can('administrator')) {
    add_filter('show_admin_bar', '__return_false');
}

// Remove WordPress version from head
remove_action('wp_head', 'wp_generator');

// Remove WordPress emoji scripts
remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');

// Remove WordPress feed links
remove_action('wp_head', 'feed_links', 2);
remove_action('wp_head', 'feed_links_extra', 3);

// Remove WordPress RSD link
remove_action('wp_head', 'rsd_link');

// Remove WordPress wlwmanifest link
remove_action('wp_head', 'wlwmanifest_link');

// Remove WordPress shortlink
remove_action('wp_head', 'wp_shortlink_wp_head');

// Remove WordPress REST API link
remove_action('wp_head', 'rest_output_link_wp_head');

// Remove WordPress oEmbed links
remove_action('wp_head', 'wp_oembed_add_discovery_links');

// Customize login page
function assessor_custom_login_logo() {
    $logo_url = esc_url(get_option('assessor_app_logo_url', ''));
    $has_logo = !empty($logo_url);
    echo '<style type="text/css">';
    if ($has_logo) {
        echo '#login h1 a {
            background-image: url(' . $logo_url . ') !important;
            background-size: contain !important;
            background-repeat: no-repeat !important;
            background-position: center center !important;
            width: 320px !important;
            height: 80px !important;
            text-indent: -9999px !important;
            overflow: hidden !important;
            display: block !important;
        }';
    } else {
        echo '#login h1 a {
            background-image: none !important;
            background-size: contain !important;
            width: 100% !important;
            height: 60px !important;
            text-indent: 0 !important;
            font-size: 24px !important;
            line-height: 60px !important;
            color: #1e3a8a !important;
            text-decoration: none !important;
        }
        #login h1 a:before {
            content: "Property Assessor System";
        }';
    }
    echo '</style>';
}
add_action('login_head', 'assessor_custom_login_logo');

// Change login logo URL
function assessor_login_logo_url() {
    return home_url();
}
add_filter('login_headerurl', 'assessor_login_logo_url');

// Change login logo title
function assessor_login_logo_url_title() {
    return get_bloginfo('name');
}
add_filter('login_headertext', 'assessor_login_logo_url_title');

// Enqueue React app scripts
function assessor_enqueue_react_app() {
    // Get the build directory path
    $build_dir = get_template_directory() . '/assets';
    
    // Check if assets directory exists
    if (!is_dir($build_dir)) {
        error_log('Assets directory not found: ' . $build_dir);
        return;
    }
    
    // Find the main JavaScript file in static/js/
    $js_files = glob($build_dir . '/static/js/main.*.js');
    if (!empty($js_files)) {
        $js_file = basename($js_files[0]);
        $js_url = get_template_directory_uri() . '/assets/static/js/' . $js_file;
        
        wp_enqueue_script(
            'assessor-react-app',
            $js_url,
            array(),
            '1.0.0',
            true
        );
        
        // Add inline script for React configuration
        global $wpdb;
        $table = $wpdb->prefix . 'assessor_settings';
        $row = $wpdb->get_row("SELECT app_logo_url, header_province, header_municipality, header_office FROM $table ORDER BY id DESC LIMIT 1", ARRAY_A);
        if (!$row) {
            $row = array(
                'app_logo_url' => '',
                'header_province' => 'Province of Bukidnon',
                'header_municipality' => 'MUNICIPALITY OF KITAOTAO',
                'header_office' => 'OFFICE OF THE MUNICIPAL ASSESSOR',
            );
        }
        wp_add_inline_script('assessor-react-app', '
            try {
                window.REACT_APP_BASE_URL = "' . get_site_url() . '/wp-json/assessor/v1";
                window.__PUBLIC_URL__ = "' . get_template_directory_uri() . '/assets";
                window.__ASSESSOR_SETTINGS__ = ' . wp_json_encode($row) . ';
                console.log("React app script loaded from: ' . $js_url . '");
            } catch (error) {
                console.error("Error setting React app configuration:", error);
            }
        ', 'before');
        
        // Log the script URL for debugging
        error_log('Loading React script: ' . $js_url);
        
    } else {
        error_log('No JavaScript files found in: ' . $build_dir . '/static/js/');
        error_log('Searched pattern: ' . $build_dir . '/static/js/main.*.js');
        
        // List all files in the js directory for debugging
        $js_dir = $build_dir . '/static/js';
        if (is_dir($js_dir)) {
            $all_files = scandir($js_dir);
            error_log('Files in js directory: ' . print_r($all_files, true));
        }
    }
    
    // Find and enqueue the main CSS file
    $css_files = glob($build_dir . '/static/css/main.*.css');
    if (!empty($css_files)) {
        $css_file = basename($css_files[0]);
        $css_url = get_template_directory_uri() . '/assets/static/css/' . $css_file;
        
        wp_enqueue_style(
            'assessor-react-app',
            $css_url,
            array(),
            '1.0.0'
        );
        
        // Log the CSS URL for debugging
        error_log('Loading React CSS: ' . $css_url);
    } else {
        error_log('No CSS files found in: ' . $build_dir . '/static/css/');
    }
}
add_action('wp_enqueue_scripts', 'assessor_enqueue_react_app');

// Enqueue Arial web font for consistent typography
function assessor_enqueue_arial_font() {
    // Add Arial as a web font using Google Fonts fallback
    wp_enqueue_style(
        'assessor-arial-font',
        'https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap',
        array(),
        '1.0.0'
    );
    
    // Add inline CSS to set Arial as the primary font family
    wp_add_inline_style('assessor-arial-font', '
        * {
            font-family: "Inter","Roboto","Helvetica","Arial",sans-serif !important;
        }
        body, html {
            font-family: "Inter","Roboto","Helvetica","Arial",sans-serif !important;
        }
        .wp-admin, .wp-content, .wp-header, .wp-footer, .wp-sidebar, .wp-main, 
        .wp-widget, .wp-menu, .wp-submenu, .wp-toolbar, .wp-admin-bar, 
        .wp-notification, .wp-dashboard, .wp-post, .wp-page, .wp-comment, 
        .wp-form, .wp-input, .wp-button, .wp-link, .wp-title, .wp-meta, 
        .wp-excerpt, .wp-content-area, .wp-sidebar-area, .wp-header-area, 
        .wp-footer-area, #wpadminbar, #adminmenu, #adminmenuback, #adminmenuwrap,
        #adminmenu li, #adminmenu a, #adminmenu .wp-submenu, #adminmenu .wp-submenu a,
        .wp-toolbar, .wp-toolbar *, .wp-header *, .wp-content *, .wp-footer * {
            font-family: "Inter","Roboto","Helvetica","Arial",sans-serif !important;
        }
    ');
}
add_action('wp_enqueue_scripts', 'assessor_enqueue_arial_font');
add_action('admin_enqueue_scripts', 'assessor_enqueue_arial_font');

// Add theme support
function assessor_theme_setup() {
    // Add theme support for various features
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('html5', array(
        'search-form',
        'comment-form',
        'comment-list',
        'gallery',
        'caption',
    ));
    
    // Set content width
    if (!isset($content_width)) {
        $content_width = 1200;
    }
}
add_action('after_setup_theme', 'assessor_theme_setup');

// Output favicon and apple-touch-icon from saved settings (frontend index.html equivalents)
function assessor_output_favicons_from_settings() {
    $logo_url = esc_url(get_option('assessor_app_logo_url', ''));
    if (!empty($logo_url)) {
        echo "\n<link rel=\"icon\" href=\"{$logo_url}\" />\n";
        echo "<link rel=\"apple-touch-icon\" href=\"{$logo_url}\" />\n";
    }
}
add_action('wp_head', 'assessor_output_favicons_from_settings', 99);

// Customize WordPress title
function assessor_custom_title($title) {
    if (is_front_page()) {
        return 'Property Assessor System';
    }
    return $title;
}
add_filter('wp_title', 'assessor_custom_title');

// Remove WordPress default widgets
function assessor_remove_default_widgets() {
    unregister_widget('WP_Widget_Pages');
    unregister_widget('WP_Widget_Calendar');
    unregister_widget('WP_Widget_Archives');
    unregister_widget('WP_Widget_Links');
    unregister_widget('WP_Widget_Meta');
    unregister_widget('WP_Widget_Search');
    unregister_widget('WP_Widget_Text');
    unregister_widget('WP_Widget_Categories');
    unregister_widget('WP_Widget_Recent_Posts');
    unregister_widget('WP_Widget_Recent_Comments');
    unregister_widget('WP_Widget_RSS');
    unregister_widget('WP_Widget_Tag_Cloud');
    unregister_widget('WP_Nav_Menu_Widget');
}
add_action('widgets_init', 'assessor_remove_default_widgets');

// Disable WordPress comments
function assessor_disable_comments() {
    // Close comments on the front-end
    add_filter('comments_open', '__return_false', 20, 2);
    add_filter('pings_open', '__return_false', 20, 2);
    
    // Hide existing comments
    add_filter('comments_array', '__return_empty_array', 10, 2);
    
    // Remove comments page in admin
    add_action('admin_menu', function() {
        remove_menu_page('edit-comments.php');
    });
    
    // Remove comments links from admin bar
    add_action('wp_before_admin_bar_render', function() {
        global $wp_admin_bar;
        $wp_admin_bar->remove_menu('comments');
    });
}
add_action('init', 'assessor_disable_comments');

// Remove WordPress dashboard widgets
function assessor_remove_dashboard_widgets() {
    remove_meta_box('dashboard_right_now', 'dashboard', 'normal');
    remove_meta_box('dashboard_activity', 'dashboard', 'normal');
    remove_meta_box('dashboard_quick_press', 'dashboard', 'side');
    remove_meta_box('dashboard_primary', 'dashboard', 'side');
}
add_action('wp_dashboard_setup', 'assessor_remove_dashboard_widgets');

// Customize admin footer
function assessor_custom_admin_footer() {
    echo 'Property Assessor System - Powered by WordPress';
}
add_filter('admin_footer_text', 'assessor_custom_admin_footer');

// Handle React app routing - minimal approach
add_action('init', function() {
    // Only apply this for our theme
    if (get_template() === 'assessor-theme') {
        // Simple rewrite rule to serve our theme for all routes
        add_rewrite_rule(
            '^.*$',
            'index.php',
            'top'
        );
        
        // Flush rewrite rules (only once)
        if (get_option('assessor_theme_rewrite_flushed') !== '1') {
            flush_rewrite_rules();
            update_option('assessor_theme_rewrite_flushed', '1');
        }
    }
});

// CRITICAL: Disable WordPress authentication checks for React app ONLY
// This prevents WordPress from redirecting to wp-login.php for React app
add_action('init', function() {
    // Only apply this for our theme
    if (get_template() === 'assessor-theme') {
        // Only disable redirects for React app routes, not admin
        if (!is_admin() && !wp_doing_ajax() && strpos($_SERVER['REQUEST_URI'], '/wp-admin') === false) {
            // Remove WordPress authentication filters that cause redirects
            remove_filter('template_redirect', 'wp_redirect_admin_locations', 1000);
            remove_action('template_redirect', 'wp_redirect_admin_locations');
            
            // Allow access to all frontend routes without authentication
            add_filter('rest_authentication_errors', function($result) {
                $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
                if (strpos($uri, '/wp-json/assessor/') !== false) {
                    return null; // Allow custom API access (dev convenience)
                }
                return $result;
            }, 5);
        }
    }
});

// ADDITIONAL: Prevent WordPress from redirecting to login page for React app ONLY
add_action('template_redirect', function() {
    // Only apply this for our theme
    if (get_template() === 'assessor-theme') {
        // If this is a frontend request and not admin, and not wp-admin
        if (!is_admin() && !wp_doing_ajax() && strpos($_SERVER['REQUEST_URI'], '/wp-admin') === false) {
            // Remove any authentication redirects
            remove_action('template_redirect', 'wp_redirect_admin_locations');
            
            // Log for debugging
            error_log('🔍 Assessor Theme: React app request detected, preventing WordPress redirects');
        }
    }
}, 1); // Priority 1 to run early

// DISABLE WordPress authentication requirements for React app ONLY
// Note: 'auth_redirect' is an action that receives one argument. Use add_action with correct arg count.
add_action('auth_redirect', function($arg) {
    // Only apply this for our theme
    if (get_template() === 'assessor-theme') {
        // If this is a React app request, log and allow
        if (!is_admin() && !wp_doing_ajax() && strpos($_SERVER['REQUEST_URI'], '/wp-admin') === false) {
            error_log('🔍 Assessor Theme: Auth redirect encountered on frontend; allowing access');
            // Can't short-circuit via return here; prevention handled in earlier hooks
        }
    }
}, 10, 1);

// FINAL: Override WordPress authentication for React app ONLY
add_action('wp', function() {
    // Only apply this for our theme
    if (get_template() === 'assessor-theme') {
        // If this is a React app request and not admin, and not wp-admin
        if (!is_admin() && !wp_doing_ajax() && strpos($_SERVER['REQUEST_URI'], '/wp-admin') === false) {
            // Force WordPress to not require authentication for React app
            if (!defined('WP_USE_THEMES')) {
                define('WP_USE_THEMES', true);
            }
            
            // Only remove specific authentication hooks, not all
            remove_action('template_redirect', 'wp_redirect_admin_locations');
            
            error_log('🔍 Assessor Theme: WordPress auth hooks removed for React app only');
        }
    }
}, 1);

// Note: React Router will handle all client-side routing automatically
// WordPress should not interfere with frontend requests
