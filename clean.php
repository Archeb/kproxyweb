<?php
foreach ($_COOKIE as $key => $value) {
        setcookie($key, null);
    }
header('Location: /');
