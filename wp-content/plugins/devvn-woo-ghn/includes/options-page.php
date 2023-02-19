<?php
defined( 'ABSPATH' ) or die( 'No script kiddies please!' );
$current_tab = isset($_REQUEST['tab']) ? esc_html($_REQUEST['tab']) : 'general';
?>
<div class="wrap">
	<h1><?php _e('Giao hàng nhanh','devvn-ghn');?></h1>

    <h2 class="nav-tab-wrapper devvn-nav-tab-wrapper">
        <a href="?page=devvn-woo-ghn&tab=general" class="nav-tab <?php echo ($current_tab == 'general') ? 'nav-tab-active' : '' ?>"> <?php _e('Cài đặt chung', 'devvn-ghn'); ?></a>
        <a href="?page=devvn-woo-ghn&tab=hubs" class="nav-tab <?php echo ($current_tab == 'hubs') ? 'nav-tab-active' : '' ?>"> <?php _e('Cửa hàng/Kho', 'devvn-ghn'); ?></a>
        <a href="?page=devvn-woo-ghn&tab=license" class="nav-tab <?php echo ($current_tab == 'license') ? 'nav-tab-active' : '' ?>"> <?php _e('License', 'devvn-ghn'); ?></a>
        <a href="?page=devvn-woo-ghn&tab=about" class="nav-tab <?php echo ($current_tab == 'about') ? 'nav-tab-active' : '' ?>"> <?php _e('Giới thiệu', 'devvn-ghn'); ?></a>
    </h2>
    <?php
    switch ($current_tab) {
        case 'general': include('options-generals.php');
            break;
        case 'hubs': include('options-allhubs.php');
            break;
            break;
        case 'license': include('options-license.php');
            break;
        case 'about': include('options-about.php');
            break;
    }
    ?>
</div>