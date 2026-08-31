# Changelog

## [1.0.0] – 2026-08-31

Erstes stabiles Release. Funktionsumfang seit 0.2.0/0.3.x unverändert (WebDAV/Nextcloud/Dropbox, mehrere benannte Verbindungen pro Typ, MediaPlace-Cloud-Speicher-Integration) – der Versionssprung markiert, dass cloudconnect inzwischen produktiv sowohl MediaPlace als auch (seit dessen Version 2.0.0) das eigenständige `nextcloud`-Addon als gemeinsame Transportschicht trägt.

## [0.3.1] – 2026-08-31

### Behoben

- **Dateigröße/Änderungsdatum/MIME-Typ bei Nextcloud-Verbindungen waren immer leer bzw. 0 Byte**: `NextcloudClient::parseMultistatusResponse()` griff für `displayname`/`getcontentlength`/`getlastmodified`/`getcontenttype` auf `SimpleXMLElement`-Magic-Properties zu (`$prop->getcontentlength`), statt wie bei `resourcetype`/`fileid` bereits korrekt gemacht per namespace-bewusstem `xpath('d:getcontentlength')` – Magic Properties lösen nur im Default-Namespace des Knotens auf, WebDAV-PROPFIND-Antworten kommen aber durchgängig mit explizitem `d:`-Präfix. Je nach genauer Namespace-Deklaration der Serverantwort blieben Größe/Datum/MIME-Typ dadurch durchgängig leer (betraf sowohl MediaPlace's Cloud-Browsing als auch das `nextcloud`-Addon, das ab 2.0.0 denselben Client nutzt).

## [0.3.0] – 2026-08-31

### Neu

- `NextcloudClient` (`lib/Nextcloud/NextcloudClient.php`) um generische Transport-Bausteine erweitert, additiv und nicht-breaking: `putContent()` (WebDAV PUT), `deleteFile()` (WebDAV DELETE), `getFileTags()` (oc:tags/oc:fileid via PROPFIND) und `createShareLink()` (OCS Share API). Werden von MediaPlace selbst nicht genutzt, ermöglichen aber dem eigenständigen `nextcloud`-Addon (ab dessen Version 2.0.0), auf seinen bisherigen eigenen WebDAV-/OCS-Unterbau zu verzichten und stattdessen als reiner Client dieser Klasse zu arbeiten – siehe dessen CHANGELOG für den Umbau.

## [0.2.0] – 2026-08-31

### Neu

- **Mehrere Verbindungen pro Quelltyp**: WebDAV/Nextcloud/Dropbox unterstützen jetzt beliebig viele, einzeln benannte Verbindungen gleichzeitig (z. B. zwei Nextcloud-Server) statt nur einer festen Verbindung pro Typ. Jede aktive Verbindung erscheint als eigener, mit ihrer Bezeichnung beschrifteter Baum in der MediaPlace-Sidebar.
- Einstellungsseite komplett neu als Verbindungsliste + Assistent: **CloudConnect → Einstellungen** zeigt jetzt alle Verbindungen mit Typ/Bezeichnung/Status, „+ Neue Verbindung“ je Typ, Bearbeiten/Aktivieren-Deaktivieren/Löschen sowie Verbindung-testen (WebDAV/Nextcloud) bzw. Verbinden/Trennen (Dropbox) pro Zeile.
- Neue zentrale `lib/ConnectionStore.php` (ersetzt `OAuthTokenStore`) für alle Verbindungsdaten aller Typen.
- Automatische, einmalige Migration bestehender Einzel-Verbindungen aus 0.1.0 nach dem Update (`install.php`) – bestehende WebDAV-/Nextcloud-/Dropbox-Zugangsdaten und -Token bleiben erhalten, keine Neueinrichtung nötig.

### Geändert

- Rollenrecht bleibt bewusst pro **Quelltyp**, nicht pro einzelner Verbindung (vermeidet Rollenverwaltungs-Chaos bei jeder neuen/gelöschten Verbindung) – eine Rolle mit z. B. `cloudconnect[nextcloud_browse]` sieht automatisch alle aktiven Nextcloud-Verbindungen.
- Dropbox-Feldbezeichnungen zeigen jetzt „App-Key“/„App-Secret“ statt der generischen OAuth-Begriffe „Client-ID“/„Client-Secret“, passend zur Terminologie der Dropbox App Console.
- `vendor/` schlanker: `psr/http-message` und `symfony/deprecation-contracts` (transitive Abhängigkeiten von `league/oauth2-client`/`guzzlehttp/guzzle`) werden nicht mehr dupliziert mitgeliefert, da REDAXO-Core exakt dieselben Versionen bereits selbst mitbringt (`composer.json`-`provide`-Eintrag statt eigener Installation) – siehe [DEV.md](DEV.md).
- README.md ist jetzt die englische Hauptversion, deutsche Inhalte sind nach [README.de.md](README.de.md) umgezogen (gleiches Muster wie andere FriendsOfREDAXO-Addons in diesem Projekt).
- Neue Unterseite **CloudConnect → Hilfe** zeigt README.md direkt im Backend (gleiches Muster wie beim `phpmailer`-Addon).

### Behoben

- Seitentitel der Einstellungsseite zeigte `[translate:settings]` statt „Einstellungen“/„Settings“, da der Sprachschlüssel `settings` in keiner Sprachdatei definiert war.

### Entfernt

- **OneDrive-Unterstützung** wieder entfernt (Scope-Entscheidung) – CloudConnect verbindet MediaPlace jetzt mit WebDAV, Nextcloud und Dropbox.

## [0.1.0] – 2026-08-31

### Neu

- Erstveröffentlichung: verbindet [MediaPlace](https://github.com/FriendsOfREDAXO/mediaplace) mit **WebDAV** (generisch, Basic Auth, beliebiger Server), **Nextcloud** (eigener, spezialisierter Provider) und **Dropbox** (OAuth2, Dropbox API v2). Jede Quelle klinkt sich über den Erweiterungspunkt `MEDIAPLACE_STORAGE_PROVIDERS` als eigener, durchsuchbarer Baum in die MediaPlace-Sidebar ein – rein lesendes Browsen + Import einzelner Dateien in den lokalen Medienpool, kein Sync.
- Nextcloud und Dropbox unterstützen Server-Suche und echte Vorschaubilder (Nextcloud über dessen eigene Preview-API), generisches WebDAV liefert Browsen + Import ohne Suche/Thumbnails (protokollbedingt).
- Der Nextcloud-Provider deckt die MediaPlace-Integration des eigenständigen [`nextcloud`](https://github.com/FriendsOfREDAXO/nextcloud)-Addons ab und macht dieses für reine MediaPlace-Nutzung überflüssig – dessen eigenständige Verwaltungsseite (Upload zu Nextcloud, Löschen, ZIP-Download, Share-Links) ist bewusst nicht mit portiert, siehe README.
- Jede Quelle bringt ihr eigenes, granulares Rollenrecht mit (`cloudconnect[webdav_browse]`, `cloudconnect[nextcloud_browse]`, `cloudconnect[dropbox_browse]`) – kein globaler Schalter.
- Einstellungsseite für Zugangsdaten (WebDAV/Nextcloud: Server-URL/Benutzername/Passwort/Wurzel-Pfad/SSL-Verify; Dropbox: Client-ID/Secret + angezeigte Redirect-URI + Verbinden/Trennen-Button mit Verbindungsstatus).

### Behoben

- OAuth-`redirectUri()` lieferte nur eine seiten-relative URL (ungültig als OAuth-`redirect_uri`, führte bei Dropbox zu „You must provide a proper URI with an authority or path component“) – jetzt eine echte absolute URL über `rex::getServer()`.
- `Api\OAuthCallback` verließ sich auf `rex::getUser()`, der im (bewusst genutzten) Frontend-Kontext immer `null` ist – jede Verbindung scheiterte dadurch mit 403, bevor `code`/`state` geprüft wurden. Absicherung läuft jetzt ausschließlich über den sessiongebundenen `state`-Parameter (Standard-OAuth2-CSRF-Schutz).
- „Mit Dropbox verbinden“-Link war durch doppeltes HTML-Escaping kaputt (`&amp;amp;` statt `&amp;` im href) – Klick tat scheinbar nichts.
- Dropbox: `fetchAccountInfo()` schlug mit HTTP 400 fehl (`json_encode([])` sendet ein JSON-Array statt `null`) – betrifft alle parameterlosen Dropbox-Endpunkte.
- Dropbox: fehlender Scope `account_info.read` für `fetchAccountInfo()`.

### Bekannte Einschränkung

- **FTPS** ist bewusst noch nicht enthalten (siehe [DEV.md](DEV.md)) – folgt als eigener Nachtrag, sobald die benötigte PHP-`ftp`-Extension in einer testbaren Umgebung verfügbar ist.
