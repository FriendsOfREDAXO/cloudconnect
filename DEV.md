# CloudConnect – Entwicklung

## Architektur

Drei unabhängige `StorageProviderInterface`-Implementierungen (siehe MediaPlace, `lib/StorageProviderInterface.php`), jede über den Erweiterungspunkt `MEDIAPLACE_STORAGE_PROVIDERS` registriert (`boot.php`), jede mit eigenem, granularem Recht statt eines globalen Schalters (gleiches Muster wie `nextcloud[mediaplace_browse]`). Mehrere **Verbindungen** pro Typ gleichzeitig möglich (siehe nächster Abschnitt) – Rechte bleiben aber pro Typ, nicht pro Verbindung.

```
lib/
  ConnectionStore.php  – zentrale CRUD-Stelle fuer alle Verbindungen aller Typen (siehe unten)
  Webdav/
    WebdavClient.php    – PROPFIND/GET per curl + SimpleXMLElement, HTTP Basic Auth
    WebdavProvider.php  – StorageProviderInterface-Adapter (hasSearch()=false, getThumbnail()=null)
  Nextcloud/
    NextcloudClient.php   – wie WebdavClient, aber fest auf /remote.php/dav/files/<user>/
                             verdrahtet + Nextcloud-Erweiterungen: oc:fileid (PROPFIND),
                             SEARCH-Verb (serverseitige Suche), eigene Preview-API (Thumbnails)
    NextcloudProvider.php – StorageProviderInterface-Adapter (hasSearch()=true, echte Thumbnails)
  OAuth/
    AbstractOAuthClient.php – gemeinsame league/oauth2-client-Mechanik (Authorization-Code-Flow,
                               automatischer Token-Refresh über freshAccessToken())
    DropboxClient.php / DropboxProvider.php
  Api/
    OAuthCallback.php  – gemeinsamer OAuth-Redirect-Endpunkt (?provider=dropbox)
```

Jede Verbindung registriert sich in `boot.php` **nur, wenn tatsächlich benutzbar** (WebDAV/Nextcloud: Zugangsdaten vollständig, Dropbox: zusätzlich OAuth-Verbindung hergestellt) – sonst erschiene ein leerer Baum in der MediaPlace-Sidebar.

## Mehrere Verbindungen pro Quelltyp

Ab 0.2.0 unterstützt jeder der drei Typen beliebig viele, einzeln benannte Verbindungen gleichzeitig (z. B. zwei Nextcloud-Server) – verwaltet über **`lib/ConnectionStore.php`** (ein einziger `rex_config`-Schlüssel `connections`, Array `id => ['type', 'label', 'active', 'config' => [...]]`, rex_config serialisiert Arrays selbst).

**Kernproblem, das die Architektur bestimmt hat:** MediaPlace instanziert Provider strikt über `StorageProviderRegistry::getInstance($providerId)` → `new $class()`, **ohne** Konstruktor-Argumente (verifiziert durch Lesen der MediaPlace-Quelle, `lib/StorageProviderRegistry.php`). Eine Klasse kann also nicht direkt "wissen", für welche Verbindung sie steht. Lösung **ohne** Laufzeit-Klassen-Hack: Jede Verbindung bekommt eine kurze Zufalls-ID (`bin2hex(random_bytes(4))`), der Provider-Registry-Key wird `cloudconnect_<type>_<connId>`. Es bleibt **eine** Client-Klasse pro Typ; jeder Konstruktor nimmt optional eine Connection-ID:

```php
public function __construct(?string $connectionId = null)
{
    $this->connectionId = $connectionId ?? self::resolveConnectionIdFromRequest();
}
```

- Ruft MediaPlace auf (`getInstance()`, zero-arg): die Client-Klasse liest die ID selbst aus `rex_request('provider')` – funktioniert zuverlässig, weil `Provider.php` (in MediaPlace) exakt diesen Wert im selben Request bereits gelesen hat, um `getInstance($providerId)` überhaupt aufzurufen. Vorsicht beim Testen dieses Pfads per CLI-Skript: PHP befüllt `$_REQUEST` (das `rex_request()` liest, nicht `$_GET` direkt) einmalig aus der echten Query, ein nachtraegliches `$_GET[...] = ...;` im Skript wirkt sich NICHT auf `$_REQUEST` aus – im Testskript direkt `$_REQUEST[...]` setzen.
- Ruft `pages/settings.php` auf (Verbindung testen/bearbeiten/verbinden/trennen fuer eine bestimmte Zeile): die ID wird **explizit** übergeben, z. B. `new NextcloudClient($connectionId)`.

Für OAuth-Typen kommt eine zweite Huerde dazu: die `redirect_uri` muss 1:1 bei Dropbox hinterlegt sein und bleibt deshalb **pro Typ fix** (kann keine Connection-ID enthalten). Welche Verbindung gerade autorisiert wird, transportiert stattdessen die Session: `AbstractOAuthClient::buildAuthorizationUrl()` merkt sich `connectionId` unter `cloudconnect_oauth_pending_connection_<type>` (parallel zum `state`-CSRF-Token), `Api\OAuthCallback` liest das ueber die statische Methode `AbstractOAuthClient::consumePendingConnectionId($type)` aus, **bevor** ueberhaupt eine Client-Instanz existiert (die Instanz selbst braucht die ID ja schon im Konstruktor).

**Migration von der alten Einzel-Verbindung-Konfiguration** (< 0.2.0, feste Schlüssel wie `webdav_url`, `dropbox_access_token`): `install.php`, idempotent (nur aktiv, wenn `ConnectionStore::getAll()` noch leer ist). Wichtig: dieses REDAXO-Core hat zwar eine Konstante `rex_package::FILE_UPDATE = 'update.php'`, wertet sie aber **nirgends** aus (verifiziert durch Grep im Core) – ein separates `update.php` würde nie aufgerufen. `install.php` läuft dagegen bei jedem Install **und** jedem Update (gleiche Konvention wie `mediaplace/install.php`), die Idempotenz-Prüfung übernimmt hier die Guard-Funktion, die ein `update.php` sonst gehabt hätte.

## Autoloading

Kein `composer.json`-`autoload`-Block nötig: REDAXO-Core scannt `lib/` und `vendor/` jedes aktiven Addons automatisch selbst (`rex_package::enlist()` → `rex_autoload::addDirectory()`), das ist **keine** Composer-PSR-4-Autoloadung, sondern REDAXOs eigener, regex-basierter Klassen-Scan. `vendor/league/oauth2-client` (+ transitiv `guzzlehttp/guzzle`) ist deshalb einfach mit committet (`composer install --no-dev`), kein manuelles `require vendor/autoload.php` in `boot.php` nötig.

### Keine Duplikate zu bereits von REDAXO-Core mitgebrachten Paketen

`league/oauth2-client`/`guzzlehttp/guzzle` bringen transitiv `psr/http-message` und `symfony/deprecation-contracts` mit – **beide** liegen bereits identisch (exakt gleiche Version: `psr/http-message 2.0`, `symfony/deprecation-contracts v3.7.1`, geprüft gegen `src/core/vendor/composer/installed.json`) in REDAXOs eigenem `src/core/vendor/`. Ein zweites, eigenes Exemplar in diesem Addon wäre reine Redundanz.

Grund, warum das trotzdem sicher funktioniert: `rex_autoload::register()` (`src/core/lib/autoload.php:54-58`) lädt REDAXOs eigenen Composer-`ClassLoader` einmalig, `unregister()`t ihn aber sofort wieder aus dem regulären `spl_autoload_register`-Stack und ruft `$composerLoader->loadClass($class)` stattdessen **manuell** als Fallback innerhalb von `rex_autoload::autoload()` (Zeile 120) auf – jede über REDAXOs eigenen Autoloader aufgelöste Klasse (also praktisch jede Klasse in diesem Prozess, Addon-Code eingeschlossen) kann sich damit transparent auf Core's eigene vendor-Klassen verlassen, ganz ohne einen zweiten, eigenen `require vendor/autoload.php`.

Umgesetzt über `composer.json`'s **`provide`**-Sektion (nicht `require`/`replace` – `provide` sagt Composer "das ist ohne eigene Installation bereits erfüllt", ohne selbst eine Implementierung dieser Pakete zu behaupten):

```json
"provide": {
    "psr/http-message": "2.0",
    "symfony/deprecation-contracts": "3.7.1"
}
```

Danach `composer update --no-dev -o` erneut laufen lassen – Composer entfernt beide Pakete dann selbst aus `vendor/`/`composer.lock`, die restliche Abhängigkeitsauflösung (`guzzlehttp/psr7` etc. deklarieren ihre Anforderung an `psr/http-message` weiterhin, gilt aber als erfüllt) bleibt unangetastet.

**Bei jedem `composer update`/Versions-Bump von `league/oauth2-client` erneut prüfen**, ob sich die tatsächlich benötigten Versionen von `psr/http-message`/`symfony/deprecation-contracts` geändert haben und ob REDAXO-Core's eigene Version (`src/core/composer.json`) das noch abdeckt – sonst müsste die `provide`-Version hier entsprechend nachgezogen werden oder das Paket doch wieder real vendored werden.

## OAuth2-Token-Speicherung

`rex_config` (Namespace `cloudconnect`), **unverschlüsselt** – REDAXO-Core bietet keine verschlüsselte Konfigurationsablage an, dieselbe Konvention wie beim `nextcloud`-Addon (WebDAV-Passwort dort ebenfalls Klartext in `rex_config`). Schutz ausschließlich über DB-/Server-Zugriffskontrolle.

`AbstractOAuthClient::freshAccessToken()` prüft vor jedem API-Aufruf `expires_at` (60s Puffer) und erneuert automatisch über den Refresh-Token, falls nötig – kein manuelles Eingreifen nötig, solange der Refresh-Token gültig bleibt.

## Bekannte Stolperfallen

- **Namespace + globale REDAXO-Klassen**: alle `lib/*.php`-Dateien sind namespaced (`FriendsOfRedaxo\CloudConnect\...`). REDAXO-Core-Klassen (`rex_config`, `rex_exception`, `rex_media_service`, …) leben **ohne** Namespace – jeder Aufruf braucht deshalb ein führendes `\` (z. B. `\rex_config::get(...)`), sonst sucht PHP fälschlich nach `FriendsOfRedaxo\CloudConnect\rex_config`. `pages/settings.php` ist bewusst NICHT namespaced (gleiche Konvention wie MediaPlace/nextcloud – Page-Dateien bleiben global), dort sind bare `rex_*`-Aufrufe deshalb korrekt.
- **`rex_url` liefert AUSSCHLIESSLICH seiten-relative URLs** (siehe Klassen-Docblock: "Utility class to generate relative URLs") – `backendController()`/`backendPage()`/`currentBackendPage()` sind zur Browser-Auflösung relativ zur jeweils aktuell aufgerufenen Seite gedacht, NIEMALS domain-absolut, und ihre Bedeutung kehrt sich sogar um je nachdem ob der aufrufende Code gerade im Frontend- oder Backend-Einstiegspunkt läuft (`rex_path_default_provider::backend()`/`base()`, abhängig von `$REX['HTDOCS_PATH']`). Für die OAuth-`redirectUri()` (muss identisch sein: einmal beim Erzeugen der Autorisierungs-URL, einmal beim späteren Token-Tausch im Callback – UND exakt so bei Dropbox hinterlegt) ist das unbrauchbar; stattdessen `rtrim(\rex::getServer(), '/') . '/index.php?' . http_build_query([...], '', '&')` (siehe `AbstractOAuthClient::redirectUri()`, gleiches Muster wie `issue_tracker/lib/NotificationService.php` für E-Mail-Links). **Zweite Falle dabei**: `http_build_query()` ohne den dritten Parameter fällt auf die PHP-ini-Einstellung `arg_separator.output` zurück – auf diesem Server steht die tatsächlich auf `&amp;` (Legacy-XHTML-Konvention), nicht auf `&`. Explizit `'&'` übergeben, sonst enthält die redirect_uri wörtlich `&amp;` statt `&` und der State-Parameter geht auf dem Weg zu Dropbox verloren.
- **`rex::getUser()` ist im Frontend-Kontext (`/index.php`) IMMER `null`** – `rex_backend_login` (die einzige Stelle, die ihn befüllt) läuft ausschließlich in `backend.php`, niemals in `frontend.php`. Da `AbstractOAuthClient::redirectUri()` bewusst auf den Frontend-Einstiegspunkt zeigt (s.o.), darf `Api\OAuthCallback::execute()` sich **nicht** auf `rex::getUser()->isAdmin()` verlassen (führte real zu einem harten 403, bevor `code`/`state` überhaupt geprüft wurden – der OAuth-Flow konnte so nie erfolgreich abschließen). Die eigentliche Absicherung läuft über den `state`-Parameter (sessiongebunden, `hash_equals()`, Standard-OAuth2-CSRF-Schutz) – das reicht aus, weil nur ein Browser mit der Session eines zuvor eingeloggten Admins (Settings-Seite ist `perm: admin`) den passenden State kennt. Funktioniert hier nur, weil `session.cookie_path=/` und kein individueller `session.name` gesetzt sind (sonst Session nicht zwischen Frontend-/Backend-Einstiegspunkt geteilt) – vor einer Auslieferung an andere Installationen ggf. gegenprüfen.
- **Niemals eine bereits von `rex_url` escapte URL nochmal durch `rex_escape()` schicken** – `rex_url::currentBackendPage(...)` escapt standardmäßig selbst (`$escape=true`, `&`→`&amp;`), fürs direkte Einbetten in ein `href`-Attribut korrekt. Ein zusätzliches äußeres `rex_escape(...)` erzeugte real `&amp;amp;` im HTML; der Browser dekodiert HTML-Entities nur EINMAL, die tatsächliche Navigations-URL enthielt dadurch einen wörtlichen `&amp;`-String statt eines Parameter-Trenners – der zweite Query-Parameter (`cloudconnect_connect=dropbox`) kam serverseitig nie an, der Verbinden-Button tat scheinbar nichts.
- **`GenericProvider`'s Scope-Separator** ist standardmäßig Komma – Dropbox erwartet Leerzeichen-getrennte Scopes (RFC-6749-Standard), deshalb `'scopeSeparator' => ' '` in `AbstractOAuthClient::makeProvider()`.
- **Dropbox-Scoped-Apps brauchen `account_info.read`** zusätzlich zu `files.metadata.read`/`files.content.read`, sobald `fetchAccountInfo()` (`/users/get_current_account`, für die "Verbunden als …"-Anzeige) aufgerufen wird – sonst HTTP 401 `missing_scope`. Scopes müssen zusätzlich in der Dropbox-App-Konsole (Reiter Permissions) angehakt UND per **Submit** gespeichert werden; ein bereits ausgestellter Token behält die ALTEN Berechtigungen, bis explizit über "Verbindung trennen" + erneut "Verbinden" ein neuer Token geholt wird.
- **`json_encode([])` liefert `"[]"` (JSON-Array), nicht `"{}"` oder `null`** – Dropbox-Endpunkte ohne Parameter (z. B. `/users/get_current_account`) akzeptieren nur `null`/ein JSON-Objekt als Body und antworten sonst mit HTTP 400 `"expected string or object, got array"`. `DropboxClient::apiCall()` kodiert deshalb `null` statt `[]`, wenn `$payload` leer ist.
- **Dropbox „App folder“ vs. „Full Dropbox“ ist bei App-Erstellung endgültig und nicht mehr änderbar** – mit „App folder“ sieht die App ausschließlich einen eigenen, leeren `Apps/<AppName>/`-Unterordner, niemals die echten Kontodateien. Das äußert sich NICHT als Fehler: Auth/Scopes/Token-Tausch laufen einwandfrei durch, `list_folder("")` liefert einfach HTTP 200 mit `entries: []`, obwohl das Konto (verifizierbar über `users/get_space_usage`) echte Dateien enthält. Live diagnostiziert über `files/list_folder` auf mehrere Pfade außerhalb einer vermuteten Sandbox (`/Apps`, …) – alle `path/not_found`, kombiniert mit belegtem Speicherplatz laut `get_space_usage` UND vom Nutzer bestätigten sichtbaren Dateien auf dropbox.com. Einzige Lösung: neue App mit „Full Dropbox“ anlegen (siehe README, dort mit Warnhinweis vor dem Erstellen dokumentiert).
- **Dropbox-Pfade**: die Wurzel ist ein **leerer String** (`""`), nicht `"/"` – `list_folder`/`search_v2` mit `path: "/"` liefern einen Fehler.
- **MediaPlace selbst**: `updateStatus(count)` (mediaplace `core.js`) hängt bei aktivem lokalen Zustand (`mediaTotal>0`) automatisch ein "`| X von Y geladen`"-Suffix an – dieser Zustand ist reines lokales-Grid-Modul-Scope-Wissen und wird beim Wechsel in den Provider-Modus NICHT automatisch zurückgesetzt. `modules/providers.js::openProvider()` muss deshalb `ctx.resetLoadedState()` aufrufen (neu ergänzter Ctx-Eintrag in `core.js`, setzt `mediaTotal=0`/`lastLoadedFiles=[]`), sonst zeigt die Statuszeile nach einem Wechsel von der lokalen Ansicht in einen Cloud-Provider dauerhaft die alten lokalen Zahlen an – unabhängig davon, was der Provider tatsächlich liefert.
- **NextcloudClient vs. WebdavClient sind bewusst getrennte Implementierungen**, kein gemeinsamer Basistyp: Nextclouds Datei-Endpunkt ist fest `/remote.php/dav/files/<username>/...` (aus Benutzername + konfigurierter Basis-URL zusammengesetzt), während generisches WebDAV die vom Nutzer eingetragene URL bereits als kompletten Endpunkt behandelt – eine gemeinsame Basisklasse hätte diesen Unterschied nur künstlich verstecken müssen. Portiert aus dem eigenständigen `nextcloud`-Addon (`lib/nextcloud.php`/`lib/MediaplaceProvider.php`), auf den für MediaPlace-Browsing/-Import relevanten Ausschnitt reduziert (kein Upload-zu-Nextcloud/Löschen/ZIP/Share-Links – das bleibt Funktionsumfang der eigenständigen Nextcloud-Verwaltungsseite, falls parallel weiterbetrieben).

## FTPS (zurückgestellt)

PHPs `ext-ftp` (`ftp_ssl_connect()`) war im ursprünglichen Entwicklungs-Setup nicht installiert, ein Live-Test war dort nicht möglich. Ein `FtpsClient`/`FtpsProvider` nach demselben Muster wie `WebdavClient`/`WebdavProvider` lässt sich ergänzen, sobald die Extension verfügbar ist – Boot.php-Registrierung analog (`extension_loaded('ftp')`-Check vor der Registrierung, sonst erscheint der Provider einfach nicht in der Sidebar statt mit einem Laufzeitfehler zu scheitern).

## Verifikation

- `php -l` auf jeder geänderten PHP-Datei.
- `composer validate` nach Änderungen an `composer.json`.
- Lang-Parität: `diff <(grep -oE "^cloudconnect_[a-z0-9_]+" lang/de_de.lang | sort -u) <(grep -oE "^cloudconnect_[a-z0-9_]+" lang/en_gb.lang | sort -u)`.
- Neue Rechte müssen unter **Benutzer → Rollen** als Checkbox erscheinen (`rex_perm::register()` in `boot.php` – **kein** `permissions:`-Block in `package.yml`, der wird von REDAXO-Core nicht verarbeitet, siehe nextcloud-Bugfix 1.7.1).
- `var/log/system.log` nach jedem Deploy auf neue Einträge prüfen.
