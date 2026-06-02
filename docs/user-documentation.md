# End-User Documentation

## Getting started

### Logging in

- Go to `/app` and sign in with your provided credentials.

### Navigation

- Inbox: 1:1 messaging
- Contacts: manage CRM
- Broadcast: bulk campaigns
- Settings: system configuration

## Inbox (1:1 messaging)

### Send SMS

- Select a conversation
- Type a message
- Click Send

### Send MMS (media)

- Click the media picker
- Choose a file (image/video/audio/pdf)
- Optionally type a message body
- Click Send

Notes:

- Maximum file size is enforced by server rules.
- Recipients who opted out (STOP) cannot be messaged when opt-out enforcement is enabled.

## Contacts

- Add contacts manually
- Use Groups and Tags to segment
- Add Custom Fields for personalization

## Broadcast campaigns

### Preview

- Choose an audience mode
- Click Preview to see eligible count and samples

### Send now

- Choose From number
- Write message (use merge fields like `{first_name}`)
- Click Send

### Schedule

- Pick Schedule mode
- Choose date/time
- Click Schedule

### Throttling (optional)

- Enable throttling
- Set batch size and delay

Recommended starting settings (shared hosting):

- Batch size: 25 to 50
- Delay: 100ms to 300ms

### Campaign history / analytics

- Open Broadcast → Campaigns to view jobs.
- Open Broadcast → Analytics to view details.

### Cancel a campaign

- Go to Broadcast → Campaigns
- Click Cancel on a scheduled/running campaign

## Settings

### Timezone

- Set the app timezone so scheduling matches your local time.

### Add-ons

- Add-ons can be enabled/disabled by an admin.
- Some add-ons may show Coming Soon.
