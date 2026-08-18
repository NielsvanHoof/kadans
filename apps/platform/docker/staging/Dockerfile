# syntax=docker/dockerfile:1

# Staging is production's artifact with one addition: an inert Xdebug. Composer
# still installs --no-dev (via the shared base) so the container under test
# behaves like the one that ships.
#
#   docker buildx bake -f docker-bake.hcl staging
FROM base AS runtime

USER root
RUN install-php-extensions xdebug

COPY docker/staging/php.ini /usr/local/etc/php/conf.d/staging.ini
COPY docker/staging/entrypoint.sh /usr/local/bin/qompose-entrypoint

USER www-data

ENTRYPOINT ["qompose-entrypoint"]
CMD ["web"]
