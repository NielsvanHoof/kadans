# Fixtures

`document.pdf` is a hand-built single-page PDF with a real cross-reference
table, kept text-readable so a reviewer can see what the renderer is being asked
to draw rather than diffing a binary blob.

`malformed.pdf` announces itself as a PDF and then is not one. It exists to prove
a rendering failure costs the thumbnail and nothing else — the scan verdict is
the security-relevant half of this function's output and must still be published.

Raster fixtures are generated in the tests rather than committed, since an
encoder in the standard library produces them more legibly than a checked-in
binary.
