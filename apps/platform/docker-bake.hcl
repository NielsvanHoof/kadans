# Docker has no include directive: a stage in one Dockerfile cannot be FROM'd by
# another. `contexts = { base = "target:base" }` is what lets each environment
# keep its own Dockerfile while sharing one build of docker/base/Dockerfile —
# a single build graph, no registry round-trip, full layer caching.
#
# Always pass -f: buildx also auto-loads compose.yaml as a bake definition, and
# an explicit file keeps the graph to these targets only.
#
#   docker buildx bake -f docker-bake.hcl --print production
#   docker buildx bake -f docker-bake.hcl --load production
#   REVERB_PUBLIC_KEY=... VITE_REVERB_HOST=... docker buildx bake -f docker-bake.hcl staging

variable "TAG" {
  default = "dev"
}

# Compiled into the JavaScript bundle at build time — an image cannot be
# repointed at a different Reverb host without rebuilding.
variable "REVERB_PUBLIC_KEY" {
  default = ""
}

variable "VITE_REVERB_HOST" {
  default = ""
}

variable "VITE_REVERB_PORT" {
  default = "443"
}

variable "VITE_REVERB_SCHEME" {
  default = "https"
}

group "default" {
  targets = ["production"]
}

target "base" {
  context    = "."
  dockerfile = "docker/base/Dockerfile"
  target     = "app-base"

  args = {
    REVERB_PUBLIC_KEY  = REVERB_PUBLIC_KEY
    VITE_REVERB_HOST   = VITE_REVERB_HOST
    VITE_REVERB_PORT   = VITE_REVERB_PORT
    VITE_REVERB_SCHEME = VITE_REVERB_SCHEME
  }
}

target "production" {
  context    = "."
  dockerfile = "docker/production/Dockerfile"
  contexts   = { base = "target:base" }
  tags       = ["qompose-platform:production-${TAG}"]
}

target "staging" {
  context    = "."
  dockerfile = "docker/staging/Dockerfile"
  contexts   = { base = "target:base" }
  tags       = ["qompose-platform:staging-${TAG}"]
}
