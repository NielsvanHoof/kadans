package storage

import (
	"bytes"
	"io"
)

// newReader gives the SDK a seekable body, which lets it hash and retry the
// upload without buffering the payload a second time.
func newReader(body []byte) io.ReadSeeker {
	return bytes.NewReader(body)
}
