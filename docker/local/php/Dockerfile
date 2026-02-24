FROM php:8.4-fpm-alpine

# Set arguments
ARG USER_ID=1000
ARG GROUP_ID=1000

# Install system dependencies
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    icu-dev \
    oniguruma-dev \
    postgresql-dev \
    freetype-dev \
    libjpeg-turbo-dev \
    libwebp-dev \
    libxpm-dev \
    libavif-dev \
    linux-headers \
    docker-cli \
    $PHPIZE_DEPS

# Install PHP extensions
RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg \
    --with-webp \
    --with-xpm \
    --with-avif \
    && docker-php-ext-install -j$(nproc) \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    pgsql \
    mbstring \
    exif \
    pcntl \
    bcmath \
    gd \
    zip \
    intl \
    opcache

# Install Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Install Xdebug (optional, for debugging)
RUN pecl install xdebug && docker-php-ext-enable xdebug

# Get latest Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create system user to run Composer and Artisan Commands
RUN addgroup -g ${GROUP_ID} appuser && \
    adduser -D -u ${USER_ID} -G appuser appuser

# Set working directory
WORKDIR /var/www/html

# Copy existing application directory permissions
COPY --chown=appuser:appuser . /var/www/html

# Change current user to appuser
USER appuser

# Expose port 9000 and start php-fpm server
EXPOSE 9000
CMD ["php-fpm"]

