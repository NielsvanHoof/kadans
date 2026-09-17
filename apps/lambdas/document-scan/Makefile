# Honours the apps/lambdas contract: `make build` writes dist/function.zip,
# `make test` runs the tests. See ../README.md.
#
# provided.al2023 requires the executable inside the zip to be named `bootstrap`.
# CGO stays off: the PDF renderer runs PDFium as WebAssembly under wazero, so
# this cross-compiles to one static binary from any host.

GOOS   ?= linux
GOARCH ?= arm64

.PHONY: build test vet clean

build: clean
	CGO_ENABLED=0 GOOS=$(GOOS) GOARCH=$(GOARCH) go build -trimpath -ldflags="-s -w" -o dist/bootstrap .
	cd dist && zip -q -X function.zip bootstrap

test:
	go test ./...

vet:
	go vet ./...

clean:
	rm -rf dist
