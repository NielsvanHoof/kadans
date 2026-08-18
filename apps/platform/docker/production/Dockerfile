# syntax=docker/dockerfile:1

# `base` is the app-base stage of docker/base/Dockerfile, wired in as a named
# build context by docker-bake.hcl. Build through bake, not `docker build`:
#
#   docker buildx bake -f docker-bake.hcl production
FROM base AS runtime

COPY docker/production/php.ini /usr/local/etc/php/conf.d/production.ini
COPY docker/production/entrypoint.sh /usr/local/bin/qompose-entrypoint

USER www-data

ENTRYPOINT ["qompose-entrypoint"]
CMD ["web"]
