#!/usr/bin/env bash
set -e

# Update package lists and install base packages
apt-get update
apt-get install -y --no-install-recommends \
    curl \
    wget \
    openssh-client \
    openssh-server \
    git \
    vim-tiny \
    jq \
    procps \
    iputils-ping \
    ca-certificates \
    libjpeg62-turbo-dev \
    libpng-dev \
    libwebp-dev \
    libfreetype6-dev \
    libxpm-dev \
    libzip-dev \
    zip \
    vim \
    unzip

# Enable ssh
sudo systemctl start ssh && sudo systemctl enable ssh

# Configure git
git config --global --add safe.directory /var/www/html
git config --system core.pager cat

# Clean up apt lists
rm -rf /var/lib/apt/lists/*

# Fix www-data UID/GID
groupmod -g 1000 www-data
usermod -u 1000 www-data

# Apache override configuration
cat <<EOF > /etc/apache2/conf-enabled/allow-override.conf
<Directory /var/www/html>
    AllowOverride All
</Directory>
EOF

# Update Apache document root
sed -i 's|DocumentRoot .*|DocumentRoot /var/www/html/public|' /etc/apache2/sites-available/000-default.conf

# Determine Debian release
release=$(grep VERSION_CODENAME /etc/os-release | cut -d= -f2)

# Fix sources list for old Debian releases
if [ "$release" = "stretch" ] || [ "$release" = "buster" ]; then
    sed -i 's|deb.debian.org/debian|archive.debian.org/debian|g' /etc/apt/sources.list
    sed -i '/security.debian.org/d' /etc/apt/sources.list

    if [ -d /etc/apt/sources.list.d ]; then
        for f in /etc/apt/sources.list.d/*; do
            [ -f "$f" ] || continue
            sed -i 's|deb.debian.org/debian|archive.debian.org/debian|g' "$f"
            sed -i '/security.debian.org/d' "$f"
        done
    fi

elif [ "$release" = "bullseye" ]; then
    sed -i 's|archive.debian.org/debian|deb.debian.org/debian|g' /etc/apt/sources.list
    sed -i 's|http://deb.debian.org/debian-security|http://security.debian.org/debian-security|g' /etc/apt/sources.list
fi

# Disable Check-Valid-Until
echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99no-check-valid-until

# Determine PHP version
phpver=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')

# Configure GD extension
if [ "$phpver" = "7.0" ] || [ "$phpver" = "7.1" ] || [ "$phpver" = "7.2" ] || [ "$phpver" = "7.3" ]; then
    docker-php-ext-configure gd --with-jpeg-dir=/usr/include/ --with-freetype-dir=/usr/include/
else
    docker-php-ext-configure gd --with-jpeg --with-freetype --with-webp
fi

# Install PHP extensions
docker-php-ext-install gd mysqli pdo_mysql zip
docker-php-ext-enable gd mysqli pdo_mysql zip

# Enable Apache modules
a2enmod rewrite
a2enmod headers

# -----------------------------
# 1. Create logs and tmp directories
# -----------------------------
mkdir -p /var/www/html/logs /var/www/html/tmp
chown -R www-data:www-data /var/www/html/logs /var/www/html/tmp
chmod -R 755 /var/www/html/logs /var/www/html/tmp

# -----------------------------
# 2. Redirect Apache logs to project folder
# -----------------------------
APACHE_LOG_DIR=/var/www/html/logs
sed -i "s|ErrorLog .*|ErrorLog ${APACHE_LOG_DIR}/apache_error.log|" /etc/apache2/sites-available/000-default.conf
sed -i "s|CustomLog .*|CustomLog ${APACHE_LOG_DIR}/apache_access.log combined|" /etc/apache2/sites-available/000-default.conf

# -----------------------------
# 3. PHP development configuration
# -----------------------------
cat <<'EOF' > /usr/local/etc/php/conf.d/dev.ini
; -----------------------------
; PHP Dev Settings
; -----------------------------
display_errors = On
display_startup_errors = On
log_errors = On
error_log = /var/www/html/logs/php_errors.log
error_reporting = E_ALL

max_execution_time = 120
memory_limit = 512M
post_max_size = 50M
upload_max_filesize = 50M
session.save_path = "/var/www/html/tmp"

; Opcache disabled for dev to see changes immediately
opcache.enable = 0
opcache.validate_timestamps = 1
opcache.revalidate_freq = 0
EOF

# -----------------------------
# 4. Optional: Enable Xdebug if installed
# -----------------------------
if php -m | grep -qi xdebug; then
    cat <<'EOF' > /usr/local/etc/php/conf.d/xdebug.ini
xdebug.mode=debug
xdebug.start_with_request=yes
xdebug.client_host=host.docker.internal
xdebug.client_port=9003
EOF
fi

# -----------------------------
# 5. Restart Apache to pick up new configs
# -----------------------------
apachectl -k restart
