# 运行时
FROM ghcr.io/pfm-powerforme/s6-debian-php:8.4 AS runtime
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

RUN wget https://wordpress.org/wordpress-${WP_VERSION}.tar.gz -O /tmp/wordpress.tar.gz && \
     mkdir -p /usr/src && \
     tar -xzf /tmp/wordpress.tar.gz -C /usr/src/ && \
     rm /tmp/wordpress.tar.gz && \
     cp -r /usr/src/wordpress/* /var/www/ && \
     rm -rf /var/www/wp-content && \
     mkdir -p /var/www/wp-content && \
     wget https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar -O /usr/local/sbin/wp && \
     chmod +x /usr/local/sbin/wp && \
     chmod 640 /wordpress/wp-config.php && \
     mv /wordpress/wp-config.php /var/www/wp-config.php && \
     mv /wordpress/wp-cli.yml /var/www/wp-cli.yml && \
     rm -rf /wordpress

RUN /pfm/bin/fix_env
WORKDIR /var/www/wp-content
VOLUME /var/www/wp-content
