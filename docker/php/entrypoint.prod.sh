#!/bin/sh
set -e

# 영구 볼륨을 storage에 마운트하면 처음에는 비어 있으므로 필요한 폴더를 만든다.
mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache

# 운영에서는 설정, 라우트, 이벤트를 미리 합쳐 둔다(요청마다 파일을 읽고 해석하지 않는다).
# 환경변수가 이미 주입된 컨테이너 시작 시점에 만들어야 올바른 값이 들어간다.
if [ "${APP_ENV}" = "production" ]; then
    php artisan config:cache
    php artisan route:cache
    php artisan event:cache
fi

# 위에서 root로 만든 캐시 파일도 php-fpm 워커(www-data)가 읽고 쓸 수 있어야 한다.
chown -R www-data:www-data storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

exec "$@"
