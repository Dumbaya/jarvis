#!/bin/sh
set -e

# Windows 바인드 마운트나 root로 실행한 artisan 때문에 storage가 root 소유가 되면
# PHP-FPM(www-data)이 로그와 캐시를 쓰지 못해 500이 난다. 시작할 때마다 바로잡는다.
mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

exec "$@"
