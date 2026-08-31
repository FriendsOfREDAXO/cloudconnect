# CloudConnect

**Verbindet MediaPlace mit WebDAV, Nextcloud und Dropbox**

![REDAXO](https://img.shields.io/badge/REDAXO-%3E%3D5.18-red) ![PHP](https://img.shields.io/badge/PHP-%3E%3D8.1-blue) ![MediaPlace](https://img.shields.io/badge/MediaPlace-%3E%3D1.21-green)

Klinkt sich über [MediaPlace](https://github.com/FriendsOfREDAXO/mediaplace)s Erweiterungspunkt für Cloud-Speicher (`StorageProviderInterface`) in dessen Sidebar ein – jede angebundene Quelle erscheint dort als eigener, durchsuchbarer Baum. Rein lesendes Browsen + Import einzelner Dateien in den lokalen Medienpool, kein Sync: einmal importiert ist eine Datei eine ganz normale lokale Mediendatei.

## Features

- **WebDAV** – beliebiger generischer WebDAV-Server (Basic Auth).
- **Nextcloud** – eigener, auf Nextcloud zugeschnittener Provider: Browsen, serverseitige Suche und echte Vorschaubilder über Nextclouds eigene Preview-API (mehr als reines WebDAV bietet).
- **Dropbox** – Browsen, Server-Suche, Vorschaubilder, Import.

Jede Quelle bringt ihr **eigenes, granulares Recht** mit (`cloudconnect[webdav_browse]`/`[nextcloud_browse]`/`[dropbox_browse]`) – kein globaler Schalter, eine Rolle kann gezielt nur einzelne Quellen bekommen.

**Mehrere Verbindungen pro Quelltyp gleichzeitig** – z. B. zwei verschiedene Nextcloud-Server oder mehrere WebDAV-Zugänge parallel, jede einzeln benannt (**CloudConnect → Verbindungen**: Verbindung erstellen → Typ wählen → Bezeichnung + Zugangsdaten → aktiv/inaktiv). Jede aktive Verbindung erscheint als eigener, mit ihrer Bezeichnung beschrifteter Baum in der MediaPlace-Sidebar. Das Recht gilt weiterhin pro **Quelltyp**, nicht pro einzelner Verbindung – eine Rolle mit `cloudconnect[nextcloud_browse]` sieht automatisch alle aktiven Nextcloud-Verbindungen.

> **FTPS** ist bewusst (noch) nicht enthalten – siehe [DEV.md](DEV.md) für den Hintergrund.

### Ablösung des eigenständigen `nextcloud`-Addons

Der hier enthaltene Nextcloud-Provider deckt die **MediaPlace-Integration** des älteren, eigenständigen [`nextcloud`](https://github.com/FriendsOfREDAXO/nextcloud)-Addons vollständig ab (Browsen, Suche, Vorschaubilder, Import) – für reine MediaPlace-Nutzung ist das `nextcloud`-Addon damit nicht mehr nötig, beide können nicht parallel für dieselbe Nextcloud-Instanz browsen (unterschiedliche Konfigurationsorte). Nicht mit portiert wurden die Funktionen der **eigenständigen Verwaltungsseite** des `nextcloud`-Addons, die außerhalb von MediaPlace laufen: Datei-**Upload vom Medienpool nach Nextcloud**, **Löschen**, **ZIP-Download** und öffentliche **Share-Links** (inkl. `REX_NEXTCLOUD_SHARE`-Modul-Variable). Wer eine dieser Zusatzfunktionen aktiv nutzt, sollte das `nextcloud`-Addon vorerst weiterbetreiben.

## Installation

1. Addon installieren und aktivieren. Voraussetzung: [MediaPlace](https://github.com/FriendsOfREDAXO/mediaplace) ≥ 1.21 ist installiert.
2. Unter **CloudConnect → Einstellungen** die gewünschten Quellen einrichten (siehe unten).
3. Unter **Benutzer → Rollen** den jeweiligen Rollen die passenden `cloudconnect[...]`-Rechte zuweisen.

### WebDAV einrichten

Für **Nextcloud-Server** gibt es unten einen eigenen, leistungsfähigeren Abschnitt („Nextcloud einrichten“, mit Suche + echten Vorschaubildern) – dieser generische WebDAV-Abschnitt ist für alle **anderen** WebDAV-Anbieter gedacht (ownCloud, Hoster-eigenes WebDAV etc.), funktioniert aber technisch auch mit Nextcloud, nur ohne dessen Zusatzfunktionen.

WebDAV ist kein einzelner Dienst, sondern ein Protokoll, das viele Anbieter unter einer eigenen URL anbieten – ein separates "App anlegen" wie bei Dropbox entfällt hier, es reicht ein bestehendes Konto beim jeweiligen Anbieter. Zugangsdaten und WebDAV-URL stehen im Kundenmenü/Kontrollpanel des jeweiligen Anbieters (z. B. Strato HiDrive, IONOS, Hetzner Storage Box, viele klassische Webhosting-Pakete), meist unter „Zugriffsmethoden“, „FTP/WebDAV-Zugang“ oder ähnlich – im Zweifel dort direkt nach „WebDAV“ suchen oder beim Support nachfragen.

**In CloudConnect eintragen:**

1. Unter **CloudConnect → Einstellungen** oben auf **„+ Neue Verbindung: WebDAV“** klicken.
2. **Bezeichnung** vergeben (frei wählbar, erscheint als Name des Baums in MediaPlace – sinnvoll bei mehreren WebDAV-Verbindungen, z. B. „Kunde A – Fotos“), dann Server-URL, Benutzername und Passwort (bzw. App-Passwort) eintragen.
3. Optional einen **Wurzel-Pfad** setzen, falls nur ein bestimmter Unterordner als Cloud-Baum in MediaPlace erscheinen soll (leer lassen für den kompletten WebDAV-Server).
4. „SSL-Zertifikat prüfen“ nur deaktivieren, wenn der Server ein selbstsigniertes Zertifikat verwendet und vertrauenswürdig ist (z. B. eigener interner Testserver) – sonst aktiviert lassen.
5. Speichern. Zurück in der Liste mit **„Verbindung testen“** prüfen, ob die Zugangsdaten funktionieren. Mehrere WebDAV-Verbindungen sind jederzeit über denselben „+ Neue Verbindung“-Link möglich.

### Nextcloud einrichten

Eigener Provider statt des generischen WebDAV-Abschnitts oben, weil Nextcloud zwei Zusatzfunktionen bietet, die reines WebDAV nicht kennt: eine serverseitige Volltextsuche und eine eigene Vorschaubild-API für echte Thumbnails in MediaPlace (statt nur Datei-Icons).

**Zugangsdaten erzeugen:** in der Nextcloud-Weboberfläche oben rechts auf den Avatar → **Einstellungen** → **Sicherheit** → Abschnitt „App-Passwörter“ → Namen vergeben (z. B. „CloudConnect“) → **Neues App-Passwort erzeugen** klicken. Das angezeigte Passwort **sofort kopieren** (wird danach nicht mehr angezeigt) und statt des echten Kontopassworts verwenden – so bleibt der Zugriff über CloudConnect separat widerrufbar, ohne das eigentliche Passwort zu ändern.

**In CloudConnect eintragen:**

1. Unter **CloudConnect → Einstellungen** oben auf **„+ Neue Verbindung: Nextcloud“** klicken.
2. **Bezeichnung** vergeben (z. B. „Firma A“, falls mehrere Nextcloud-Server angebunden werden), dann Server-URL **ohne** `/remote.php/dav` (also z. B. `https://cloud.example.com`, nicht die WebDAV-Endpunkt-URL wie beim generischen WebDAV oben), Benutzername und das eben erzeugte App-Passwort eintragen.
3. Optional einen **Wurzel-Pfad** setzen (wie bei WebDAV).
4. Speichern. Zurück in der Liste mit **„Verbindung testen“** prüfen, ob die Zugangsdaten funktionieren. Für weitere Nextcloud-Server denselben „+ Neue Verbindung“-Link erneut nutzen – jede Verbindung erscheint als eigener, mit ihrer Bezeichnung beschrifteter Baum in MediaPlace.

### Dropbox einrichten

Dropbox-Zugriff läuft über eine selbst angelegte "App" in der Dropbox-Entwicklerkonsole – das ist kein separates Produkt, sondern nur ein Zugangsschlüssel-Paar für das eigene (oder ein beliebiges) Dropbox-Konto. Ein normaler, kostenloser Dropbox-Account genügt.

> ⚠️ **Wichtigste Entscheidung, unbedingt vorher lesen:** Beim Anlegen der App muss der **Access type** auf **„Full Dropbox“** stehen – **nicht** „App folder“. Diese Wahl lässt sich nach dem Erstellen der App **nicht mehr ändern** (Dropbox bietet dafür keine Einstellung). Mit „App folder“ sieht die App nur einen eigenen, von Dropbox neu angelegten, leeren Unterordner (`Apps/<App-Name>/`) – niemals die echten Dateien im Dropbox-Konto. Das Ergebnis: CloudConnect verbindet sich scheinbar erfolgreich, zeigt im MediaPlace-Baum aber dauerhaft **0 Dateien**, obwohl im Dropbox-Konto selbst eindeutig Dateien liegen – ohne jede Fehlermeldung, da aus Dropbox-Sicht alles korrekt läuft (die App bekommt exakt das, wofür sie berechtigt ist: ihren eigenen leeren Ordner). Wurde die App versehentlich mit „App folder“ angelegt, hilft nur eine **neue** App mit „Full Dropbox“ plus neuen Zugangsdaten in CloudConnect – die bestehende App lässt sich nicht nachträglich umstellen.

1. Mit dem Dropbox-Konto, dessen Dateien durchsucht werden sollen, bei [dropbox.com](https://www.dropbox.com) anmelden (Account ggf. vorher kostenlos anlegen).
2. Die [Dropbox App Console](https://www.dropbox.com/developers/apps) öffnen → **Create app**.
3. **1. Choose an API**: **„Scoped access“** wählen (nicht „Legacy“).
4. **2. Choose the type of access you need**: **„Full Dropbox“** wählen – siehe Warnung oben, „App folder“ ist für diesen Anwendungsfall die falsche Wahl.
5. **3. Name your app**: einen beliebigen, Dropbox-weit eindeutigen Namen vergeben (z. B. „CloudConnect-<Projektname>“) → Nutzungsbedingungen bestätigen → **Create app**.
6. Im neu angelegten App-Dashboard oben den Reiter **Permissions** öffnen (erscheint erst nach dem Erstellen der App, nicht schon im Create-Dialog). Dort unter „Files and folders“ die Häkchen bei `files.metadata.read`, `files.content.read` **und** unter „Account info“ bei `account_info.read` setzen (letzteres für die „Verbunden als …“-Anzeige in CloudConnect). Ganz unten auf der Seite auf **Submit** klicken – ohne diesen Klick werden die Häkchen nicht gespeichert.
7. Zum Reiter **Settings** wechseln: dort stehen oben unter „App key“ und „App secret“ die beiden Zugangsschlüssel (Secret erst über den Link **„Show“** sichtbar machen). Direkt darunter zeigt Dropbox nochmal den gewählten **„Access type“** an (**„Full Dropbox“** sollte hier stehen – hier lässt sich der zuvor beschriebene Fehler nochmal gegenprüfen, bevor man weitermacht).
8. In CloudConnect unter **CloudConnect → Einstellungen** auf **„+ Neue Verbindung: Dropbox“** klicken, eine **Bezeichnung** vergeben (z. B. bei mehreren Dropbox-Konten der Kontoname), App key → Feld **App-Key (Client-ID)**, App secret → Feld **App-Secret (Client-Secret)** eintragen, **speichern**.
9. Die Verbindung jetzt über **„Bearbeiten“** erneut öffnen – dort erscheint (erst nach dem ersten Speichern) die **Redirect-URI**. Kopieren und im Dropbox-App-Dashboard, Reiter **Settings**, Abschnitt **„OAuth 2“**, Feld **„Redirect URIs“** einfügen → **Add**.
10. Zurück in der CloudConnect-Verbindungsliste bei dieser Verbindung auf **„Verbinden“** klicken, im Dropbox-Login/-Bestätigungsdialog den Zugriff erlauben. Direkt danach sollte die Liste „Verbunden als &lt;dein Name&gt;“ anzeigen und der Dropbox-Baum in MediaPlace echte Dateien zeigen. Für weitere Dropbox-Konten denselben Ablauf mit einer neuen Verbindung (ggf. mit einer zweiten Dropbox-App) wiederholen.

**Nachträglich einen Scope ergänzt oder den Access Type korrigiert?** Ein bestehender Verbindungs-Token behält die Berechtigungen vom Zeitpunkt des letzten Connects – nach jeder Änderung an den App-Permissions in der Dropbox-Konsole bei dieser Verbindung erst auf **„Verbindung trennen“**, dann erneut auf **„Verbinden“** klicken, sonst greifen die neuen Berechtigungen nicht.

## Für Entwickler

Siehe [DEV.md](DEV.md) für Architektur, das `StorageProviderInterface`-Muster und Hinweise für weitere Provider (z. B. den geplanten FTPS-Nachtrag).

## Credits

Entwickelt von [FriendsOfREDAXO](https://github.com/FriendsOfREDAXO). Nutzt [league/oauth2-client](https://github.com/thephpleague/oauth2-client) für den OAuth2-Verbindungsaufbau zu Dropbox.
