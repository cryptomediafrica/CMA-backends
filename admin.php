<?php

/*
 * ==========================================================
 * ADMINISTRATION PAGE
 * ==========================================================
 *
 */

$installation = false;
if (!file_exists('config.php')) {
    $installation = true;
    $file = fopen('config.php', 'w');
    fwrite($file, '<?php define("BXC_URL", "") ?>');
    fclose($file);
}
require('functions.php');
if (!defined('BXC_USER')) {
    $installation = true;
}
$minify = $installation ? false : bxc_settings_get('minify');

function bxc_box_installation() { ?>
<div class="bxc-main bxc-installation bxc-box">
    <form>
        <div class="bxc-info"></div>
        <div class="bxc-top">
            <img src="media/logo.svg" />
            <div class="bxc-title">
                Installation
            </div>
            <div class="bxc-text">
                Please complete the installation process.
            </div>
        </div>
        <div id="user" class="bxc-input">
            <span>
                Username
            </span>
            <input type="text" required />
        </div>
        <div id="password" class="bxc-input">
            <span>
                Password
            </span>
            <input type="password" required />
        </div>
        <div id="password-check" class="bxc-input">
            <span>
                Repeat password
            </span>
            <input type="password" required />
        </div>
        <div id="db-name" class="bxc-input">
            <span>
                Database name
            </span>
            <input type="text" required />
        </div>
        <div id="db-user" class="bxc-input">
            <span>
                Database user
            </span>
            <input type="text" required />
        </div>
        <div id="db-password" class="bxc-input">
            <span>
                Database password
            </span>
            <input type="password" />
        </div>
        <div id="db-host" class="bxc-input">
            <span>
                Database host
            </span>
            <input type="text" placeholder="Default" />
        </div>
        <div id="db-port" class="bxc-input">
            <span>
                Database port
            </span>
            <input type="number" placeholder="Default" />
        </div>
        <div class="bxc-bottom">
            <div id="bxc-submit-installation" class="bxc-btn">
                Complete installation
            </div>
        </div>
    </form>
</div>
<?php } ?>

<?php function bxc_box_login() { ?>
<div class="bxc-main bxc-login bxc-box">
    <form>
        <div class="bxc-info"></div>
        <div class="bxc-top">
            <img src="media/logo.svg" />
            <div class="bxc-title">
                Sign into
            </div>
            <div class="bxc-text">
                To continue to Crypt NFTs Metaverse
            </div>
        </div>
        <div id="username" class="bxc-input">
            <span>
                Username
            </span>
            <input type="text" required />
        </div>
        <div id="password" class="bxc-input">
            <span>
                Password
            </span>
            <input type="password" required />
        </div>
        <div class="bxc-bottom">
            <div id="bxc-submit-login" class="bxc-btn">
                Sign in
            </div>
        </div>
    </form>
</div>
<?php } ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1.0, user-scalable=no" />
    <title>
        <?php bxc_e('Admin') ?> | Crypt NFTs etaverse
    </title>
    <script><?php echo bxc_settings_js_admin() ?></script>
    <script src="<?php echo BXC_URL . ($minify ? 'js/min/client.min.js?v=' : 'js/client.js?v=') . BXC_VERSION ?>"></script>
    <script src="<?php echo BXC_URL . ($minify ? 'js/min/admin.min.js?v=' : 'js/admin.js?v=') . BXC_VERSION ?>"></script>
    <link rel="stylesheet" href="<?php echo BXC_URL . 'css/admin.css?v=' . BXC_VERSION ?>" media="all" />
    <link rel="shortcut icon" type="image/svg" href="<?php echo BXC_URL . 'media/icon.svg' ?>" />
</head>
<body>
    <?php
    if ($installation) {
        bxc_box_installation();
    } else if (bxc_verify_admin()){
        bxc_box_admin();
    } else {
        bxc_box_login();
    }
    ?>
</body>
</html>