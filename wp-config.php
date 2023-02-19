<?php

/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the web site, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://wordpress.org/support/article/editing-wp-config-php/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', 'dulichphukien' );

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
define( 'AUTH_KEY',         'duz/h5|0dAwZbkkS-%wF!c.cLo?2>+G:XWi2gcY_&9{&V5M]DG|eAt[>X*|x-9yc' );
define( 'SECURE_AUTH_KEY',  'RYGwwFJ?P@|=^h6d;@7C.G>g@{gwC2)i`|!AI v!{&J2KOm}?.Su}u(Dgiu;7 ;9' );
define( 'LOGGED_IN_KEY',    'UcD^7KEAkxN)i++(q2!<uZ,2ZQ!B]V[&z8u7r3C|>(7VuMNs~cOqr)otec^;yH(Y' );
define( 'NONCE_KEY',        'R%:GRYcginRw0T@k)*:-SRw8 )>/[Nwh*>JA.WLsTGjH~i/Bus`?7ahA<k}oHIlr' );
define( 'AUTH_SALT',        '//nX+Mvm_kZ.vsTUVK_h$.$,Zu3:b`&;P3*R0?%m8`]ie.vBA1qvt/EQ0M]A1`+U' );
define( 'SECURE_AUTH_SALT', 'x5SdEigK[qwNAd0dnQOLnlJ3O`7t}@@|5| ywFh>tyg+q/9-WN[`9sWJ,QV$o[:r' );
define( 'LOGGED_IN_SALT',   ';>G5DzJtIyPT)<gb6.5m+jNVO8kh,@$ucG:(Ov=FeA:JEjtezzh[21SqO$F56yUA' );
define( 'NONCE_SALT',       ',o.0rL[J)e%+}<>EeEsh4c2Om&TrH=O0B8.-BGzST|$!D}wh{*<Xon8H)@W<[[&~' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
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
 * @link https://wordpress.org/support/article/debugging-in-wordpress/
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
