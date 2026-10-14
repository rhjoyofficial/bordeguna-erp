# Popups, sounds, Relax zone and work schedule: the rules

Owner request, 14 Oct 2026. Plans and reviews (ChatGPT graded each plan before it was built): `H:\WORK\BorDeGuna\popup-sound-engine-2026-10\`. These rules are standing: every change to these four features must keep them.

## Who manages what
- **Popups and sounds** (Settings > Popups, Settings > Sounds): permission `system.popups.manage`, Owner only.
- **Relax zone** (Settings > Relax zone): permission `relax.manage`, Owner and Manager.
- **Work schedule** (Settings > Work schedule): permission `schedule.manage`, Owner and Manager.
- All four are **group-wide**: one set for every business. Their pages still run inside the `business` middleware, because roles are per business (Spatie teams) and a permission check with no business resolved fails. ⚠ That mistake shipped once (audit FND-001): tests must start with no business set (`BusinessContext::forget()`), the way production does.
- Every create, edit, pause, delete and slot change is written to the activity log.

## Each person's own switches (Settings > Appearance)
- Popups on/off (`bismillah_greeting` preference). Popup sound on/off (`popup_sound`, ON by default for everybody, owner's decision 14 Oct).
- Work schedule alerts on/off, and their sound on/off (`schedule_alerts`, both ON by default).
- Muting a sound never hides a popup or an alert.
- Dashboard quiet hours also silence popup and schedule sounds.

## Popups
1. **One popup at a time**, never two stacked. Highest priority first, then the oldest.
2. **Bismillah is built in** (key `bismillah`): it can be edited and paused, never deleted. The server refuses the delete even if called directly.
3. **When:** on sign-in, after N idle minutes (minimum 5, counted across every ERP tab), or both. An idle popup shows once per idle period, in one tab; closing it closes it in every tab.
4. **How often:** every time, once a day per person (Dhaka date), or once per person ever. A once-rule popup is **claimed on the server right before it shows**; the tab or device that loses the claim stays quiet. A paused, out-of-date or other-audience popup cannot be claimed.
5. **Who:** everybody, or chosen role names, matched in the business the person is working in.
6. **Content is plain text only.** Lines are rendered as text, never as HTML. Line kinds: Arabic, heading, body, note.
7. **Media, one per popup:** image (JPG, PNG, WebP, up to 5 MB, slimmed), uploaded video (MP4/WebM, up to 25 MB, streamed with Range), or YouTube (only the 11-character id is stored; the player address is built from it on youtube-nocookie.com). An image or video popup saved with no file is refused.
8. **A video or YouTube popup** never closes by itself, never plays the popup sound, and closes only with its Close button or Escape. Text and image popups close on a click, any key, Close, or after their time.
9. **Never on** guest pages, print pages, or the POS counter while a sale is open: the counter holds popups back until the cart is empty.

## Sounds
10. **Library rules:** MP3, OGG, WAV or M4A, up to 1 MB, checked by the file's **bytes**, not its name. Stored on the private disk with a random name and streamed only to signed-in people.
11. **Each event plays one library sound**, or its built-in sound (`public/sounds`), or silence for Popup: Success, Attention, Error, Popup, Work schedule.
12. **A sound in use cannot be deleted.** Replacing its file keeps every link and updates them all.
13. **Never late:** browsers keep audio locked until the first click or key press after a page load. A sound asked for while locked is dropped, not queued. The click on "Log in" counts, so the sign-in popup can sound.
14. At most one dashboard sound per 3 seconds; a burst is bundled into one, the most serious class wins.

## Relax zone
15. Audio (MP3, OGG, M4A, WAV, up to 20 MB), uploaded video (MP4/WebM, up to 100 MB) or YouTube by id. Checked by bytes, private disk, streamed with Range to signed-in people only.
16. Nothing autoplays. One item plays at a time, across tabs too. YouTube loads only when the person presses play.
17. Paused items and items whose file is missing are left off the page.

## Work schedule alerts
18. Slots are group-wide, with no overlaps on the same weekday (checked on the server). Times are Asia/Dhaka, computed from the server clock.
19. An alert fires once per slot per day, in one tab, only while a tab is on screen, and up to 2 minutes late after a suspended tab wakes. A fresh page load after a slot has started does not alert for it.
20. **The visual:** a soft colour glow at the screen edge, 3 gentle pulses (well under 3 flashes per second, WCAG safe; the owner approved soft pulses over a hard blink), plus a small banner with the reason. It never blocks clicks, never dims the screen, never moves focus, and works during a POS sale. Reduced motion: no pulse, banner only.
21. The banner disappears after the alert seconds set by managers (default 8).

## Uploads (all four features)
22. A file is judged by its bytes (finfo), never by its name or the browser's claim. Tests must use real temporary files: `UploadedFile::fake()` reports a type from the NAME and cannot prove this.
23. Served with the stored type, `X-Content-Type-Options: nosniff`, `Content-Security-Policy: sandbox`, and a version in the address so a replaced file is never served stale.
