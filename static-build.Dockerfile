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

FROM dunglas/frankenphp:static-builder-musl

WORKDIR /go/src/app/dist/app

# 复制应用代码
COPY . .

# 构建静态二进制
WORKDIR /go/src/app
RUN EMBED=dist/app/ ./build-static.sh
