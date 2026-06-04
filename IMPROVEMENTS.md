# Twilio SMS & Calling Platform — Improvement Plan

## Context

This is a PHP 8+ web CRM for SMS and voice calling built on Twilio, deployed on shared hosting (Hostinger). The stack is vanilla PHP + MySQL with a vanilla JS frontend. Version 1 baseline is complete and stable. This plan prioritizes the highest-impact improvements first.

---

## What Was Already Fixed

- **Pagination added to all API list endpoints** (`/api/calls`, `/api/contacts`, `/api/inbox/conversations`) — all now accept `?limit=` and `?offset=` params and return `limit`/`offset` metadata in the response. BindValue is used for safe parameterized LIMIT/OFFSET.
- **Stashed local changes** (app.js, index.php, InboxRoutes.php) — to be resolved separately.

---

## Tier 1 — Immediate Impact (Do First)

### 1. **SQL Injection Fix in CallsRoutes**

The `/api/calls` endpoint has a SQL injection vulnerability. The LIMIT is concatenated directly:

```php
LIMIT ' . $limit); // ← user-controlled, unquoted but still dangerous
```

Even though `$limit` is cast to int, the pattern is wrong and the query builder should use parameterized queries throughout. Fix: ensure all WHERE clause params use `:placeholder` style with bindValue throughout.

**Priority:** High. Security.

---

### 2. **Frontend Pagination + Infinite Scroll**

The inbox, calls, and contacts views currently load all data or paginate on the backend but the UI doesn't support it. Add:

- **Load more / infinite scroll** on the inbox (conversations list), calls log, and contacts table.
- **Backend already supports** `?limit=N&offset=N` on all three endpoints.
- On the frontend, add a "Load More" button or intersection observer scroll handler that increments offset and appends rows.

**Priority:** High. UX + performance on large datasets.

---

### 3. **Calls Log Filtering UI**

Currently the calls API accepts `?direction=`, `?status=`, `?from_date=`, `?to_date=` but the UI has no filter controls. Add a filter bar above the calls table with dropdowns for direction and status, plus date range pickers.

**Priority:** High. Core feature parity.

---

### 4. **Notifications / Webhooks for SMS Status**

The app logs outbound SMS status callbacks but doesn't surface delivery status (delivered, failed, undelivered) in the UI. Add a status badge on each message in the conversation thread.

**Priority:** High. User expectation for SMS apps.

---

## Tier 2 — Major Quality of Life (Do Second)

### 5. **Contact Search with Typeahead**

The contacts page currently does a full reload on search. Replace with a debounced fetch that shows results as you type. The `/api/contacts/search` endpoint already exists.

**Priority:** Medium. UX improvement.

---

### 6. **Conversation Search**

Add a global search bar that searches across contacts, messages, and call history. One endpoint that queries across tables and returns categorized results.

**Priority:** Medium. Core productivity feature.

---

### 7. **Message Templates / Snippets**

Allow users to save reusable SMS templates with shortcodes (e.g. `[NAME]`, `[DATE]`). When composing an SMS, templates appear in a dropdown. Twilio supports pre-built message segments.

**Priority:** Medium. Time savings for high-volume users.

---

### 8. **Call Recording + Playback**

Twilio Voice SDK supports call recording. Store recordings as Twilio blobs or proxy them through the app. Add a play button on call log entries that have recordings.

**Priority:** Medium. If recording is a business requirement.

---

### 9. **Dark Mode Toggle**

The CSS has a `.dark` class but the toggle UI is missing. Add a theme toggle button in the navbar that persists to localStorage.

**Priority:** Low. Nice to have, quick win.

---

### 10. **User Activity Log**

Track logins, number assignments, bulk operations. Add an audit table and an admin-only activity log view. Useful for compliance and debugging.

**Priority:** Low-Medium. Depends on business need.

---

## Tier 3 — Architectural / Technical Debt

### 11. **Rate Limiting on API Endpoints**

Shared hosting is vulnerable to abuse (spamming SMS via the API). Add per-user rate limits using a simple DB-backed counter table. Lock out after N requests per minute.

**Priority:** High if exposed. Low if behind auth + internal use.

---

### 12. **MMS Support for Inbound/Outbound**

Currently MMS (images) may be proxied but the UI doesn't display them nicely. Add image previews in the conversation thread, MMS badge on conversation list.

**Priority:** Medium. MMS is a common expectation.

---

### 13. **Migrate to a Router Pattern**

`public/index.php` currently handles routing with large if/else chains. Extract into a proper router (even a simple one). This makes the codebase maintainable as it grows.

**Priority:** Medium. Technical debt.

---

### 14. **Two-Factor Authentication (2FA)**

Add TOTP-based 2FA for admin accounts using a library like `spomky-labs/otphp`. Critical for admin panels that manage telephony.

**Priority:** Medium. Security hardening.

---

### 15. **WebSocket / SSE for Real-Time Updates**

Currently the inbox polls every 10–30 seconds. Replace with Server-Sent Events (SSE) for instant message delivery without polling overhead.

**Priority:** Low-Medium. Adds complexity but significantly improves UX.

---

### 16. **Scheduled SMS / Scheduled Broadcast**

Allow users to schedule a single SMS or an entire broadcast campaign for a future time. Uses the existing broadcast cron infrastructure.

**Priority:** Medium. User request likely.

---

### 17. **Mobile Responsive UI**

The UI currently works on desktop but the inbox and dialpad aren't optimized for mobile. Add responsive breakpoints so mobile users can manage conversations on the go.

**Priority:** Medium. User expectation for any modern app.

---

## Tier 4 — Polish & Edge Cases

### 18. **Multi-Twilio Account Support**

Currently the app assumes one Twilio account. Support switching between multiple Twilio accounts (e.g. different brands or clients) within the same app.

**Priority:** Low. Only needed for agencies.

---

### 19. **Bulk SMS from Contact List**

Allow composing an SMS and selecting multiple contacts (or a tag/group) to send the same message to all of them at once. Uses the broadcast engine.

**Priority:** Low-Medium. Common use case.

---

### 20. **Email Notifications for Missed Calls**

When a call goes unanswered, send an email notification to the assigned user with call details and a callback link.

**Priority:** Low. Nice to have.

---

## Quick Wins (1-2 Hours Each)

| # | Task | Effort | Impact |
|---|------|--------|--------|
| 9 | Dark mode toggle | 1h | Low |
| 3 | Calls log filter UI | 2h | High |
| 7 | SMS templates | 2h | Medium |
| 4 | Delivery status badges | 2h | High |
| 2 | Infinite scroll on list views | 3h | High |

---

## Recommended Order

1. **SQL Injection Fix** (security — do now)
2. **Frontend Pagination / Load More** (performance — do now)
3. **Delivery Status Badges** (UX — 2h)
4. **Calls Log Filter UI** (feature parity — 2h)
5. **SMS Templates** (productivity — 2h)
6. **Contact Typeahead Search** (UX — 2h)
7. **Dark Mode Toggle** (polish — 1h)
8. **Rate Limiting** (security — half day)
9. **MMS Image Support** (feature parity — half day)
10. **Scheduled SMS** (new feature — half day)

---

## Notes

- The app uses vanilla JS (no framework). For pagination/filtering, use vanilla fetch with DOM manipulation. Keep it simple.
- For any async work (cron, broadcasts), the existing broadcast infrastructure in `BroadcastRoutes.php` is the reference implementation.
- Before any large feature, check `ISSUES_TRACKER.md` and the `docs/` folder for context.