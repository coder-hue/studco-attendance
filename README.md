# StuCo Attendance

A fast, mobile-first attendance app for student council meetings.

## What it does

- Member name selection stored in the browser
- Event-specific QR links using `/checkin?event={id}&token={random-token}`; the server validates the pair and redirects to a clean `/checkin` page
- Configurable open and close check-in windows with manual overrides
- Regular attendance worth 2 points; non-submissions stay blank
- Decorations Day mode with separate 6-point “Left early” and 8-point “Full time” QR codes
- Dance shifts mode with one QR and a per-member check-in window from 10 minutes before through 10 minutes after the assigned shift
- Live officer dashboard with manual attendance corrections
- Event finalization, which marks non-check-ins absent
- Roster management and CSV member import

## Local setup

The local `.env` is already configured for testing. Start the app with:

```bash
php -S localhost:8080 -t public public/router.php
```

Open [http://localhost:8080](http://localhost:8080). Staff sign in at `/admin`.

The initial local advisor account is configured in `.env`. To create the database tables, run:

```bash
php scripts/install.php
```

To preload the current roster, run:

```bash
php scripts/seed_members.php
```

## Core flow

1. An officer creates an event and opens check-in. Decorations Day events expose two QR codes that can be opened and closed independently.
2. Students scan its QR code. On a new phone, they type and tap their name once; on later meetings, attendance records immediately.
3. The live event dashboard updates automatically.
4. An officer can correct any record or finalize the event when it ends. A Full time scan upgrades an earlier Left early result from 6 to 8 points and never lowers an 8-point result.

## Dance shifts

Create attendance and choose **Dance shifts · 2 points**. On the event page, either paste a list such as:

```text
Ella Adamson, 6:30 PM
Lincoln Aguilar, 7:00 PM
```

or set individual shift times in the roster. Importing a pasted list replaces the current shift list. The event can still be closed manually, but while it is open each member can only record attendance from 10 minutes before through 10 minutes after their own shift.
