#						Converter Playlist to HLS Stream


##  What is it?
##  -----------
This tool converting dynamic smil playlist to hls stream


##  The Latest Version

	version 1.0 2018.12.04

##  What's new

	version 1.0 2018.12.04

##  Documentation
##  -------------


##  Features
##  ------------
	1.	Support most part of video formats. Support vod stream sources
	2.	Correctly concatenate videos to stream


##  Installation
##  ------------
Install required tools for this application ( php, ffmpeg, nginx+nginx-rtmp-module ) .

```
sudo apt-get install php
```

Nginx:
```
wget http://nginx.org/download/nginx-1.14.1.tar.gz
tar xf nginx-1.14.1.tar.gz
rm nginx-1.14.1.tar.gz
git clone https://github.com/kaltura/nginx-vod-module.git
git clone https://github.com/arut/nginx-rtmp-module.git
NGINX_VERSION=1.14.1
OPENSSL_VERSION=1.1.1
wget https://www.openssl.org/source/openssl-${OPENSSL_VERSION}.tar.gz
tar -xvzf openssl-${OPENSSL_VERSION}.tar.gz
apt-get install libpcre++-dev
apt-get install zlib1g-dev
./configure --pid-path=/run/nginx.pid  --with-openssl=/home/ubuntu/src/openssl-${OPENSSL_VERSION}/   --with-http_v2_module   --with-http_ssl_module   --with-http_sub_module  --add-module=/home/ubuntu/src/nginx-vod-module  --add-module=/home/ubuntu/src/nginx-rtmp-module
make
make install


cat << EOF >/lib/systemd/system/nginx.service
# Stop dance for nginx
# =======================
# Save in /etc/nginx/systemd/system directory
# ExecStop sends SIGSTOP (graceful stop) to the nginx process.
# If, after 5s (--retry QUIT/5) nginx is still running, systemd takes control
# and sends SIGTERM (fast shutdown) to the main process.
# After another 5s (TimeoutStopSec=5), and if nginx is alive, systemd sends
# SIGKILL to all the remaining processes in the process group (KillMode=mixed).
#
# nginx signals reference doc:
# http://nginx.org/en/docs/control.html
#
[Unit]
Description=A high performance web server and a reverse proxy server
After=network.target

[Service]
Type=forking
PIDFile=/run/nginx.pid
ExecStartPre=/usr/local/nginx/sbin/nginx -t -q -g 'daemon on; master_process on;'
ExecStart=/usr/local/nginx/sbin/nginx -g 'daemon on; master_process on;'
ExecReload=/usr/local/nginx/sbin/nginx -g 'daemon on; master_process on;' -s reload
ExecStop=-/sbin/start-stop-daemon --quiet --stop --retry QUIT/5 --pidfile /run/nginx.pid
TimeoutStopSec=5
KillMode=mixed

[Install]
WantedBy=multi-user.target
EOF


# systemctl unmask nginx
systemctl enable nginx
ls -la /etc/systemd/system/
service --status-all
service start nginx
service nginx start
```

Ffmpeg:
```
sudo apt-get update
sudo apt-get -y install apache2 php php-sqlite3 libapache2-mod-php php-curl git

cd 
wget https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-64bit-static.tar.xz
tar xf ffmpeg-release-64bit-static.tar.xz
sudo mkdir /usr/share/ffmpeg
sudo mv ffmpeg-4.1-64bit-static/ /usr/share/ffmpeg
sudo ln -s /usr/share/ffmpeg/ffmpeg-4.1-64bit-static/ffmpeg /usr/bin/ffmpeg
sudo ln -s /usr/share/ffmpeg/ffmpeg-4.1-64bit-static/ffprobe /usr/bin/ffprobe
```


##  How to use
##  ------------
	1.	Set required values in `data/config.json`
	2.	Prepare smil file in required format ( eg `php do_test_smil_file.php -f test.smil`)
	3.	Run main processing script `php smil_parsing -f test.smil`
	4.	Open stream in any video player http://my_ip/video/hls/myStream/480p.m3u8


#### Recomendation
	1.  Require a lot of CPU ( minimum 8 cores )


##  Bugs
##  ------------
	1. Async audio on sevral sources



  Licensing
  ---------
	GNU

  Contacts
  --------

     o korolev-ia [at] yandex.ru
     o http://www.unixpin.com

