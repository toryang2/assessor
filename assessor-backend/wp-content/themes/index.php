<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Property Assessor System">
    <meta name="robots" content="noindex, nofollow">
    <?php wp_head(); ?>
    <title><?php bloginfo('name'); ?> - Property Assessor</title>
</head>
<body <?php body_class(); ?>>
    <div id="assessor-app-root">
        <div style="text-align: center; padding: 2rem; color: #666;">
            <h2>Loading Property Assessor System...</h2>
            <p>If you see this message for more than a few seconds, check the browser console for errors.</p>
        </div>
    </div>
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
