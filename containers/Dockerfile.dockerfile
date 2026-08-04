# 运行时
FROM dunglas/frankenphp:1-php8-trixie AS builder
ARG REPO
# eg. amd64 | arm64
ARG ARCH
# eg. x86_64 | aarch64
ARG CPU_ARCH
ARG TAG
# eg. latest
ARG IMAGE_VERSION
ENV REPO=$REPO \
     ARCH=$ARCH \
     CPU_ARCH=$CPU_ARCH \
     WP_VERSION=$TAG \
     IMAGE_VERSION=$IMAGE_VERSION \
     WP_CLI_CONFIG_PATH=/var/www/wp-cli.yml

COPY rootfs/ /

RUN apt update && apt install -y wget && rm -rf /var/lib/apt/lists/*

RUN wget https://wordpress.org/wordpress-${WP_VERSION}.tar.gz -O /tmp/wordpress.tar.gz && \
     mkdir -p /usr/src && \
     mkdir -p /var/www && \
     tar -xzf /tmp/wordpress.tar.gz -C /usr/src/ && \
     rm /tmp/wordpress.tar.gz && \
     # 1. 复制除 wp-content 以外的所有东西到 /var/www
     cp -r /usr/src/wordpress/* /var/www/ && \
     # 2. 彻底移除 /var/www/wp-content（防止建立 root 属性的 wp-content 目录）
     rm -rf /var/www/wp-content && \
     wget https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -O /usr/local/sbin/wp && \
     chmod +x /usr/local/sbin/wp && \
     chmod 640 /wordpress/wp-config.php && \
     mv /wordpress/wp-config.php /var/www/wp-config.php && \
     mv /wordpress/wp-cli.yml /var/www/wp-cli.yml && \
     mv /wordpress/system-mu-plugins /var/www/system-mu-plugins && \
     rm -rf /wordpress

# Add additional PHP extensions here
RUN install-php-extensions amqp \
     apcu \
     bcmath \
     brotli \
     bz2 \
     calendar \
     dba \
     exif \
     ffi \
     gd \
     gettext \
     gmp \
     igbinary \
     imagick \
     intl \
     memcached \
     mongodb \
     mysqli \
     pcntl \
     pdo_mysql \
     pdo_pgsql \
     pgsql \
     redis \
     soap \
     sockets \
     sysvmsg \
     sysvsem \
     sysvshm \
     uuid \
     xmldiff \
     xmlrpc \
     zip \
     zstd

# Copy shared libs of frankenphp and all installed extensions to temporary location
# You can also do this step manually by analyzing ldd output of frankenphp binary and each extension .so file
RUN <<-EOF
	apt-get update
	apt-get install -y --no-install-recommends libtree
	mkdir -p /tmp/libs
	for target in $(which frankenphp) \
		$(find "$(php -r 'echo ini_get("extension_dir");')" -maxdepth 2 -name "*.so"); do
		libtree -pv "$target" 2>/dev/null | grep -oP '(?:── )\K/\S+(?= \[)' | while IFS= read -r lib; do
			[ -f "$lib" ] && cp -n "$lib" /tmp/libs/
		done
	done
EOF

RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates && rm -rf /var/lib/apt/lists/*


# Distroless Debian base image, make sure this matches the Debian version of the builder
# Distroless Debian base image
# ---------------- Final 阶段 ----------------
FROM gcr.io/distroless/base-debian13

COPY --from=builder /usr/local/bin/frankenphp /usr/local/bin/frankenphp
COPY --from=builder /usr/local/lib/php/extensions /usr/local/lib/php/extensions
COPY --from=builder /tmp/libs /usr/lib

COPY --from=builder /usr/local/etc/php/conf.d /usr/local/etc/php/conf.d
COPY --from=builder /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

ENV XDG_CONFIG_HOME=/config XDG_DATA_HOME=/data
COPY --from=builder --chown=nonroot:nonroot /data /data
COPY --from=builder --chown=nonroot:nonroot /config /config

# 1. 先复制基础程序（此时 /app 下没有任何 wp-content 目录）
COPY --from=builder --chown=nonroot:nonroot /var/www/ /app

# 2. 用 --chown 首次创建 /app/wp-content（使其所有者直接成为 nonroot）
COPY --from=builder --chown=nonroot:nonroot /usr/src/wordpress/wp-content /app/wp-content
COPY --from=builder --chown=nonroot:nonroot /usr/src/wordpress/wp-content /usr/src/wordpress/wp-content

COPY --from=builder /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/
COPY --chown=nonroot:nonroot docker-entrypoint.php /usr/local/bin/docker-entrypoint.php
COPY Caddyfile /etc/caddy/Caddyfile

USER nonroot
WORKDIR /app/wp-content
VOLUME /app/wp-content

ENTRYPOINT ["frankenphp", "php-cli", "/usr/local/bin/docker-entrypoint.php"]