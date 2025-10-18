# 🛰️ SuperDDNS Notify System
# 🛰️ 超级 DDNS

## 🧭 一句话说明
> **解决动态公网 IP（DDNS）服务器地址变化后，客户端无法及时访问的问题。**

当你家宽带（主机 B）拥有公网 IP，但这个 IP 经常变动时，  
外部云主机（主机 A）上的服务往往会因为 DNS 未及时更新而访问失败。  
本系统能在 **IP 变化发生的最短时间内自动通知云端更新配置（如 nginx.conf）**，  
实现类似“动态 DDNS”的即时修复，而无需域名服务商介入。

## ⚙️ 适用场景

### ✅ 典型使用情形
- 主机 **B** 是家庭/办公宽带，**服务器物理机所在地，动态公网 IP**；
- 主机 **A** 是云服务器，尽量买离自己家近的，**用于反向中继或代理转发**；
- 在动态公网内开游戏服务器，让客户端连接云服务器，游戏便能稳定连接
- 主机 A 使用 nginx 或其他反向代理前端；
- 你希望自动将 A 的代理配置（如 `proxy_pass`）更新为 B 的最新公网 IP，并自动 reload。
- 代理到家用服务器中的 **私有游戏服务端**（如 Minecraft、Factorio、Valheim 等）；
- 内网穿透场景下的 **低数据量应用**；
- 自建 API、Socket 服务、或实验项目，不想备案、不想走域名。

### ⚠️ 不适用场景

在中国大陆：
- 不能转发浏览器访问网站（不支持 HTTP 协议）
- 不能转发 80/443 等大陆管制端口
- 若需转发网站请求，需要购买香港云服务器并实现另外一套转发逻辑（不在这个项目里）

## 🧩 系统结构概览

| 角色 | 说明 |
|:--|:--|
| 主机 B | 动态公网节点，负责向**主机 A**定时高频检测自己的公网 IP，如变化则签名通知主机 A。 |
| 主机 A | 云端中继节点，接收通知 → 验证签名 → 修改 nginx.conf → reload。 |
| 通信协议 | 明文传输 + RSA 签名验证（防伪造，非加密），JSON 返回状态。 |
| 核心脚本 | `check_this_ip_notify_cdn.php`（运行在 **主机B** 由 systemd 管理的 php-cli），`notify.php`（运行在 ***主机 A** nginx 后端的 php-fpm）。 |

## 🔐 第一步：生成并放置密钥
### 1️⃣ 在 **主机 B** 创建目录并进入
```bash
sudo mkdir -p /opt/shell
sudo chmod 700 /opt/shell
cd /opt/shell
```

### 2️⃣ 在 **主机 B** 生成私钥和公钥
#### b_priviate 留在**主机 B**
#### b_public 留在**主机 A**
```bash
openssl genrsa -out b_private.pem 2048
openssl rsa -in b_private.pem -pubout -out b_public.pem
```

### 3️⃣ 设置文件权限（选做）
```bash
sudo chmod 600 b_private.pem
sudo chmod 644 b_public.pem
```

### 4️⃣ 把公钥复制到主机 A（选做）
```bash
scp /opt/shell/b_public.pem root@A_IP:/opt/shell/b_public.pem
```
或用 ssh 工具把 **b_public.pem** 上传到 /opt/shell/ 目录下

### 5️⃣ 验证密钥是否有效（选做）
```bash
echo "testdata" > test.txt
openssl dgst -sha256 -sign b_private.pem -out test.sig test.txt
openssl dgst -sha256 -verify b_public.pem -signature test.sig test.txt
```

## 🐘 第二步：在**主机 B**安装 php（必须）
### 我一直都是用编译安装 php，不太清楚 apt, yum 等工具如何正确安装 php，请自行解决这个步骤
### 为 php 指令设置环境变量，假设编译安装在 /opt/php/bin/php，则设置建软连接
```bash
ln -s /opt/php/bin/php /usr/php
```

## 🧱 第三步：在**主机 B**配置主循环脚本（必须）
### 1️⃣ 放置脚本
把 check_this_ip_notify_cdn.php 放置在 **主机 B** 的 /opt/shell/ 目录下
编辑 check_this_ip_notify_cdn.php
找到 const HOST_A = ''; 的单引号内填写**主机 A** 的 IP 地址（注意不能填域名，否则会被云服务商拦截）

### 2️⃣ 配置 systemd
```bash
nano /usr/lib/systemd/system/check_this_ip_notify_cdn.service
```
> [Unit]
> Description=SuperDDNS IP Watcher and Notifier
> After=network-online.target
> Wants=network-online.target
> 
> [Service]
> Type=simple
> ExecStart=php /opt/shell/check_this_ip_notify_cdn.php
> Restart=always
> RestartSec=5
> StandardOutput=null
> StandardError=null
> User=root
> Group=root
> WorkingDirectory=/opt/shell
> 
> [Install]
> WantedBy=multi-user.target
保存，退出
```bash
systemctl daemon-reload
systemctl enable check_this_ip_notify_cdn.service
systemctl start check_this_ip_notify_cdn.service
systemctl status check_this_ip_notify_cdn.service
```
** 可以不着急启动(start)，最好把 **主机 A** 搭建完再启动上述脚本

## 第四步：在 **主机 A**（云主机）上配置 nginx + php
### 1️⃣ 安装 nginx，必须有 stream 模块
- 如果是 apt 安装，则文件会很分散：
-- 主程序：/usr/sbin/nginx
-- **配置文件**：/etc/nginx/
-- **网站目录**：/usr/share/nginx/
-- 日志：/var/log/nginx/
- 如果是编译安装，预编译参数 --prefix=/opt/nginx
-- 主程序：/opt/nginx/sbin
-- **配置文件**：/opt/nginx/conf/
-- **网站目录**：/opt/nginx/html/
-- 日志：/opt/nginx/logs/
### 2️⃣ 修改 nginx 默认网站监听端口
- 打开 **配置文件** 里的 **nginx.conf**
-- 把 **listen 80;** 修改为 **listen 100;**
-- 确保 **server_name** 这一行是这样：**server_name _;**
### 3️ 搭建转发逻辑（核心功能）
在 **nginx.conf** 中，与 http{} **平行的位置** 添加如下代码：（假设你当前主机B的动态IP是 1.2.3.4，要转发 25565 和 7777 两个端口：
> stream {
> 	server {
> 		listen 25565;
> 		proxy_pass 1.2.3.4:25565;
> 	}
> 	server {
> 		listen 7777;
> 		proxy_pass 1.2.3.4:7777;
> 	}
> }
#### 在 server 内的 listen 和 : 后填写你**需要稳定转发的端口**
每一个
> server{
> 	listen <要转发的端口>;
> 	proxy_pass <当前动态公网地址>:<要转发的端口>;
> }
#### 将会转发一个固定端口，并以最大可靠性确保连接可靠性（动态IP更换时，只会断一瞬间，可以瞬间重连）
#### 当前动态公网地址只需要填一次，之后会在 IP 更换时自动填写并重载 nginx
#### 让玩家连接 **云主机 A** 的地址，开始游戏（ddns 更新延迟问题就没有了）

### 4️⃣ 安装 php 并配置 php-fpm，确保运行权限为 root
- 开启 **curl** 扩展, 并配置 **openssl** （--with-openssl）
- 为 php-fpm 配置 systemd，确保运行参数有 -R：
```bash
nano /usr/lib/systemd/system/php-fpm.service
```
> [Unit]
> Description=The PHP FastCGI Processing Manage (FPM) 8.4.13
> After=syslog.target network.target
> Before=nginx.service
> 
> [Service]
> Type=simple
> PIDFile=/opt/php/var/run/php-fpm.pid
> ExecStart=/opt/php/sbin/php-fpm -R
> ExecStop=kill -INT `cat /opt/php/var/run/php-fpm.pid`
> ExecReload=kill -USR2 `cat /opt/php/var/run/php-fpm.pid`
> Restart=on-abort
> 
> [Install]
> WantedBy=multi-user.target

### 5️⃣ 放置文件
把 **ip.php**, **notify.php** 放进上面的 **网站目录**
### 

## 运行逻辑
### 主机 B
- 定期访问 **主机 A** 的 `/ip.php` 获取自己当前公网 IP；
- 若 IP 变化 → 使用私钥签名 → POST 到 `/notify.php`；
- 若主机 A 返回 `fail`，持续重试直到成功；
- 全程记录日志 `/opt/shell/notify.log`；
- 使用 `systemd` 保证脚本崩溃自动重启。

### 主机 A
- `ip.php` 返回客户端请求的公网 IP；
- `notify.php` 验证签名 → 更新 nginx.conf 中对应行；
- 使用 `sed -i` 修改 `proxy_pass` 把最新 IP 直接写入配置文件 → 然后执行 `nginx -s reload`。

## 开发环境

| 项目 | 要求 |
|:--|:--|
| 操作系统 | Ubuntu 22.04
| PHP 版本 | 8.4
| PHP 扩展 | `curl`, `openssl` |
| nginx | 1.28.0，带 stream 模块
