#!/usr/bin/env sh

# One image, several roles. ECS runs the same artifact as the web service, the
# queue workers and Reverb by passing a role as the command (see TASK-34.2).
#
#   docker run <image>            # web (the CMD default)
#   docker run <image> worker
#   docker run <image> reverb
#   docker run <image> schedule   # one-shot, for EventBridge -> RunTask
#   docker run <image> migrate    # one-shot
#
# Anything unrecognised is executed verbatim, so `<image> php -v` still works.

set -eu

CADDYFILE=/var/www/html/docker/staging/Caddyfile
ROLE="${1:-web}"

case "$ROLE" in
    web | worker | reverb | schedule | textract-consumer | transcribe-consumer | migrate)
        # Cache config, routes, views and events once per container start.
        # Deliberately skipped for the passthrough below: `optimize` boots the
        # app, so a container with broken configuration could not otherwise be
        # inspected — which is precisely when you need a shell in it.
        php artisan optimize
        ;;
esac

case "$ROLE" in
    web)
        exec php artisan octane:start \
            --server=frankenphp \
            --host=0.0.0.0 \
            --port=8080 \
            --admin-port=2019 \
            --caddyfile="$CADDYFILE"
        ;;
    worker)
        # All four queues, default first so interactive jobs are not starved
        # behind an OCR run (.ai/rules/jobs.md). WORKER_QUEUES lets a deployment
        # run one service per queue without rebuilding the image. Retries and
        # timeouts stay on the job classes' own attributes.
        exec php artisan queue:work \
            --queue="${WORKER_QUEUES:-default,ocr,transcription,dossier-export}" \
            --max-time="${WORKER_MAX_TIME:-3600}"
        ;;
    reverb)
        exec php artisan reverb:start --host=0.0.0.0 --port=8080
        ;;
    schedule)
        exec php artisan schedule:run
        ;;
    textract-consumer)
        exec php artisan textract:consume
        ;;
    transcribe-consumer)
        exec php artisan transcribe:consume
        ;;
    migrate)
        exec php artisan migrate --force
        ;;
    *)
        exec "$@"
        ;;
esac
