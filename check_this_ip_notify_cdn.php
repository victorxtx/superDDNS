<?php
/*
文件名：check_this_ip_notify_cdn.php
运行位置：处于家用动态公网后的“主机B”
作用：每几秒钟（可配置）向“云主机A”探测一次自己的公网IP地址，若公网地址发生变化，立刻向“云主机B”发出通知，让“主机A”修改自己的 nginx proxy_pass 地址并重启 nginx
*/
// 配置部分开始（在 '' 内填写内容）

const HOST_A = ''; // (必填) 主机A（云服务器）IP 地址
const SLEEP_SECONDS = 5; // 每多少秒向云主机A探测一次自己的动态公网IP地址

// 配置部分结束

// SuperDDNS B-side - 优化版（复用 curl、显式释放、内存统计）
$privateKeyFile = "/opt/shell/b_private.pem";
$privateKeyPem = @file_get_contents($privateKeyFile);
if ($privateKeyPem === false) {
	echo "Cannot read private key file $privateKeyFile\n";
	exit(1);
}
$privateKey = openssl_pkey_get_private($privateKeyPem);
if ($privateKey === false) {
	echo "Cannot load private key\n";
	exit(1);
}

$echoHost   = "http://".HOST_A.":100/ip.php";
$notifyHost = "http://".HOST_A.":100/notify.php";
$oldIp = "";
$logFile = "/opt/shell/notify.log";

function logmsg($msg){
	global $logFile;
	$t = date("Y-m-d H:i:s");
	// 尽量不要 echo 太多，防止 stdout 缓冲导致内存膨胀
	// echo "[$t] $msg\n";
	file_put_contents($logFile, "[$t] $msg\n", FILE_APPEND | LOCK_EX);
}

// 创建两个长期复用的 curl handle（一个用于 echoHost，一个用于 notifyHost）
$ch_get = curl_init();
curl_setopt($ch_get, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_get, CURLOPT_CONNECTTIMEOUT_MS, 1500);
curl_setopt($ch_get, CURLOPT_TIMEOUT, 3000 / 1000); // seconds (fallback)
if (defined('CURLOPT_TIMEOUT_MS')) curl_setopt($ch_get, CURLOPT_TIMEOUT_MS, 3000);

$ch_post = curl_init();
curl_setopt($ch_post, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch_post, CURLOPT_POST, true);
curl_setopt($ch_post, CURLOPT_CONNECTTIMEOUT_MS, 1500);
curl_setopt($ch_post, CURLOPT_TIMEOUT, 4000 / 1000);
if (defined('CURLOPT_TIMEOUT_MS')) curl_setopt($ch_post, CURLOPT_TIMEOUT_MS, 4000);

// 循环计数，用于周期性 GC 与内存日志
$iter = 0;
$log_mem_interval = 60; // 每多少轮写一次内存使用日志
$gc_interval = 200;     // 每多少轮强制 GC
$stable_sleep = SLEEP_SECONDS;      // 基本睡眠秒数

while (true){
	$iter++;

	// === Step 1: 获取公网 IP（复用 handle） ===
	curl_setopt($ch_get, CURLOPT_URL, $echoHost);
	$result = curl_exec($ch_get);
	$curlErr = null;
	if ($result === false) {
		$curlErr = curl_error($ch_get);
		logmsg("curl getpub error: $curlErr");
		// 不把 $oldIp 改为 ''，下一轮重试
		// 释放临时变量并睡眠
		unset($result, $curlErr);
		if ($iter % $gc_interval === 0) gc_collect_cycles();
		sleep($stable_sleep);
		continue;
	}
	$newIp = trim($result);

	if ($newIp === '' || !filter_var($newIp, FILTER_VALIDATE_IP)){
		logmsg("getpub returned invalid ip: '$newIp'");
		unset($result, $newIp);
		if ($iter % $gc_interval === 0) gc_collect_cycles();
		sleep($stable_sleep);
		continue;
	}

	// === Step 2: IP 变化才上报 ===
	if ($newIp !== $oldIp){
		$timestamp = time();
		$data = $newIp . "|" . $timestamp;

		// 签名（使用外层加载的 $privateKey）
		if (!openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
			logmsg("openssl_sign failed");
			unset($signature, $data, $newIp);
			if ($iter % $gc_interval === 0) gc_collect_cycles();
			sleep($stable_sleep);
			continue;
		}
		$sign = base64_encode($signature);
		unset($signature);

		// 上报（复用 post handle）
		curl_setopt($ch_post, CURLOPT_URL, $notifyHost);
		curl_setopt($ch_post, CURLOPT_POSTFIELDS, ['data' => $data, 'sign' => $sign]);
		$resp = curl_exec($ch_post);
		if ($resp === false){
			logmsg("notify curl error: " . curl_error($ch_post));
			unset($resp, $sign, $data, $newIp);
			if ($iter % $gc_interval === 0) gc_collect_cycles();
			sleep($stable_sleep);
			continue;
		}

		// 解析响应
		$json = json_decode($resp, true);
		if (!is_array($json)) {
			logmsg("notify returned non-json: " . substr($resp, 0, 200));
			unset($resp, $json, $sign, $data, $newIp);
			if ($iter % $gc_interval === 0) gc_collect_cycles();
			sleep($stable_sleep);
			continue;
		}

		if (($json['status'] ?? '') !== 'ok') {
			$err = $json['error'] ?? 'unknown';
			logmsg("notify returned fail: $err");
			unset($resp, $json, $sign, $data, $newIp);
			if ($iter % $gc_interval === 0) gc_collect_cycles();
			sleep($stable_sleep);
			continue;
		}

		// 成功
		$oldIp = $newIp;
		$reload = $json['reload'] ?? '';
		logmsg("notify success: ip=$oldIp reload=$reload");

		// 释放本次大对象
		unset($resp, $json, $sign, $data, $newIp, $reload);
	}

	// 周期性内存与 GC 日志
	// if ($iter % $log_mem_interval === 0) {
	// 	$mem = memory_get_usage(true);
	// 	$peak = memory_get_peak_usage(true);
	// 	logmsg("iter=$iter memory={$mem} peak={$peak}");
	// }
	if ($iter % $gc_interval === 0) {
		gc_collect_cycles();
	}

	// 睡眠并继续
	sleep($stable_sleep);
}

// 结束前关闭 curl（通常不会到这里）
curl_close($ch_get);
curl_close($ch_post);
unset($privateKey);
