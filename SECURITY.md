# Security

## Reporting a problem

Please report security problems privately, by a confidential issue on the GitLab project, rather
than in a public issue.

## What's protected, and from whom

**Topics (the group chat) are not end-to-end encrypted.** They travel over HTTPS and are stored
in your database like any forum's posts. Whoever runs the server can read them, as with Telegram's
own groups.

**Direct messages are end-to-end encrypted.** Only the two people in a conversation can read them.
The server stores and relays sealed data it can't open. That includes:

- the text, formatting and quotes
- photos and files, including their names and types
- push notification previews (the notification says "New message" and the phone opens it)

Nobody else can read them: not the site's owner, not the host, and not someone with a copy of the
database.

### How it works

It's all Web Crypto in the browser (`web/assets/e2e.js`); the server never sees a key.

- Each person has an **ECDH P-256 key pair**. The private key is stored on the server only in two
  locked copies:
  - one locked with their **password** (PBKDF2-SHA-256, 600,000 rounds, AES-256-GCM)
  - one locked with a **recovery code** shown once, when they set up (160 random bits, as 8 groups
    of 4 characters; PBKDF2, 100,000 rounds)
- On each device, the unlocked key is kept in the browser's storage for the site (IndexedDB), so
  anyone with access to that browser profile can use it while signed in. Logging out deletes it
  (along with unsent drafts and that device's notifications).
- Each conversation has its own random **AES-256-GCM key**, locked separately for each member with
  an ephemeral ECDH exchange, HKDF-SHA-256 and AES-GCM.
- A sealed message is `e2e1:` plus base64(IV + ciphertext) of a small JSON object. Files are sealed
  the same way before upload, and photos are resized in the browser first.

### Lost passwords

A password reset alone can't open old messages. Any one of these can:

- the **recovery code**
- the other person in a conversation, who gets a one-tap prompt to **restore access** (their device
  re-locks the conversation key for your new key)
- signing in on a device that still has your key

After a reset, "Start fresh" makes new keys. Old conversations stay locked until one of the above
happens.

### Limits: what this does not protect

- **The code comes from the server.** As with every web app that encrypts in the browser, a server
  operator (or an attacker controlling the server) could serve altered JavaScript that leaks keys.
  Encryption protects stored data and a passive or curious operator, not a malicious one.
- **Metadata is visible:**
  - who messages whom, and when
  - message sizes
  - reactions, pins, read receipts and blocks
- **Search inside DMs happens on the device.** Server search skips DMs, and DMs get no link
  previews, which would leak the links.
- **Forwarding** a DM into a topic is a deliberate decision to publish it: the forwarded copy is
  plain.

## Other measures

- **Passwords:** PHP's `password_hash`. Sign-in attempts are limited per address and per account.
  Behind Cloudflare, its visitor-address header is believed only from Cloudflare's own addresses.
  Sign-ins, claims and refusals are logged for admins. Changing a password, or resetting it with a
  link, signs the account out everywhere else.
- **Sessions:** 90-day sessions in a private folder, with `HttpOnly`, `Secure` and `SameSite=Lax`
  cookies. Every change needs a CSRF token.
- **Headers:** a strict Content Security Policy (no inline scripts or styles, with scripts only from
  the site itself and Telegram's login widget), `X-Frame-Options: DENY` and `nosniff`.
- **Files:** uploads live outside the web folder and are served through a permission check. A DM's
  files are served only to its two members.
- **Link previews:** fetched by the server with guards against server-side request forgery. It only
  contacts public addresses on ports 80 and 443, pins the DNS answer, and re-checks each redirect.
- **"Log in with Telegram":** signatures are checked against the bot token; each sign-in link works
  once, within 10 minutes. It's refused entirely when no token is set.
- **Push notifications** are only ever sent to the browsers' own push services.
- **Uploaded files** that aren't shown inline are always served as plain downloads, never under their
  own type.
