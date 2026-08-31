<?php

/**
 * install.php laeuft sowohl beim Erst-Install als auch bei jedem Update
 * (gleiche REDAXO-Konvention wie mediaplace/install.php -- ein separates
 * update.php wird von diesem REDAXO-Core NICHT automatisch aufgerufen, die
 * Konstante rex_package::FILE_UPDATE existiert zwar, wird aber nirgends
 * ausgewertet, verifiziert durch Lesen des Core-Quellcodes).
 *
 * Einmalige, idempotente Migration von der alten "eine Verbindung pro
 * Quelltyp"-Konfiguration (feste rex_config-Schluessel wie webdav_url,
 * dropbox_access_token, ...) auf das neue Mehrfach-Verbindungs-Modell
 * (siehe lib/ConnectionStore.php, DEV.md "Mehrere Verbindungen pro
 * Quelltyp"). Greift nur, wenn noch KEINE Verbindungen existieren --
 * jeder weitere Lauf (z.B. bei einem spaeteren Versions-Update) findet
 * dann nichts mehr zu migrieren und tut nichts.
 */
if ([] === \FriendsOfRedaxo\CloudConnect\ConnectionStore::getAll()) {
    $legacyWebdavUrl = (string) rex_config::get('cloudconnect', 'webdav_url', '');
    if ('' !== $legacyWebdavUrl) {
        \FriendsOfRedaxo\CloudConnect\ConnectionStore::create('webdav', 'WebDAV', true, [
            'url' => $legacyWebdavUrl,
            'username' => (string) rex_config::get('cloudconnect', 'webdav_username', ''),
            'password' => (string) rex_config::get('cloudconnect', 'webdav_password', ''),
            'root' => (string) rex_config::get('cloudconnect', 'webdav_root', ''),
            'ssl_verify' => (bool) rex_config::get('cloudconnect', 'webdav_ssl_verify', true),
        ]);
    }

    $legacyNextcloudUrl = (string) rex_config::get('cloudconnect', 'nextcloud_url', '');
    if ('' !== $legacyNextcloudUrl) {
        \FriendsOfRedaxo\CloudConnect\ConnectionStore::create('nextcloud', 'Nextcloud', true, [
            'url' => $legacyNextcloudUrl,
            'username' => (string) rex_config::get('cloudconnect', 'nextcloud_username', ''),
            'password' => (string) rex_config::get('cloudconnect', 'nextcloud_password', ''),
            'root' => (string) rex_config::get('cloudconnect', 'nextcloud_root', ''),
            'ssl_verify' => (bool) rex_config::get('cloudconnect', 'nextcloud_ssl_verify', true),
        ]);
    }

    $legacyDropboxClientId = (string) rex_config::get('cloudconnect', 'dropbox_client_id', '');
    if ('' !== $legacyDropboxClientId) {
        \FriendsOfRedaxo\CloudConnect\ConnectionStore::create('dropbox', 'Dropbox', true, [
            'client_id' => $legacyDropboxClientId,
            'client_secret' => (string) rex_config::get('cloudconnect', 'dropbox_client_secret', ''),
            'access_token' => (string) rex_config::get('cloudconnect', 'dropbox_access_token', ''),
            'refresh_token' => (string) rex_config::get('cloudconnect', 'dropbox_refresh_token', ''),
            'expires_at' => (int) rex_config::get('cloudconnect', 'dropbox_expires_at', 0),
            'account_label' => (string) rex_config::get('cloudconnect', 'dropbox_account_label', ''),
        ]);
    }

    // Alte Fixed-Keys aufraeumen -- verhindert Verwechslungsgefahr mit tot
    // herumliegenden Werten, die kein Code mehr liest.
    foreach (['webdav', 'nextcloud', 'dropbox'] as $legacyType) {
        foreach (['url', 'username', 'password', 'root', 'ssl_verify', 'client_id', 'client_secret', 'access_token', 'refresh_token', 'expires_at', 'account_label'] as $suffix) {
            rex_config::remove('cloudconnect', $legacyType . '_' . $suffix);
        }
    }
}
