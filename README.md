# 🛰️ SuperDDNS Notify System
# 🛰️ 超级 DDNS

## 🧭 一句话说明
**Solve the issue where clients cannot access a server when its dynamic public IP (DDNS) changes.**
**解决动态公网 IP（DDNS）服务器地址变化后，客户端无法及时访问的问题。**

- When your home network (Host B) has a public IP that changes frequently...  
  当你家宽带（主机 B）拥有公网 IP，但这个 IP 经常变动
- External cloud servers (Host A) may fail to connect because DNS isn’t updated in time.  
  外部云主机（主机 A）上的服务往往会因为 DNS 未及时更新而访问失败。
- This system automatically detects IP changes and notifies Host A to reload configurations (e.g. nginx.conf) instantly.  
  本系统能在 **IP 变化发生的最短时间内自动通知云端更新配置（如 nginx.conf）**
- Achieving “real-time DDNS” behavior — without any DNS provider involved.  
  实现类似“动态 DDNS”的即时修复，而无需域名服务商介入。

## ⚙️ 适用场景

### ✅ 典型使用情形
- Host B: Dynamic public IP, located at your home or office.  
  主机 B： 动态公网节点，位于家庭或办公网络。；
- Host A: Cloud relay or proxy near your region.  
  主机 A： 云端中继节点，建议选择距离较近的地区。；
- Host A runs Nginx as reverse proxy.  
  主机 A 使用 nginx 或其他反向代理前端；
- Automatically update proxy_pass target IP when Host B changes.  
  自动更新 nginx 中 proxy_pass 的目标 IP。
- Perfect for forwarding private game servers (Minecraft, Factorio, Valheim etc.).  
  适用于转发 私有游戏服务端 （Minecraft、Factorio、Valheim 等）；
- Suitable for low-traffic tunnel or private API scenarios.  
  适合内网穿透、低数据量应用或实验项目。

### ⚠️ 不适用场景

在中国大陆：  
In mainland China:
- ❌ Cannot proxy HTTP/HTTPS (ports 80/443 are restricted).  
  不能转发 HTTP/HTTPS 网站访问（80/443 端口受管制）。
- ✅ If web forwarding is required, use a Hong Kong server and build an external proxy instead.  
  若需转发网站请求，需要购买香港云服务器并实现另外一套转发逻辑（本项目不包含）

## 🧩 System Overview / 系统结构概览

| 角色 | 说明 |
|:--|:--|
| Host B <br> 主机 B | Periodically detects its own public IP. When changed → signs and notifies Host A.<br>动态公网节点，负责向**主机 A**定时高频检测自己的公网 IP，如变化则签名通知主机 A。 |
| Host A <br> 主机 A | eceives → verifies → modifies nginx.conf → reloads.<br>云端中继节点，接收通知 → 验证签名 → 修改 nginx.conf → reload。 |
| Protocal <br> 通信协议 | Plain JSON + RSA signature (anti-spoof, not encryption).<br>明文传输 + RSA 签名验证（防伪造，非加密），JSON 返回状态。 |
| CoreScript <br> 核心脚本 | check_this_ip_notify_cdn.php (on B), notify.php (on A).<br>`check_this_ip_notify_cdn.php`（运行在 **主机B** 由 systemd 管理的 php-cli），`notify.php`（运行在 ***主机 A** nginx 后端的 php-fpm）。 |

## 🔐 Step 1: Generate & Deploy Keys / 第一步：生成并放置密钥
### 1️⃣ On Host B / 在 **主机 B**
```bash
sudo mkdir -p /opt/shell && cd /opt/shell
openssl genrsa -out b_private.pem 2048
openssl rsa -in b_private.pem -pubout -out b_public.pem
sudo chmod 600 b_private.pem
sudo chmod 644 b_public.pem
```

### 2️⃣ Place files / 放置文件
- **b_priviate** → Keep it on Host B / **b_priviate** → 留在 **主机 B**
- **b_public** → Copy to host A:/opt/shell / **b_public** → 上传到 **主机 A** 的 /opt/shell/ 目录下
```bash
scp b_public.pem root@<IP_OF_HOST_A>:/opt/shell/
```

## 🐘 Stop 2: Install PHP on Host B (Required) / 第二步：在**主机 B**安装 php（必须）
- 我习惯把 php 手动编译安装到 /opt/php，和一般的 apt, yum 安装路径很不一样
- 下面给出 apt 安装 php 的简要步骤（注意，本项目不需要主机 B 上有 nginx）
```bash
sudo apt install php php-cli php-curl -y
```

## 🧱 Step 3: Setup Main Loop Script on B / 第三步：在**主机 B**配置主循环脚本（必须）
### 1️⃣ 放置脚本
- Place check_this_ip_notify_cdn.php under /opt/shell/. / 把 **check_this_ip_notify_cdn.php** 放置在 **云主机 B** 的 **/opt/shell/** 目录下；
- Edit constant: / 编辑 **check_this_ip_notify_cdn.php**
```php
const HOST_A = '5.6.7.8';
```

### 2️⃣ Create systemd service / 配置 systemd
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

- Save, Exit & Then/ 保存，退出，然后
```bash
systemctl daemon-reload
systemctl enable check_this_ip_notify_cdn.service
systemctl start check_this_ip_notify_cdn.service
systemctl status check_this_ip_notify_cdn.service
```
** 可以不着急启动(start)，最好把 **主机 A** 搭建完再启动上述脚本

## Step 4: Configure Nginx + PHP on Host A / 第四步：在 **主机 A**（云主机）上配置 nginx + php
### 1️⃣ Install Nginx (with stream module) / 安装 nginx，必须有 stream 模块
- Debian/Ubuntu paths / 如果是 apt 安装，则文件会很分散：
-- Bin Files / 主程序：/usr/sbin/nginx  
-- **Configuration Files** / **配置文件**：/etc/nginx/  
-- **Website Main Dir**：/usr/share/nginx/  
-- Log Files / 日志：/var/log/nginx/  
- Source Build / 如果是编译带预编译参数 --prefix=/opt/nginx 安装  
-- Bin Files / 主程序：/opt/nginx/sbin  
-- **Configuration Files** / **配置文件**：/opt/nginx/conf/  
-- **Website Main Dir** / **网站目录**：/opt/nginx/html/  
-- Log Files / 日志：/opt/nginx/logs/  
### 2️⃣ Change default port (avoid 80/443) / 修改 nginx 默认网站监听端口（避开 80/443）  
- Open up 'nginx.conf', make sure: / 打开 **配置文件** 里的 **nginx.conf**，确保：
```nginx
listen 100;
server_name _;
```
### 3️ Add stream proxy block / 搭建转发逻辑（核心功能）
```nginx
stream {  
  server {  
    listen 25565;  
    proxy_pass 1.2.3.4:25565;  
  }  
  server {  
    listen 7777;  
    proxy_pass 1.2.3.4:7777;  
  }  
}
```
- ach server {} forwards a static port; IPs will be auto-updated when B’s public IP changes.  
  每个 server{} 块转发一个固定端口，B 端 IP 变化后系统会自动更新并 reload nginx。

### 4️⃣ Install PHP-FPM (runs as root) / 安装 php 并配置 php-fpm，确保运行权限为 root
- Apt install php or source build php  
  自行决定使用 apt install 或编译安装 php
- PHP official: / php 官网  
  https://php.net/
- Ensure curl and openssl extensions enabled.  
  在 php-fpm 配置中确保 curl、openssl 启用。
- Example systemd unit (-R Required):  
  为 php-fpm 配置 systemd，确保运行参数有 -R
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
- The php-fpm path in the example is /opt/php/sbin/php-fpm, which is my commonly used path, but your php path is most likely /usr/php-fpm. Please modify it accordingly.  
  示例中的 php-fpm 路径为 /opt/php/sbin/php-fpm，这是我的常用路径，而你的 php 路径大概率在 /usr/php-fpm，请自行修改
- Save, exit, enable & start  
  保存，退出，使能，启用。
```bash
systemctl daemon-reload
systemctl enable php-fpm.service
systemctl start php-fpm.service
systemctl status php-fpm.service
```
### 5️⃣ Step 5: Deploy Backend Scripts / 放置文件
- Place **ip.php** and **notify.php** to the Nginx Website Dir  
  把 **ip.php**, **notify.php** 放进上面的 **网站目录**
### 

## Workflow Summary / 运行逻辑
### 🖥️ Host B / 主机 B
1. Periodically requests /ip.php on A to detect its current IP.  
   定期访问 **主机 A** 的 `/ip.php` 获取自己当前公网 IP；
2. If changed → signs with private key → POST to /notify.php.  
   若 IP 变化 → 使用私钥签名 → POST 到 `/notify.php`；
3. Retries on failure; logs to /opt/shell/notify.log.  
   等待通知请求的返回，若失败则重试通知，并向 /opt/shell/notify.log 写日志，
4. Managed by systemd for auto-restart.  
   systemd 保障主脚本 check_this_ip_noify_cdn.php 崩溃拉起

### ☁️ Host A / 主机 A
1. ip.php returns requester’s public IP.  
   ip.php 文件接受主机 B的定期询址
2. notify.php verifies RSA signature.  
   有通知到来时，notify.php 验证 RSA 签名
3. Updates nginx.conf (proxy_pass line) via sed -i.  
   用 sed -i 更新 nginx.conf 中 proxy_pass 行的 IP 地址值
4. Executes nginx -s reload.  
   执行 nginx 重载

## Development Environment / 开发环境
| Component / 组件 | Testing Env / 测试环境 |
|:--|:--|
| 操作系统 | Ubuntu 22.04
| PHP 版本 | 8.4
| PHP 扩展 | `curl`, `openssl` |
| nginx | 1.28.0，带 stream 模块
