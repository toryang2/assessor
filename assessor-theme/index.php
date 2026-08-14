<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="A comprehensive digital archiving and management system for real property records and historical data for Philippine Local Government Units.">
    <meta name="robots" content="noindex, nofollow">
    <?php wp_head(); ?>
    <!-- <title><?php bloginfo('name'); ?> - Property Assessor</title> -->
</head>
<body <?php body_class(); ?>>
    <div id="assessor-app-root">
        <div id="wp-loading-screen" style="display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; background: #f0f4f8;">
            <div style="
                width: 50px;
                height: 50px;
                border: 4px solid #e3e8ef;
                border-top: 4px solid #2563eb;
                border-radius: 50%;
                animation: spin 1s linear infinite;
                margin-bottom: 1rem;
            "></div>
            <h2 style="color: #374151; margin: 0; font-size: 1.25rem; font-weight: 500;">Loading Property Assessor System...</h2>
            <p style="color: #6b7280; margin: 0.5rem 0 0 0; font-size: 0.875rem;">Please wait while the system initializes</p>
        </div>
    </div>
    
    <style>
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
    </style>
    <?php wp_footer(); ?>
    <script>
        console.log('WordPress theme loaded');
        console.log('React app container ready:', document.getElementById('assessor-app-root'));
        setTimeout(function() {
            if (typeof React !== 'undefined') {
                console.log('✅ React app is running successfully!');
                console.log('React version:', React.version);
            } else {
                console.error('❌ React not available');
            }
        }, 1000);
    </script>
</body>
</html>
