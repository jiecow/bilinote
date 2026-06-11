FROM php:8.1-apache

# ffmpeg + python3 (yt-dlp 依赖) + oniguruma (mbstring 依赖)
RUN apt-get update && apt-get install -y \
    ffmpeg \
    python3 \
    curl \
    libonig-dev \
    && rm -rf /var/lib/apt/lists/*

# yt-dlp
RUN curl -L https://github.com/yt-dlp/yt-dlp/releases/latest/download/yt-dlp \
    -o /usr/local/bin/yt-dlp && chmod a+rx /usr/local/bin/yt-dlp

# PHP 扩展
RUN docker-php-ext-install pdo pdo_mysql mbstring

# Apache rewrite
RUN a2enmod rewrite

# 复制应用
COPY www/ /var/www/html/
COPY uploads/ /var/www/uploads/
RUN chown -R www-data:www-data /var/www && chmod 777 /var/www/uploads/videos

# PHP 上传限制
RUN echo "upload_max_filesize = 500M\npost_max_size = 520M\nmax_execution_time = 600" \
    > /usr/local/etc/php/conf.d/bilinote.ini

EXPOSE 80
