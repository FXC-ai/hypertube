---
paths:
  - 'torrent-client/**'
---

# Torrent Client

## Verify Archive.org boundary pieces by selected file
When a torrent piece straddles a selected movie and an unselected Archive.org file, the web-seed must not fetch the unselected bytes merely to satisfy the old piece hash. Omit those bytes, keep the boundary piece unavailable, and mark it verified only after every touched selected file matches Archive.org's published whole-file SHA-1.
