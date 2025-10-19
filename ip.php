<?php
/*
文件名：ip.php
运行位置：云主机A 的 nginx->php-fpm 网站根目录下
作用：向动态公网主机 B（被动式）返回主机 B 的 IP 地址
fa
*/
echo $_SERVER['REMOTE_ADDR'];
