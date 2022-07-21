FROM alpine:3.14

ENV MEMCACHED_PORT=11211

RUN apk --update add php7-apache2 php7-pecl-memcache php7-cli php7-json php7-curl php7-openssl php7-mbstring php7-sockets php7-xml php7-zlib git

RUN rm -rf /var/www/localhost/htdocs \
 && mkdir -p /var/www/localhost/htdocs \
 && chown -R apache:apache /var/www/localhost/htdocs \
 && ln -sf /dev/stdout /var/log/apache2/access.log \
 && ln -sf /dev/stderr /var/log/apache2/error.log

WORKDIR /var/www/localhost/htdocs
COPY index.php .

EXPOSE 80

CMD ["/usr/sbin/httpd", "-D", "FOREGROUND"]
