# Volts Connect Web - Improvement Plan

## Context

This is a multi-user PHP web CRM + Twilio communications hub (SMS, MMS, browser calling, voicemail, broadcast campaigns) built on vanilla PHP/MySQL with plain JS/CSS. Currently functional but unpolished — a solid foundation that needs security hardening, architectural cleanup, and UX improvements.

---

## Phase 1 — Stability & Security (Foundation)

### 1.1 CSRF Protection
- Add CSRF token generation and validation to all state-changing endpoints (POST/PUT/DELETE)
- Store tokens in `$_SESSION`, render as hidden fields in forms
- Validate before processing — return 403 on mismatch
- **Impact:** Security hardening, prevents cross-site request forgery

### 1.2 Pagination Controls
- Implement offset-based pagination on all list endpoints (Inbox, Contacts, Calls, Voicemails, Broadcast recipients)
- Add `?page=N&per_page=25` parameters with total count in response headers
- Add `limit` and `offset` to database queries currently fetching 200 rows
- **Impact:** Performance, prevents memory issues with large datasets

### 1.3 Test Suite Setup
- Add PHPUnit as dev dependency
- Write tests for: Db.php (query building), Auth.php (session handling), core routing, key business logic (broadcast throttling, opt-out check, contact dedup)
- Mock PDO and Twilio SDK to avoid external dependencies
- **Impact:** Regression prevention, code confidence

### 1.4 Input Validation & Sanitization Audit
- Audit all `$_POST`/`$_GET` usage — ensure all user input is validated before DB queries
- Add parameterized query enforcement (already mostly done, verify gaps)
- Sanitize phone numbers (E.164 format validation)
- **Impact:** SQL injection prevention, data integrity

---

## Phase 2 — Architecture & Code Quality

### 2.1 Split `public/index.php`
- Extract routing logic into `src/Router.php`
- Extract view rendering into `src/Views.php` or `src/Renderer.php`
- Extract AJAX response helpers into `src/JsonResponse.php`
- Goal: `public/index.php` becomes a 50-80 line bootstrap file
- **Impact:** Maintainability, easier onboarding for new developers

### 2.2 Database Migrations System
- Replace raw `ensureSchema()` SQL with structured migration files (numbered: `001_create_users.sql`, `002_add_contacts.sql`, etc.)
- Add `SchemaMigrations` table (already exists) — use it properly
- Create `php migrate.php up` / `php migrate.php down` CLI tool
- **Impact:** Cleaner schema versioning, easier setup for new environments

### 2.3 Caching Layer
- Add a simple `src/Cache.php` using APCu or file-based fallback
- Cache: Twilio account configs, contact group/tag counts, user permissions, conversation previews
- TTL-based invalidation on writes
- **Impact:** Reduced DB queries per page load

### 2.4 Rate Limiting Improvements
- Move from file-based (`storage/ratelimits/*.json`) to database-backed rate limiting
- Add sliding window algorithm support in addition to fixed window
- **Impact:** Cluster-safety, more accurate rate limiting

---

## Phase 3 — User Experience & Polish

### 3.1 Contact Search Index
- Add a `contacts_search` denormalized table or use SQLite FTS-style approach
- Index: name, phone, email, tags, groups, custom field values
- Real-time re-index on contact save
- **Impact:** Fast contact search as scale grows

### 3.2 Activity Timeline (Contact Detail)
- Add unified timeline view on contact pages showing: all messages, calls, voicemails, notes, broadcast sends
- Chronological, filterable by type
- **Impact:** Better contact context for agents

### 3.3 Broadcast Campaign Improvements
- Add campaign status dashboard (pending, sending, completed, paused)
- Per-recipient delivery status tracking UI
- Retry failed messages option
- Opt-out processing before send (not per-message)
- **Impact:** Better broadcast UX, reliability

### 3.4 Voicemail Improvements
- Store and serve Twilio CDN URLs directly instead of PHP proxy
- Add waveform visualization placeholder (CSS-based)
- Voicemail transcription via Twilio API (if available)
- **Impact:** Faster voicemail playback, better UX

### 3.5 Dark Mode Polish
- Audit current dark mode implementation for contrast issues
- Ensure all components (modals, dropdowns, toasts) respect theme
- Add system preference detection via `prefers-color-scheme`
- **Impact:** Visual polish, accessibility

### 3.6 Toast & Notification Improvements
- Replace current alert() calls with proper toast system
- Add success/error/warning/info variants
- Stack toasts, auto-dismiss with progress indicator
- **Impact:** Professional feel, no page interruptions

---

## Phase 4 — Features & Integrations

### 4.1 Audit Log
- Add `audit_log` table: user_id, action, entity_type, entity_id, old_value, new_value, ip, timestamp
- Log: user CRUD, contact changes, settings changes, role assignments
- Add admin-only audit log viewer
- **Impact:** Accountability, troubleshooting

### 4.2 Outbound Webhooks
- Allow users to configure webhooks (URL + secret) per Twilio account
- Fire on: message sent, message delivered, message failed, call answered, call completed
- HMAC-signed payloads
- Retry with exponential backoff (Twilio handles inbound; this is outbound to user systems)
- **Impact:** Integration capability, automation

### 4.3 API Documentation
- Document all AJAX endpoints (method, params, response shape)
- Use OpenAPI/Swagger format or a simple markdown reference
- Include examples for common operations
- **Impact:** Enables third-party integrations, developer experience

### 4.4 Contact Import Improvements
- Add duplicate detection during CSV import (match on phone or email)
- Show preview before committing
- Handle encoding issues (UTF-8 BOM, Windows-1252)
- **Impact:** Data quality, fewer duplicates

### 4.5 Bulk Contact Operations
- Bulk assign/remove tags and groups (already partially exists)
- Bulk phone number formatting/validation
- Bulk export with field selection
- **Impact:** CRM efficiency

---

## Phase 5 — Polish & Edge Cases

### 5.1 Installer Improvements
- Environment check (PHP version, extensions, file permissions)
- Database connection test with helpful error messages
- Demo data option for evaluation
- **Impact:** Smoother onboarding

### 5.2 Number Porting / Purchase UI
- Add UI for purchasing new Twilio numbers (Twilio API integration)
- Number search with country/type filters
- **Impact:** Self-service number management

### 5.3 Notification Preferences UI
- Per-user notification preferences (email on new message, new voicemail, campaign complete, etc.)
- Per-role notification rules (already has DB tables, needs UI)
- **Impact:** Less email fatigue, better UX

### 5.4 Message Templates with Variables
- Support `{{contact.name}}`, `{{contact.phone}}`, `{{date}}`, etc.
- Live preview before send
- **Impact:** Personalization, less manual editing

### 5.5 Keyboard Shortcuts
- `n` — new message / new contact
- `r` — reply to selected conversation
- `?` — show shortcuts help
- **Impact:** Power-user efficiency

### 5.6 Empty State Designs
- Add helpful empty states for all list views (no contacts, no messages, no calls)
- Include a CTA button (e.g., "Add your first contact")
- **Impact:** Reduced confusion for new users

---

## Prioritization Guidance

| Phase | Effort | Impact | Recommended Start |
|-------|--------|--------|-------------------|
| Phase 1 (Stability) | Medium | High | ✅ Start here |
| Phase 2 (Architecture) | High | High | After Phase 1 |
| Phase 3 (UX) | Medium | Medium | In parallel with Phase 2 |
| Phase 4 (Features) | High | Medium | After core is solid |
| Phase 5 (Polish) | Low-Medium | Low | Ongoing |

**Recommended starting point:** Phase 1.4 (Test Suite) + 1.1 (CSRF) in parallel — these are foundational and independent. Then Phase 1.2 (Pagination) while scoping Phase 2 architecture work.

---

## Out of Scope (For Now)

- Mobile app / PWA
- Full REST API (separate project)
- Multi-tenant isolation (already multi-user but not fully isolated)
- Twilio Flex integration
- AI/ML features (transcription, sentiment, auto-reply suggestions)
- White-labeling / theming
