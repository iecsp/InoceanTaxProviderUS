# Changelog

## 2.0.0 — 2026-11-01

- Replace the unverified, repeatedly decoded nationwide JSON with versioned, indexed `ino_` tables and verified CA/WA official-source adapters.
- Add authenticated asynchronous synchronization, a native plugin configuration screen, worker recovery, immutable active versions, checksums, validity dates and retention commands.
- Add opt-in official California address verification, local daily/versioned caching, queue deduplication and ambiguity blocking; no HTTP in checkout.
- Correct gross/net formulas, mixed-exempt discount allocation, shipping handling across deliveries, stale payload removal, standard tax serialization and payment total adjustment.
- Reject unsupported calculations explicitly; prohibit silently recalculating historical orders using today's rates.
- Preserve native tax definitions on uninstall and keep providers inactive until plugin activation.
- Remove stale compiled resources, obsolete PHPStan suppressions and broad document/storefront/admin template replacements.
- Add offline, isolated MySQL, admin component, kernel and opt-in live-source checks. Drop 6.6 compatibility; prepare dependency ranges for 6.8 without claiming runtime certification.
