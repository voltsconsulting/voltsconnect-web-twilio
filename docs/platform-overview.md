# Platform Overview

This platform is a web-based CRM and Twilio communications hub.

## Core capabilities

## 1) Contacts (CRM)

- Store contacts with phone number, name, email
- Organize contacts with Groups and Tags
- Optional Custom Fields for personalization

## 2) Inbox messaging

- Two-way messaging with contacts
- SMS templates
- MMS media upload + send
- STOP/START automation support (opt-out compliance)

## 3) Broadcast campaigns

- Send bulk SMS campaigns to:
  - All contacts
  - Search filter
  - Group
  - Tag
  - Pasted numbers

- Preview before sending (eligible vs opted out)
- Send now or schedule
- Cron-driven processing for scheduled sends
- Throttling controls:
  - Batch size
  - Delay per message

- Campaign history + analytics
- Cancel scheduled/running campaigns

## 4) Settings / administration

- Timezone
- SMTP (optional)
- Roles/permissions (RBAC)
- Add-ons management

## Add-ons (planned)

- Flow Builder / Automations (Coming soon)
- Advanced Analytics (Coming soon)
- Integrations / API / Webhooks (Coming soon)
