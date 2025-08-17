<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'production_database2' );

/** Database username */
define( 'DB_USER', 'root' );

/** Database password */
define( 'DB_PASSWORD', '' );

/** Database hostname */
define( 'DB_HOST', 'localhost' );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );


define('JWT_AUTH_SECRET_KEY', 'N-krvF*[ErT}rctWqGn=:uCls]c>G;}#[e:iO}67<msIOy[MFzrJ3m$1&e^E?zuU');
define('JWT_AUTH_CORS_ENABLE', true);

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         'hWOb<_clCEkO<= YN05+$ZAg(Zp[2 5I~68njC2R^{IXB&j.&@n]v?JCRm4aU)KG' );
define( 'SECURE_AUTH_KEY',  '[;u?BPN>/ia$LH!3s_c|N[AX^NI[a2.Ue3,1N;0+0V&K_,|,B[ausm:tn%4d2.}}' );
define( 'LOGGED_IN_KEY',    'euwsnn({Y#PG$`29z?S8<47baf`jPrXKef4M.$F3WD(1+pwzsQ?W9koy)z:rIX#y' );
define( 'NONCE_KEY',        '#nxE]%Gg}@hEainvB4#8d[PZ0gkbFMT?k8Rk[Xz,(Z(@ztS-]?(cL[{@{;]OQ5B_' );
define( 'AUTH_SALT',        'RtvJ1|eHtAExu?SWtNd?rXo8%3j$nVXhI<Fq<8%ul-8]*VT}e(+Gd7jSI&aiN6@w' );
define( 'SECURE_AUTH_SALT', '8RVq*7Hu44fDjVJm)LbS9v=Nn0VFQNG%=UCGJhjG}gj OA_SDbj+Zn #%Vj^@PMK' );
define( 'LOGGED_IN_SALT',   '8@H? eG1I6b;}i4O(r}%/T[P59G8Z~v3&owK8&+{uvsF2YWC:[*j|Qr@,APr?K$H' );
define( 'NONCE_SALT',       '^jMq>}UP{l?<:b^Y5,%(SO,wW00pA6#Q>f^@nNIF8u(W(c0X^i)S+vr)478F>4ln' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
