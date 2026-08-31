---
name: cloudconnect
description: CloudConnect addon development skill. Use when working on FriendsOfREDAXO cloudconnect (WebDAV/Nextcloud/Dropbox providers for MediaPlace), especially the multi-connection architecture, OAuth2 flow, ConnectionStore, boot.php provider registration, settings.php, or docs/changelog/README synchronization.
---

# CloudConnect Development Skill

Du arbeitest im REDAXO-Addon `cloudconnect` (FriendsOfREDAXO). Es verbindet [MediaPlace](https://github.com/FriendsOfREDAXO/mediaplace) mit externen Cloud-Speichern -- WebDAV (generisch), Nextcloud (spezialisiert) und Dropbox (OAuth2) -- über MediaPlace's `StorageProviderInterface`/`MEDIAPLACE_STORAGE_PROVIDERS`-Erweiterungspunkt. Rein lesendes Browsen + Import in den lokalen Medienpool, kein Sync.

## Architektur in Kürze

Drei `StorageProviderInterface`-Implementierungen (WebDAV, Nextcloud, Dropbox), jede unterstützt **mehrere gleichzeitige, einzeln benannte Verbindungen** (siehe [DEV.md](../../../DEV.md) "Mehrere Verbindungen pro Quelltyp" für die vollständige Herleitung). Zentrale Punkte:

- **`lib/ConnectionStore.php`** -- einzige Quelle der Wahrheit für alle Verbindungen aller Typen, ein `rex_config`-Schlüssel `connections` (Array `id => ['type','label','active','config'=>[...]]`).
- **Ein Client pro Typ** (`WebdavClient`, `NextcloudClient`, `DropboxClient`), **keine** Klasse pro Verbindung -- MediaPlace instanziert Provider-Klassen zero-arg (`StorageProviderRegistry::getInstance()` → `new $class()`), deshalb lösen die Client-Konstruktoren ihre Connection-ID selbst aus `rex_request('provider')` auf, wenn keine explizit übergeben wird (Provider-Registry-Key ist `cloudconnect_<type>_<connId>`).
- **`boot.php`** registriert dynamisch einen `MEDIAPLACE_STORAGE_PROVIDERS`-Eintrag pro *aktiver* Verbindung (nicht pro Typ) -- Rechte (`rex_perm::register()`) bleiben aber pro Typ, nicht pro Verbindung.
- **`pages/settings.php`** ist ein reiner GET/POST-Assistent (Listenansicht + Formularansicht über Query-Parameter), kein JS-Framework.

## Wo du was findest

- `lib/ConnectionStore.php` -- CRUD für Verbindungen (`getAll()`, `create()`, `update()`, `updateConfig()`, `delete()`, `isComplete()`, `isConnected()`).
- `lib/Webdav/WebdavClient.php`, `lib/Nextcloud/NextcloudClient.php` -- PROPFIND/GET per curl, Basic Auth. Nextcloud zusätzlich: `oc:fileid`, SEARCH-Verb, eigene Preview-API.
- `lib/OAuth/AbstractOAuthClient.php` -- gemeinsame `league/oauth2-client`-Mechanik (Authorization-Code-Flow, Token-Refresh), generisch für künftige weitere OAuth-Provider gehalten, aktuell nur von `DropboxClient` genutzt.
- `lib/Api/OAuthCallback.php` -- OAuth-Redirect-Endpunkt (`?provider=dropbox`), `$published=true` (Frontend-Einstiegspunkt).
- `boot.php` -- Rechte-Registrierung + dynamischer `MEDIAPLACE_STORAGE_PROVIDERS`-Loop über `ConnectionStore::getActiveByType()`.
- `install.php` -- läuft bei jedem Install/Update (kein separates `update.php`, siehe Stolperfalle unten), enthält die einmalige Migration von der alten Single-Connection-Konfiguration.
- `DEV.md` -- ausführliche Architektur-Begründungen und alle bisher gefundenen Stolperfallen, vor größeren Änderungen lesen.

## Kritische Stolperfallen (siehe DEV.md für Details)

1. **`rex_url` liefert nur seiten-relative URLs**, niemals domain-absolut -- für die OAuth-`redirectUri()` stattdessen `rtrim(rex::getServer(),'/') . '/index.php?' . http_build_query([...],'','&')`. Separator explizit `'&'` übergeben (PHP-ini `arg_separator.output` kann `&amp;` sein).
2. **`rex::getUser()` ist im Frontend-Kontext (`/index.php`) immer `null`** -- `Api\OAuthCallback` darf sich NICHT darauf verlassen, Absicherung läuft über den sessiongebundenen `state`-Parameter.
3. **Niemals eine bereits von `rex_url` escapte URL nochmal durch `rex_escape()` schicken** -- erzeugt `&amp;amp;`, bricht die Ziel-URL.
4. **Dropbox-Scoped-Apps brauchen `account_info.read`** zusätzlich zu `files.metadata.read`/`files.content.read` für `fetchAccountInfo()`. Nach jeder Scope-Änderung in der Dropbox-Konsole: Verbindung trennen + neu verbinden, sonst greift der alte Token weiter.
5. **`json_encode([])` liefert `"[]"`**, nicht `null` -- Dropbox-Endpunkte ohne Parameter brauchen `null` als Body.
6. **Dropbox "App folder" vs. "Full Dropbox"** ist bei Erstellung endgültig -- "App folder" liefert `list_folder("")` mit `entries: []` OHNE Fehler, obwohl das Konto echte Dateien hat. Einzige Lösung: neue App mit "Full Dropbox".
7. **Keine Composer-Vendor-Duplikate zu REDAXO-Core**: vor jedem `composer update` prüfen, ob eine (transitive) Abhängigkeit bereits identisch in `src/core/vendor/` liegt (`composer/installed.json` vergleichen) -- falls ja, über `composer.json`s `provide`-Sektion lösen statt zu duplizieren (aktuell: `psr/http-message`, `symfony/deprecation-contracts`).
8. **`update.php` wird von diesem REDAXO-Core NICHT aufgerufen** (Konstante existiert, wird aber nirgends ausgewertet) -- Migrationen gehören in `install.php`, idempotent geschrieben (läuft bei jedem Install/Update).
9. **Provider-Klassen brauchen einen zero-arg-fähigen Konstruktor** (`?string $connectionId = null`) wegen `StorageProviderRegistry::getInstance()` -- niemals einen Pflichtparameter einführen.

## Arbeitsweise

1. Vor PHP-Änderungen: `php -l <file>`.
2. Nach lang-Änderungen: `diff <(grep -oE "^cloudconnect_[a-z0-9_]+" lang/de_de.lang | sort -u) <(grep -oE "^cloudconnect_[a-z0-9_]+" lang/en_gb.lang | sort -u)` -- muss leer sein. Auch prüfen, dass jeder definierte Key tatsächlich referenziert wird (keine Leichen).
3. Nach `composer.json`-Änderungen: `composer validate`, und Stolperfalle 7 gegenprüfen.
4. Live-Verifikation bevorzugen: `docker exec -w /var/www/html fairplayweb php bin/console cache:clear`, danach `var/log/system.log` auf neue Einträge prüfen. Für tiefere Prüfungen (Provider-Registrierung, Connection-Resolution) ein temporäres Skript unter `bin/_tmp_*.php` im Container anlegen, das REDAXO wie `bin/console` bootstrapped (`rex_addon::initialize()` + `enlist()`/`boot()` je Package), danach wieder löschen.
5. **Bei Tests, die `rex_request('provider')` brauchen**: `$_REQUEST[...]` direkt setzen, nicht nur `$_GET` -- PHP befüllt `$_REQUEST` einmalig aus der echten Query, ein nachträgliches `$_GET[...]=...` im Testskript wirkt sich nicht darauf aus.
6. Bei sichtbaren Änderungen: `CHANGELOG.md` (aktuelle, noch unveröffentlichte Version-Überschrift ergänzen, niemals eine bereits getaggte Version nachträglich ändern), `README.md` (Englisch, Hauptversion) UND `README.de.md` (Deutsch) synchron halten, `DEV.md` bei architekturrelevanten Änderungen/neuen Stolperfallen aktualisieren.

## Scope-Entscheidungen (nicht versehentlich rückgängig machen)

- **FTPS**: bewusst nicht enthalten, siehe DEV.md.
- **OneDrive**: war zeitweise enthalten, wurde wieder entfernt (Scope-Entscheidung) -- nicht ohne explizite Anfrage wieder hinzufügen.
- **Rechte pro Typ, nicht pro Verbindung**: bewusste Entscheidung gegen Rollenverwaltungs-Chaos bei häufig wechselnden Verbindungen.
- **Unverschlüsselte Zugangsdaten-Speicherung** (`rex_config`, Klartext): konsistent mit dem Rest des Projekts (siehe `nextcloud`-Addon), kein REDAXO-Core-Feature für verschlüsselte Config vorhanden.
