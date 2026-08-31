# CloudConnect

**Connects MediaPlace to WebDAV, Nextcloud and Dropbox**

![REDAXO](https://img.shields.io/badge/REDAXO-%3E%3D5.18-red) ![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-blue) ![MediaPlace](https://img.shields.io/badge/MediaPlace-%3E%3D1.21-green)

Hooks into [MediaPlace](https://github.com/FriendsOfREDAXO/mediaplace)'s cloud storage extension point (`StorageProviderInterface`) in its sidebar – each connected source appears there as its own, searchable tree. Read-only browsing + importing individual files into the local media pool, no sync: once imported, a file is a perfectly normal local media file.

## Features

- **WebDAV** – any generic WebDAV server (Basic Auth).
- **Nextcloud** – a dedicated, Nextcloud-tailored provider: browsing, server-side search, and real thumbnails via Nextcloud's own preview API (more than plain WebDAV offers).
- **Dropbox** – browsing, server-side search, thumbnails, import.

Every source brings its **own, granular permission** (`cloudconnect[webdav_browse]`/`[nextcloud_browse]`/`[dropbox_browse]`) – no global switch, a role can be granted access to individual sources selectively.

**Multiple connections per source type at once** – e.g. two different Nextcloud servers or several WebDAV accounts in parallel, each individually named (**CloudConnect → Settings**: create connection → choose type → label + credentials → active/inactive). Every active connection appears as its own tree, labeled with its name, in the MediaPlace sidebar. The permission still applies per **source type**, not per individual connection – a role with `cloudconnect[nextcloud_browse]` automatically sees all active Nextcloud connections.

> **FTPS** is deliberately not included (yet) – see [DEV.md](DEV.md) for the background.

### Replacing the standalone `nextcloud` addon

The Nextcloud provider included here fully covers the **MediaPlace integration** of the older, standalone [`nextcloud`](https://github.com/FriendsOfREDAXO/nextcloud) addon (browsing, search, thumbnails, import) – for pure MediaPlace usage, the `nextcloud` addon is no longer needed; the two cannot browse the same Nextcloud instance in parallel (different configuration locations). Not ported over are the features of the `nextcloud` addon's **standalone management page**, which runs outside of MediaPlace: **uploading from the media pool to Nextcloud**, **deleting**, **ZIP download**, and public **share links** (including the `REX_NEXTCLOUD_SHARE` module variable). Anyone actively using one of these extra features should keep running the `nextcloud` addon for now.

## Installation

1. Install and activate the addon. Requires: [MediaPlace](https://github.com/FriendsOfREDAXO/mediaplace) ≥ 1.21 is installed.
2. Under **CloudConnect → Settings**, set up the sources you want (see below).
3. Under **Users → Roles**, assign the relevant `cloudconnect[...]` permissions to the appropriate roles.

### Setting up WebDAV

For **Nextcloud servers** there's a dedicated, more capable section below ("Setting up Nextcloud", with search + real thumbnails) – this generic WebDAV section is meant for all **other** WebDAV providers (ownCloud, hosting-provider-supplied WebDAV, etc.), though it technically also works with Nextcloud, just without its extra features.

WebDAV isn't a single service but a protocol that many providers offer under their own URL – there's no separate "create an app" step like with Dropbox here, an existing account with the respective provider is enough. Credentials and the WebDAV URL can be found in the customer portal/control panel of the respective provider (e.g. Strato HiDrive, IONOS, Hetzner Storage Box, many classic web hosting packages), usually under "access methods", "FTP/WebDAV access" or similar – if in doubt, search there directly for "WebDAV" or ask support.

**Entering it in CloudConnect:**

1. Under **CloudConnect → Settings**, click **"+ New connection: WebDAV"** at the top.
2. Give it a **label** (freely chosen, appears as the tree's name in MediaPlace – useful when running multiple WebDAV connections, e.g. "Client A – Photos"), then enter the server URL, username and password (or app password).
3. Optionally set a **root path**, if only a specific subfolder should appear as the cloud tree in MediaPlace (leave empty for the entire WebDAV server).
4. Only disable "Verify SSL certificate" if the server uses a self-signed certificate and is trusted (e.g. your own internal test server) – otherwise leave it enabled.
5. Save. Back in the list, use **"Test connection"** to check whether the credentials work. Additional WebDAV connections are possible at any time via the same "+ New connection" link.

### Setting up Nextcloud

A dedicated provider instead of the generic WebDAV section above, because Nextcloud offers two extras that plain WebDAV doesn't: server-side full-text search and its own preview API for real thumbnails in MediaPlace (instead of just file icons).

**Generating credentials:** in the Nextcloud web interface, click the avatar in the top right → **Settings** → **Security** → "App passwords" section → give it a name (e.g. "CloudConnect") → click **Create new app password**. **Copy the displayed password immediately** (it won't be shown again) and use it instead of the real account password – this way, access via CloudConnect stays separately revocable without changing the actual password.

**Entering it in CloudConnect:**

1. Under **CloudConnect → Settings**, click **"+ New connection: Nextcloud"** at the top.
2. Give it a **label** (e.g. "Company A", if connecting multiple Nextcloud servers), then enter the server URL **without** `/remote.php/dav` (i.e. e.g. `https://cloud.example.com`, not the WebDAV endpoint URL like with generic WebDAV above), username, and the app password you just generated.
3. Optionally set a **root path** (as with WebDAV).
4. Save. Back in the list, use **"Test connection"** to check whether the credentials work. For additional Nextcloud servers, use the same "+ New connection" link again – each connection appears as its own tree, labeled with its name, in MediaPlace.

### Setting up Dropbox

Dropbox access runs through a self-created "app" in the Dropbox developer console – this isn't a separate product, just a pair of access keys for your own (or any) Dropbox account. A normal, free Dropbox account is enough.

> ⚠️ **Most important decision, read this before you start:** When creating the app, the **Access type** must be set to **"Full Dropbox"** – **not** "App folder". This choice **cannot be changed** after the app has been created (Dropbox provides no setting for that). With "App folder", the app only sees its own, empty subfolder newly created by Dropbox (`Apps/<App name>/`) – never the actual files in the Dropbox account. The result: CloudConnect appears to connect successfully, but the MediaPlace tree permanently shows **0 files**, even though the Dropbox account clearly contains files – with no error message at all, since everything is working correctly from Dropbox's point of view (the app gets exactly what it's entitled to: its own empty folder). If the app was accidentally created with "App folder", the only fix is a **new** app with "Full Dropbox" plus new credentials in CloudConnect – an existing app can't be switched over afterwards.

1. Log in to [dropbox.com](https://www.dropbox.com) with the Dropbox account whose files you want to search (create an account first if needed, it's free).
2. Open the [Dropbox App Console](https://www.dropbox.com/developers/apps) → **Create app**.
3. **1. Choose an API**: select **"Scoped access"** (not "Legacy").
4. **2. Choose the type of access you need**: select **"Full Dropbox"** – see the warning above, "App folder" is the wrong choice for this use case.
5. **3. Name your app**: give it any name, unique across all of Dropbox (e.g. "CloudConnect-<project name>") → accept the terms of service → **Create app**.
6. In the newly created app dashboard, open the **Permissions** tab at the top (only appears after the app has been created, not already in the create dialog). Under "Files and folders", check `files.metadata.read`, `files.content.read` **and**, under "Account info", `account_info.read` (the latter for the "Connected as …" display in CloudConnect). Click **Submit** at the very bottom of the page – without this click, the checkboxes won't be saved.
7. Switch to the **Settings** tab: at the top you'll find "App key" and "App secret", the two access credentials (the secret only becomes visible via the **"Show"** link). Right below it, Dropbox shows the chosen **"Access type"** again (**"Full Dropbox"** should be shown here – a good place to double-check the mistake described above before continuing).
8. In CloudConnect, under **CloudConnect → Settings**, click **"+ New connection: Dropbox"**, give it a **label** (e.g. the account name if you have multiple Dropbox accounts), enter App key → field **App key (client ID)**, App secret → field **App secret (client secret)**, **save**.
9. Now reopen the connection via **"Edit"** – the **Redirect URI** now appears there (only after the first save). Copy it and paste it into the Dropbox app dashboard, tab **Settings**, section **"OAuth 2"**, field **"Redirect URIs"** → **Add**.
10. Back in the CloudConnect connection list, click **"Connect"** on this connection, and allow access in the Dropbox login/confirmation dialog. Right afterwards, the list should show "Connected as &lt;your name&gt;" and the Dropbox tree in MediaPlace should show real files. For additional Dropbox accounts, repeat the same process with a new connection (potentially using a second Dropbox app).

**Added a scope or corrected the access type afterwards?** An existing connection's token keeps the permissions from the time it was last connected – after any change to the app's permissions in the Dropbox console, first click **"Disconnect"** on this connection, then **"Connect"** again, otherwise the new permissions won't take effect.

## For developers

See [DEV.md](DEV.md) for the architecture, the `StorageProviderInterface` pattern, and notes on further providers (e.g. the planned FTPS addendum).

## Credits

Developed by [FriendsOfREDAXO](https://github.com/FriendsOfREDAXO). Uses [league/oauth2-client](https://github.com/thephpleague/oauth2-client) for the OAuth2 connection setup to Dropbox.
