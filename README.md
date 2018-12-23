# Converter Playlist to HLS Stream

## What is it?

## -----------

This tool converting dynamic smil playlist to hls stream and add any independed overlays ( video with cromakey, images, text )

## The Latest Version

    version 1.2 2018.12.24

## What's new
    version 1.2 2018.12.24
    + Fixed errors
    + Prepare testing scripts
    + Optional output hls resolution

    version 1.1 2018.12.22
    +	Fixed audio unsync
    +	Fixed pause between videos
    +	Added rtmp as sources
    +	Added video, images and texts overlays ( see `data/overlay_test.json` for example )

    version 1.0 2018.12.04

## Documentation

## -------------

## Features

## ------------

    1.	Support most part of video formats as input. Support vod stream sources
    2.	Correctly concatenate videos to hls stream
    3.	Add video, images and texts overlays to output video stream
    4.	Now store hls to 360, 480, 720 ( require a lot of cpu for hi resolutions )
		5.	Image overlay support opacity ( for png )
		6.	Video overlay support cromakey

## Installation

## ------------

Install required tools for this application ( php, php-gd, ffmpeg, nginx ) .

```
sudo apt-get -y install php php-gd nginx git
```

```

Ffmpeg:
```

sudo apt-get update

cd
wget https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-64bit-static.tar.xz
tar xf ffmpeg-release-64bit-static.tar.xz
sudo mkdir /usr/share/ffmpeg
sudo mv ffmpeg-4.1-64bit-static/ /usr/share/ffmpeg
sudo ln -s /usr/share/ffmpeg/ffmpeg-4.1-64bit-static/ffmpeg /usr/bin/ffmpeg
sudo ln -s /usr/share/ffmpeg/ffmpeg-4.1-64bit-static/ffprobe /usr/bin/ffprobe

```

#### Sample of nginx config
```nginx.conf
#user  nobody;
worker_processes  1;

#error_log  logs/error.log;
#error_log  logs/error.log  notice;
#error_log  logs/error.log  info;

#pid        logs/nginx.pid;


events {
    worker_connections  1024;
}


http {
    include       mime.types;
    default_type  application/octet-stream;

    #log_format  main  '$remote_addr - $remote_user [$time_local] "$request" '
    #                  '$status $body_bytes_sent "$http_referer" '
    #                  '"$http_user_agent" "$http_x_forwarded_for"';

    #access_log  logs/access.log  main;

    sendfile        on;
    #tcp_nopush     on;

    #keepalive_timeout  0;
    keepalive_timeout  65;

    #gzip  on;

    server {
        listen       80;
        server_name  localhost;

        #charset koi8-r;

        #access_log  logs/host.access.log  main;

        location / {
            root   html;
            index  index.html index.htm;
        }

        #error_page  404              /404.html;

        # redirect server error pages to the static page /50x.html
        #
        error_page   500 502 503 504  /50x.html;
        location = /50x.html {
            root   html;
        }

        location /video {
            add_header 'Access-Control-Allow-Origin' '*' always;
            add_header 'Access-Control-Expose-Headers' 'Content-Length';
            # allow CORS preflight requests
            if ($request_method = 'OPTIONS') {
                add_header 'Access-Control-Allow-Origin' '*';
                add_header 'Access-Control-Max-Age' 1728000;
                add_header 'Content-Type' 'text/plain charset=UTF-8';
                add_header 'Content-Length' 0;
                return 204;
            }
            # Serve HLS fragments
            types {
                application/dash+xml mpd;
                application/vnd.apple.mpegurl m3u8;
                video/mp2t ts;
            }
            root /opt/streaming;
            add_header Cache-Control no-cache;
        }

    }
}	

```



##  How to use
##  ------------
	1.	Set required values in `data/config.json`
	2.	Prepare smil file in required format, or run `php do_test_smil_with_streams.php -f test.smil -n 6` for test. This command shedule streaming in 10 seconds.
	3.	Run main processing script `php smil_parsing -f test.smil`
	4.	Open stream in any video player `http://my_ip/video/hls/myStream/360p.m3u8` ( `http://my_ip/video/hls/myStream/480p.m3u8`, `http://my_ip/video/hls/myStream720p.m3u8`)

eg:
```
$ php do_test_smil_with_streams.php -f test.smil -n 4
$ php smil_parsing.php -f test.smil -o data/overlay_test.json
$ ffplay http://localhost/video/hls/myStream/360p.m3u8
```


### Data formats:
#### data/config.json
```json
{
  "timezone":"Europe/Moscow",
  "mp4Basedir":"/opt/streaming/mp4",
  "hlsBasedir":"/opt/streaming/video/hls",
  "preparedVideoBasedir":"/opt/streaming/video/prepared",
  "maxConcatFiles":100  
}
```
where:

	+	timezone - timezone ( see supported timezones `http://us.php.net/manual/en/timezones.php` ).
	+	mp4Basedir - where video files are stored 
	+	hlsBasedir - base directory for hls files. In this directory will be created folder with `stream name` and hls files will be stored in this subfolder. Eg stream `myStream` will be stored to /opt/streaming/video/hls/myStream and will be available with http://your_ip/video/hls/myStream/480p.m3u8
	+	preparedVideoBasedir - do not used now
  + maxConcatFiles - how many files can be processed


#### overlay.json
```json
{
  "output_width": 1920,
  "output_height": 1080,
  "transition": "fade",
  "resize_original_video": true,
  "background": "black",
  "overlay": [
    {
      "type": "image",
      "properties": {
        "start": "2",
        "end": "20",
        "fade_in": 1.5,
        "fade_out": 1.5,
        "file": "assets/crest.jpg",
        "x": 500,
        "y": 200,
        "width": 150,
        "height": 150
      }
    },
    {
      "type": "video",
      "properties": {
        "start": "3",
        "end": "15",
        "fade_in": 1,
        "fade_out": 1,
        "file": "assets/logo.mp4",
        "x": 20,
        "y": 0,
        "width": 150,
        "height": 150,
        "chromakey": {
          "blend": 0.1,
          "similarity": 0.1,
          "color": "0x70de77"
        }
      }
    },
    {
      "type": "text",
      "properties": {
        "start": "17",
        "end": "38",
        "fade_in": 1.5,
        "fade_out": 1,
        "x": 500,
        "y": 500,
        "text": "fc-query returns error code 0 for successful parsing, \\Nor 1 if any errors occured or if at least \\None font face could not be opened.",
        "font": "Arial",
        "font_size": 90,
        "font_color": "000000",
        "font_opacity": "00",
        "out_line": 5,
        "out_line_color": "FFFFFF",
        "out_line_opacity": "00",
        "align": "center",
        "bar": true,
        "bar_color": "000000",
        "bar_opacity": "88",
        "animation": "none"
      }
    }    
  ]				
}
```
where:

	+	output_width,output_height - this values will be used for resize of overlays ( depend of output video resolution)
	+	type - values `image`, `video` or `text`
	+	start	- start time from begining of output video
	+	end	- end time from begining of output video
	+	fade_in	- duration of `fade in` of overlay ( 0 - disable )
	+	fade_out	- duration of `fade out` of overlay ( 0 - disable )
	+	file	- source of video or image
	+	x	- top left coordinate of overlay ( for text overlay depend of align, see note bellow )
	+	y	- top left coordinate of overlay ( for text overlay depend of align, see note bellow )
	+	width	- for video or image resize to this width
	+	height	- for video or image resize to this height
	+	text	- in text overlays can be used `hard line break` ( \\N ) and `hard space` ( \\h ) ( see http://docs.aegisub.org/3.2/ASS_Tags/ )

NOTE: for text overlays `x`, `y` and `align` values are related. 
  + left: x,y - mean top left corner of text
  + rigth: x,y - mean top right of text text
  + center: x,y - mean top center of text



#### data.smil
```xml
<?xml version="1.0" encoding="UTF-8"?>
<smil>
    <head>
    </head>
    <body>
      <stream name="myStream"></stream>
      <playlist name="p_303997902" playOnStream="myStream" repeat="false" scheduled="2018-12-02 21:42:06">
      <video src="mp4:/0xvSx5s76M.mp4" start="0" length="23.456"/>
      </playlist>
      <stream name="myStream"></stream>
      <playlist name="p_303997902" playOnStream="myStream" repeat="false" scheduled="2018-12-02 21:42:06">
      <video src="rtmp://my_ip/stream" start="0" length="23.456"/>
      </playlist>	
      <playlist name="p_303997902" playOnStream="myStream" repeat="false" scheduled="2018-12-02 21:42:06">
      <video src="http://my_ip/vod/vidpQLJMlIexv.mp4/playlist.m3u8" start="0" length="23.456"/>
      </playlist>			      		
</body>
</smil>			
```
where:

	+	myStream - name of output hls stream ( eg http://your_ip/video/hls/myStream/480p.m3u8 )
	+	mp4:/0xvSx5s76M.mp4  - file stored in mp4Basedir ( see `data/config.json` description )



#### Recomendation
	1.  Require a lot of CPU ( minimum 8 cores )


#### Todo
	1.	Empty video for unaviable sources
	2.	Fade transition between concatenated videos
	3. 	Colored box(bar) for overlayed text, fix fontname by filename, auto wraping text
	4.	Simple copy ( without processing ) for prepared videos


  Licensing
  ---------
	GNU

  Contacts
  --------

     o korolev-ia [at] yandex.ru
     o http://www.unixpin.com
```
