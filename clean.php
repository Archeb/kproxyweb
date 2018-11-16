<?php
if(isset($_GET['hidemenu'])){
    setcookie('kp_hide_menu','1');
    header("location:".$_SERVER["HTTP_REFERER"]);
    die();
}
foreach ($_COOKIE as $key => $value) {
        setcookie($key, null);
    }
header('Location: /');
