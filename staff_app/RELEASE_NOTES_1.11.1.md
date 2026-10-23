# Staff 1.11.1+2018 — private candidate

- Added a password-recovery guide and copyable link using the configured host/port.
- Fresh installs require the actual server address or Staff QR; no guessed port.
- Saved addresses/credentials remain unchanged. Background failures are visible.
- Read requests get one bounded transient retry; writes/uploads are not replayed.
- Server HTML errors become actionable messages, not JSON parser exceptions.
- Existing native setup/licence/menu/custom confirmations/billing remain available.

26 tests pass; analyzer is clean. Android package identity and signing certificate
match prior releases. Windows is NotSigned and must remain private until signed.
Requires the updated backend for password recovery; local email must be configured.
No production account password or installed runtime was changed by this build.
