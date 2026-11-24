# WhatsApp template draft editor

## 2026-11-24 — F02 activation, WhatsApp preflight and draft editor

- #125 merged at b95019d10d813892f86dd9009fcb94d692850d53 (9/9); #126 merged at bb8c4f8c0f51e8bfc5fae05cad0b9c00ab8996a0 (5/5). No actual activation.
- #127 initial CI 4/5: 1,809 PHP tests passed, 8 skipped, 8,375 assertions. Pint reported one style issue in ExternalBackupNotificationMessage; corrected by splitting the metadata array from the return at b58c3c2697fbe0e15995c666fad49058c4aa8091. Corrected Pint passed; complete corrected CI remains required before merge.
- F04 local WhatsApp draft editor candidate: persisted separate whatsapp-draft namespace, static title and body editor, compile positional Meta text, ordered safe parameter mapping and synthetic preview. Admin-only; never modifies approved transport configuration, never calls provider. Local draft validation does not establish Meta approval. Operator must obtain approved template and configure matching mapping after review. Combined CI and end-to-end staging remain release gates.

The draft editor reuses the existing template table with the isolated `whatsapp-draft` namespace; Email and Telegram list/save routes still restrict channels. No schema change or live send route is introduced. A title is static, and the body uses only the approved-field allowlist. Copy the generated numbered placeholders, example values and ordered mapping to the operator's approval workflow. Review the actual provider-approved static text and locale before activating the server configuration. Do not paste account secrets or real backup data into drafts. Rollback: remove the UI/API feature via reviewed PR; retain draft rows unless an authorized data retention action is requested.
