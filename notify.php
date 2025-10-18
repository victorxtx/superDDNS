<?php
header("Content-Type: application/json; charset=utf-8");
$pubkeyFile = "/opt/shell/b_public.pem";
$lastIpFile = "/opt/shell/last_ip.txt";
$logFile = "/opt/shell/notify.log";
function logmsg($msg){
	global $logFile;
	$t = date("Y-m-d H:i:s");
	file_put_contents($logFile, "[$t] $msg\n", FILE_APPEND);
}
$data = $_POST['data'] ?? '';
$sign = $_POST['sign'] ?? '';
if (!$data || !$sign){
	$ret = [
		'status' => "fail",
		'error' => "missing params"
	];
	echo json_encode($ret);
	exit;
}
$pubkey = openssl_pkey_get_public(file_get_contents($pubkeyFile));
$sign_bin = base64_decode($sign);
$ok = openssl_verify($data, $sign_bin, $pubkey, OPENSSL_ALGO_SHA256);
if ($ok !== 1) {
	$ret = [
		'status' => "fail",
		'error' => "bad signature"
	];
	echo json_encode($ret);
	logmsg("数据验签失败");
	exit;
}
list($ip_in, $ts) = explode('|', $data);
$ip_in = trim($ip_in);
if (!filter_var($ip_in, FILTER_VALIDATE_IP)){
	$ret = [
		'status' => 'fail',
		'error' => "invalid ip"
	];
	echo json_encode($ret);
	logmsg("ip 格式不合法");
	exit;
}
if (!is_numeric($ts) || floor($ts) != ceil($ts)){
	$ret = [
		'status' => 'fail',
		'error' => 'timestamp format error'
	];
	echo json_encode($ret);
	logmsg("时间格式不合法");
	exit;
}
if (abs(time() - $ts) > 30) {
	$ret = [
		'status' => 'fail',
		'error' => 'timediff too long'
	];
	echo json_encode($ret);
	logmsg("通知时间戳异常：当前=".date('Y-m-d H:i:s', time())."，接收=".date('Y-m-d H:i:s', $ts));
	exit;
}
touch($lastIpFile);
$lastIp = @trim(file_get_contents($lastIpFile));
if (!filter_var($lastIp, FILTER_VALIDATE_IP)){
	file_put_contents($lastIpFile, $ip_in);
	$ret = [
		'status' => 'fail',
		'error' => 'A inner adjusting, do renotify'
	];
	echo json_encode($ret);
	logmsg("本地 ip 文件内容有错，执行清理");
	exit;
}
if ($lastIp === $ip_in){
	$ret = [
		'status' => "ok",
		'reload' => 'skip',
	];
	echo json_encode($ret);
	logmsg("IP 没有变化，主机 B 却发来了修改 IP 通知");
	exit;
}
$nginx_conf_path = '';
$cmd_get_path = "nginx -t 2>&1 | sed -n 's/^nginx: configuration file \\(.*\\) test.*/\\1/p'";
exec($cmd_get_path, $out[0], $ret_path);
$nginx_conf_path = escapeshellarg($out[0]);
unset($out);
if ($ret_path !== 0){
	$ret = [
		'status' => 'fail',
		'error' => 'fail to get conf path'
	];
	echo json_encode($ret);
	logmsg("获取 nginx 配置文件失败");
	exit;
}
$cmd_update_conf = "sed -i 's/^\([[:space:]]*proxy_pass \)\(.*\)\(:[0-9]*;\)$/\1$ip_in\3/g' $nginx_conf_path";
exec($cmd_update_conf, $out1, $ret_update_conf);
if ($ret_update_conf !== 0){
	$ret = [
		'status' => 'fail',
		'error' => 'fail to update conf'
	];
	echo json_encode($ret);
	logmsg("用新 IP 更新 nginx.conf 失败");
	exit;
}
exec('nginx -s reload', $nul, $ret_reload);
if ($ret_reload !== 0){
	$ret = [
		'status' => 'fail',
		'error' => 'fail to reload nginx'
	];
	echo json_encode($ret);
	logmsg("执行 nginx -s reload 失败");
	exit;
}
file_put_contents($lastIpFile, $ip_in);
$ret = [
	'status' => 'ok',
	'reload' => 'reload'
];
echo json_encode($ret);