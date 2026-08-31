<?php

namespace FriendsOfRedaxo\CloudConnect;

use FriendsOfRedaxo\CloudConnect\Api\OAuthCallback;
use FriendsOfRedaxo\CloudConnect\OAuth\DropboxProvider;
use FriendsOfRedaxo\CloudConnect\Nextcloud\NextcloudProvider;
use FriendsOfRedaxo\CloudConnect\Webdav\WebdavProvider;

// Eigene, granulare Rechte je Provider statt eines globalen Schalters --
// gleiches Muster wie nextcloud[mediaplace_browse]: ein Nutzer kann so
// gezielt "WebDAV browsen" bekommen, ohne automatisch auch Dropbox zu
// duerfen. WICHTIG (siehe nextcloud-Bugfix 1.7.1): nur rex_perm::register()
// traegt ein Recht tatsaechlich in die Rollenverwaltung ein, ein
// "permissions:"-Block in package.yml wird von REDAXO-Core NICHT verarbeitet.
\rex_perm::register('cloudconnect[webdav_browse]', 'In MediaPlace per WebDAV browsen & importieren');
\rex_perm::register('cloudconnect[nextcloud_browse]', 'In MediaPlace in Nextcloud browsen & importieren');
\rex_perm::register('cloudconnect[dropbox_browse]', 'In MediaPlace in Dropbox browsen & importieren');

\rex_api_function::register('cloudconnect_oauth_callback', OAuthCallback::class);

// MediaPlace-Cloud-Provider-Integration (siehe StorageProviderInterface) --
// klinkt jede AKTIVE Verbindung (siehe ConnectionStore, mehrere pro Typ
// moeglich) als eigenen Baum in die MediaPlace-Sidebar ein (Browsen, Import
// in den lokalen Medienpool, kein Sync). Eine Verbindung registriert sich
// nur, wenn sie tatsaechlich benutzbar ist (WebDAV/Nextcloud: Zugangsdaten
// vollstaendig, Dropbox: zusaetzlich OAuth-Verbindung hergestellt)
// -- sonst erschiene ein leerer/kaputter Baum in der Sidebar, bevor die
// Einrichtung abgeschlossen ist (gleiche Vorsicht wie z.B. mediaplace's
// eigene ffmpeg/cropper-Verfuegbarkeitspruefungen). Rechte bleiben pro TYP
// (nicht pro Verbindung, siehe DEV.md) -- eine neue/geloeschte Verbindung
// erfordert deshalb keine Aenderung in der Rollenverwaltung.
if (\rex_addon::get('mediaplace')->isAvailable()) {
    \rex_extension::register('MEDIAPLACE_STORAGE_PROVIDERS', static function (\rex_extension_point $ep) {
        $providers = $ep->getSubject();

        $typeMeta = [
            'webdav' => ['icon' => 'fa-solid fa-server', 'perm' => 'cloudconnect[webdav_browse]', 'class' => WebdavProvider::class],
            'nextcloud' => ['icon' => 'fa-solid fa-cloud', 'perm' => 'cloudconnect[nextcloud_browse]', 'class' => NextcloudProvider::class],
            'dropbox' => ['icon' => 'fa-brands fa-dropbox', 'perm' => 'cloudconnect[dropbox_browse]', 'class' => DropboxProvider::class],
        ];

        foreach ($typeMeta as $type => $meta) {
            foreach (ConnectionStore::getActiveByType($type) as $id => $connection) {
                if (!ConnectionStore::isComplete($connection)) {
                    continue;
                }
                if ('dropbox' === $type && !ConnectionStore::isConnected($connection)) {
                    continue;
                }

                $providers['cloudconnect_' . $type . '_' . $id] = [
                    'label' => '' !== $connection['label'] ? $connection['label'] : \rex_i18n::msg('cloudconnect_provider_label_' . $type),
                    'icon' => $meta['icon'],
                    'perm' => $meta['perm'],
                    'class' => $meta['class'],
                    'color' => (string) ($connection['color'] ?? ''),
                ];
            }
        }

        return $providers;
    });
}
