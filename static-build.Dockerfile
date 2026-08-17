# FrankenPHP 静态二进制构建文件
#
# 构建：
#   docker build -t yoliarkcloud-static -f static-build.Dockerfile .
#
# 提取二进制：
#   docker cp $(docker create --name yoli-tmp yoliarkcloud-static):/go/src/app/dist/frankenphp-linux-x86_64 ./yoliarkcloud ; docker rm yoli-tmp
#
# 运行：
#   ./yoliarkcloud php-server --domain your-domain.com
#
# 数据目录：
#   默认在当前工作目录下的 storage/ 目录
#   可通过环境变量 YOLIARK_DATA_DIR 指定

FROM --platform=linux/amd64 dunglas/frankenphp:static-builder-gnu

WORKDIR /go/src/app/dist/app

# 复制应用代码
COPY . .

# 构建静态二进制
# 指定项目需要的 PHP 扩展
WORKDIR /go/src/app
RUN PHP_EXTENSIONS="pdo_sqlite,pdo_mysql,gd,curl,openssl,zip,bcmath,sodium,opcache,mbstring,fileinfo" \
    EMBED=dist/app/ ./build-static.sh
