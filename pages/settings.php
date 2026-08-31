<?php

/**
 * Einstellungen fuer cloudconnect: Verwaltung mehrerer, benannter
 * Verbindungen pro Quelltyp (WebDAV/Nextcloud/Dropbox), siehe
 * DEV.md "Mehrere Verbindungen pro Quelltyp". Reiner GET/POST-Assistent
 * ohne JS-Framework, passend zum bisherigen Stil dieses Addons:
 *   - Listenansicht (Default): alle Verbindungen + Aktionen je Zeile.
 *   - Formularansicht (?cloudconnect_new=<type> / ?cloudconnect_edit=<id>):
 *     Bezeichnung + typspezifische Zugangsdaten + Aktiv-Schalter.
 */

use FriendsOfRedaxo\CloudConnect\ConnectionStore;
use FriendsOfRedaxo\CloudConnect\OAuth\AbstractOAuthClient;
use FriendsOfRedaxo\CloudConnect\OAuth\DropboxClient;
use FriendsOfRedaxo\CloudConnect\Nextcloud\NextcloudClient;
use FriendsOfRedaxo\CloudConnect\Webdav\WebdavClient;

// Anonyme Closures statt global benannter Funktionen/Konstanten -- dieses
// Page-File koennte je nach Backend-Kontext theoretisch mehrfach eingebunden
// werden, ein `function`/`const` auf oberster Ebene waere dann bei der
// zweiten Einbindung ein fataler "Cannot redeclare"-Fehler (gleiches Muster
// wie schon zuvor in dieser Datei fuer die OAuth-Box etabliert).
$webdavLikeTypes = ['webdav', 'nextcloud'];
$oauthTypes = ['dropbox'];

/** @return array{icon: string, label: string} */
$cloudconnectTypeMeta = function (string $type): array {
    return match ($type) {
        'webdav' => ['icon' => 'fa-solid fa-server', 'label' => rex_i18n::msg('cloudconnect_provider_label_webdav')],
        'nextcloud' => ['icon' => 'fa-solid fa-cloud', 'label' => rex_i18n::msg('cloudconnect_provider_label_nextcloud')],
        'dropbox' => ['icon' => 'fa-brands fa-dropbox', 'label' => rex_i18n::msg('cloudconnect_provider_label_dropbox')],
        default => ['icon' => 'fa-solid fa-cloud', 'label' => $type],
    };
};

$cloudconnectOauthClient = function (string $type, string $connectionId): AbstractOAuthClient {
    return new DropboxClient($connectionId);
};

// ---- Meldung vom OAuth-Redirect (siehe Api\OAuthCallback::redirectWithStatus()) ----
$oauthStatus = rex_request('cloudconnect_status', 'string', '');
$oauthMessage = rex_request('cloudconnect_message', 'string', '');
$statusHtml = '';
if ('connected' === $oauthStatus) {
    $statusHtml = rex_view::success(rex_i18n::msg('cloudconnect_oauth_connected', $oauthMessage));
} elseif ('error' === $oauthStatus && '' !== $oauthMessage) {
    $statusHtml = rex_view::error(rex_escape($oauthMessage));
}

// ---- Speichern (neue oder bearbeitete Verbindung) ----
if (1 === rex_post('cloudconnect_save', 'int', 0)) {
    $id = rex_post('cloudconnect_id', 'string', '');
    $type = rex_post('cloudconnect_type', 'string', '');
    $label = trim(rex_post('cloudconnect_label', 'string', ''));
    $active = 1 === rex_post('cloudconnect_active', 'int', 0);

    $config = null;
    if (in_array($type, $webdavLikeTypes, true)) {
        $config = [
            'url' => rex_post('field_url', 'string', ''),
            'username' => rex_post('field_username', 'string', ''),
            'password' => rex_post('field_password', 'string', ''),
            'root' => rex_post('field_root', 'string', ''),
            'ssl_verify' => 1 === rex_post('field_ssl_verify', 'int', 0),
        ];
    } elseif (in_array($type, $oauthTypes, true)) {
        $config = [
            'client_id' => rex_post('field_client_id', 'string', ''),
            'client_secret' => rex_post('field_client_secret', 'string', ''),
        ];
        // Bereits vorhandene Token beim Bearbeiten NICHT loeschen -- nur
        // Client-ID/Secret werden hier ueberhaupt abgefragt, Token kommen
        // ausschliesslich aus dem OAuth-Flow (buildAuthorizationUrl()/
        // completeAuthorization()).
        if ('' !== $id) {
            $existing = ConnectionStore::get($id);
            if (null !== $existing) {
                $config = array_merge($existing['config'], $config);
            }
        }
    }

    if (null !== $config && '' !== $type && '' !== $label) {
        if ('' !== $id) {
            ConnectionStore::update($id, ['type' => $type, 'label' => $label, 'active' => $active, 'config' => $config]);
        } else {
            $id = ConnectionStore::create($type, $label, $active, $config);
        }
        echo rex_view::success(rex_i18n::msg('cloudconnect_conn_saved'));
    } else {
        echo rex_view::error(rex_i18n::msg('cloudconnect_conn_invalid'));
    }
}

// ---- Loeschen ----
$deleteId = rex_post('cloudconnect_delete', 'string', '');
if ('' !== $deleteId) {
    ConnectionStore::delete($deleteId);
    echo rex_view::success(rex_i18n::msg('cloudconnect_conn_deleted'));
}

// ---- Aktiv/Inaktiv umschalten ----
$toggleId = rex_post('cloudconnect_toggle_active', 'string', '');
if ('' !== $toggleId) {
    $conn = ConnectionStore::get($toggleId);
    if (null !== $conn) {
        ConnectionStore::setActive($toggleId, !$conn['active']);
    }
}

// ---- WebDAV/Nextcloud: Verbindung testen ----
$testId = rex_post('cloudconnect_test', 'string', '');
$testResultHtml = '';
if ('' !== $testId) {
    $conn = ConnectionStore::get($testId);
    if (null !== $conn) {
        try {
            $client = 'webdav' === $conn['type'] ? new WebdavClient($testId) : new NextcloudClient($testId);
            $entries = $client->listFiles('/');
            $testResultHtml = rex_view::success(rex_i18n::msg('cloudconnect_webdav_test_success', count($entries)));
        } catch (Throwable $e) {
            $testResultHtml = rex_view::error(rex_i18n::msg('cloudconnect_webdav_test_error', rex_escape($e->getMessage())));
        }
    }
}

// ---- Dropbox: Verbindung trennen ----
$disconnectId = rex_post('cloudconnect_disconnect', 'string', '');
if ('' !== $disconnectId) {
    $conn = ConnectionStore::get($disconnectId);
    if (null !== $conn && in_array($conn['type'], $oauthTypes, true)) {
        $cloudconnectOauthClient($conn['type'], $disconnectId)->disconnect();
        echo rex_view::success(rex_i18n::msg('cloudconnect_oauth_disconnected'));
    }
}

// ---- Dropbox: Verbindung aufbauen (Redirect zum Provider) ----
$connectId = rex_request('cloudconnect_connect', 'string', '');
if ('' !== $connectId) {
    $conn = ConnectionStore::get($connectId);
    if (null !== $conn && in_array($conn['type'], $oauthTypes, true)) {
        $client = $cloudconnectOauthClient($conn['type'], $connectId);
        if ($client->isConfigured()) {
            rex_response::sendRedirect($client->buildAuthorizationUrl());
            exit;
        }
    }
}

echo $statusHtml;
echo $testResultHtml;

$editId = rex_request('cloudconnect_edit', 'string', '');
$newType = rex_request('cloudconnect_new', 'string', '');
$listUrl = rex_url::currentBackendPage();

if ('' !== $editId || in_array($newType, [...$webdavLikeTypes, ...$oauthTypes], true)) {
    // ---- Formularansicht ----
    $connection = '' !== $editId ? ConnectionStore::get($editId) : null;
    $type = $connection['type'] ?? $newType;
    $config = $connection['config'] ?? [];
    $meta = $cloudconnectTypeMeta($type);
    $isWebdavLike = in_array($type, $webdavLikeTypes, true);

    ob_start();
    ?>
    <form method="post">
        <input type="hidden" name="cloudconnect_save" value="1">
        <input type="hidden" name="cloudconnect_id" value="<?= rex_escape($editId) ?>">
        <input type="hidden" name="cloudconnect_type" value="<?= rex_escape($type) ?>">

        <div class="form-group">
            <label class="control-label"><?= rex_i18n::msg('cloudconnect_conn_label_label') ?></label>
            <input type="text" name="cloudconnect_label" class="form-control" required value="<?= rex_escape($connection['label'] ?? '') ?>" placeholder="<?= rex_escape($meta['label']) ?>">
        </div>

        <?php if ($isWebdavLike): ?>
            <div class="form-group">
                <label class="control-label"><?= rex_i18n::msg('nextcloud' === $type ? 'cloudconnect_nextcloud_url_label' : 'cloudconnect_webdav_url_label') ?></label>
                <input type="text" name="field_url" class="form-control" value="<?= rex_escape($config['url'] ?? '') ?>" placeholder="<?= 'nextcloud' === $type ? 'https://cloud.example.com' : 'https://example.com/remote.php/webdav' ?>">
                <p class="help-block"><?= rex_i18n::msg('nextcloud' === $type ? 'cloudconnect_nextcloud_url_hint' : 'cloudconnect_webdav_url_hint') ?></p>
            </div>
            <div class="form-group">
                <label class="control-label"><?= rex_i18n::msg('cloudconnect_webdav_username_label') ?></label>
                <input type="text" name="field_username" class="form-control" value="<?= rex_escape($config['username'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="control-label"><?= rex_i18n::msg('nextcloud' === $type ? 'cloudconnect_nextcloud_password_label' : 'cloudconnect_webdav_password_label') ?></label>
                <input type="password" name="field_password" class="form-control" autocomplete="new-password" value="<?= rex_escape($config['password'] ?? '') ?>">
                <?php if ('nextcloud' === $type): ?><p class="help-block"><?= rex_i18n::msg('cloudconnect_nextcloud_password_hint') ?></p><?php endif; ?>
            </div>
            <div class="form-group">
                <label class="control-label"><?= rex_i18n::msg('cloudconnect_webdav_root_label') ?></label>
                <input type="text" name="field_root" class="form-control" placeholder="/" value="<?= rex_escape($config['root'] ?? '') ?>">
                <p class="help-block"><?= rex_i18n::msg('cloudconnect_webdav_root_hint') ?></p>
            </div>
            <div class="checkbox">
                <label><input type="checkbox" name="field_ssl_verify" value="1"<?= ($config['ssl_verify'] ?? true) ? ' checked' : '' ?>> <?= rex_i18n::rawMsg('cloudconnect_webdav_ssl_verify_label') ?></label>
                <p class="help-block"><?= rex_i18n::msg('cloudconnect_webdav_ssl_verify_hint') ?></p>
            </div>
        <?php else: ?>
            <div class="form-group">
                <label class="control-label"><?= rex_i18n::msg('cloudconnect_dropbox_key_label') ?></label>
                <input type="text" name="field_client_id" class="form-control" value="<?= rex_escape($config['client_id'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label class="control-label"><?= rex_i18n::msg('cloudconnect_dropbox_secret_label') ?></label>
                <input type="password" name="field_client_secret" class="form-control" autocomplete="new-password" value="<?= rex_escape($config['client_secret'] ?? '') ?>">
            </div>
            <?php if ('' !== $editId): ?>
                <div class="form-group">
                    <label class="control-label"><?= rex_i18n::msg('cloudconnect_redirect_uri_label') ?></label>
                    <input type="text" class="form-control" readonly onclick="this.select()" value="<?= rex_escape($cloudconnectOauthClient($type, $editId)->redirectUri()) ?>">
                    <p class="help-block"><?= rex_i18n::msg('cloudconnect_redirect_uri_hint') ?></p>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="checkbox">
            <label><input type="checkbox" name="cloudconnect_active" value="1"<?= ($connection['active'] ?? true) ? ' checked' : '' ?>> <?= rex_i18n::msg('cloudconnect_conn_active_label') ?></label>
        </div>

        <button type="submit" class="btn btn-save"><?= rex_i18n::msg('save') ?></button>
        <a href="<?= $listUrl ?>" class="btn btn-abort"><?= rex_i18n::msg('cloudconnect_conn_cancel') ?></a>
    </form>
    <?php
    $formBody = ob_get_clean();

    $fragment = new rex_fragment();
    $fragment->setVar('class', 'edit', false);
    $fragment->setVar('title', ('' !== $editId ? rex_i18n::msg('cloudconnect_conn_edit_title') : rex_i18n::msg('cloudconnect_conn_new_title')) . ': ' . rex_escape($meta['label']), false);
    $fragment->setVar('body', $formBody, false);
    echo $fragment->parse('core/page/section.php');

    return;
}

// ---- Listenansicht ----
ob_start();
?>
<p>
    <?php foreach ([...$webdavLikeTypes, ...$oauthTypes] as $type): $meta = $cloudconnectTypeMeta($type); ?>
        <a class="btn btn-default" href="<?= rex_url::currentBackendPage(['cloudconnect_new' => $type]) ?>">
            <i class="<?= rex_escape($meta['icon']) ?>"></i> <?= rex_i18n::msg('cloudconnect_conn_new_title') ?>: <?= rex_escape($meta['label']) ?>
        </a>
    <?php endforeach; ?>
</p>
<table class="table">
    <thead>
        <tr>
            <th><?= rex_i18n::msg('cloudconnect_conn_col_type') ?></th>
            <th><?= rex_i18n::msg('cloudconnect_conn_col_label') ?></th>
            <th><?= rex_i18n::msg('cloudconnect_conn_col_status') ?></th>
            <th><?= rex_i18n::msg('cloudconnect_conn_col_actions') ?></th>
        </tr>
    </thead>
    <tbody>
        <?php
        $connections = ConnectionStore::getAll();
        if ([] === $connections):
        ?>
        <tr><td colspan="4" class="text-muted"><?= rex_i18n::msg('cloudconnect_conn_none') ?></td></tr>
        <?php
        endif;
        foreach ($connections as $id => $conn):
            $meta = $cloudconnectTypeMeta($conn['type']);
            $isOauth = in_array($conn['type'], $oauthTypes, true);
        ?>
        <tr>
            <td><i class="<?= rex_escape($meta['icon']) ?>"></i> <?= rex_escape($meta['label']) ?></td>
            <td><?= rex_escape($conn['label']) ?></td>
            <td>
                <?php if ($conn['active']): ?>
                    <span class="text-success"><i class="fa-solid fa-circle-check"></i> <?= rex_i18n::msg('cloudconnect_conn_active_label') ?></span>
                <?php else: ?>
                    <span class="text-muted"><i class="fa-solid fa-circle-minus"></i> <?= rex_i18n::msg('cloudconnect_conn_inactive_label') ?></span>
                <?php endif; ?>
                <?php if ($isOauth):
                    $isConnected = ConnectionStore::isConnected($conn);
                ?>
                    <br>
                    <?php if ($isConnected): ?>
                        <span class="text-success"><i class="fa-solid fa-link"></i> <?= rex_i18n::msg('cloudconnect_oauth_status_connected', rex_escape($conn['config']['account_label'] ?: $meta['label'])) ?></span>
                    <?php elseif (ConnectionStore::isComplete($conn)): ?>
                        <span class="text-muted"><i class="fa-solid fa-link-slash"></i> <?= rex_i18n::msg('cloudconnect_oauth_not_connected') ?></span>
                    <?php else: ?>
                        <span class="text-muted"><i class="fa-solid fa-triangle-exclamation"></i> <?= rex_i18n::msg('cloudconnect_oauth_not_configured') ?></span>
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td>
                <a class="btn btn-default btn-xs" href="<?= rex_url::currentBackendPage(['cloudconnect_edit' => $id]) ?>"><?= rex_i18n::msg('cloudconnect_conn_edit_title') ?></a>

                <form method="post" style="display:inline">
                    <input type="hidden" name="cloudconnect_toggle_active" value="<?= rex_escape($id) ?>">
                    <button type="submit" class="btn btn-default btn-xs"><?= $conn['active'] ? rex_i18n::msg('cloudconnect_conn_deactivate') : rex_i18n::msg('cloudconnect_conn_activate') ?></button>
                </form>

                <?php if (!$isOauth): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="cloudconnect_test" value="<?= rex_escape($id) ?>">
                        <button type="submit" class="btn btn-default btn-xs"><i class="fa-solid fa-plug"></i> <?= rex_i18n::msg('cloudconnect_webdav_test_btn') ?></button>
                    </form>
                <?php elseif (ConnectionStore::isConnected($conn)): ?>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="cloudconnect_disconnect" value="<?= rex_escape($id) ?>">
                        <button type="submit" class="btn btn-default btn-xs"><i class="fa-solid fa-link-slash"></i> <?= rex_i18n::msg('cloudconnect_oauth_disconnect_btn') ?></button>
                    </form>
                <?php elseif (ConnectionStore::isComplete($conn)): ?>
                    <a class="btn btn-primary btn-xs" href="<?= rex_url::currentBackendPage(['cloudconnect_connect' => $id]) ?>"><i class="fa-solid fa-link"></i> <?= rex_i18n::msg('cloudconnect_oauth_connect_btn') ?></a>
                <?php endif; ?>

                <form method="post" style="display:inline" onsubmit="return confirm('<?= rex_escape(rex_i18n::msg('cloudconnect_conn_delete_confirm')) ?>')">
                    <input type="hidden" name="cloudconnect_delete" value="<?= rex_escape($id) ?>">
                    <button type="submit" class="btn btn-delete btn-xs"><?= rex_i18n::msg('cloudconnect_conn_delete') ?></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php
$listBody = ob_get_clean();

$fragment = new rex_fragment();
$fragment->setVar('class', 'edit', false);
$fragment->setVar('title', rex_i18n::msg('cloudconnect_settings_title'));
$fragment->setVar('body', $listBody, false);
echo $fragment->parse('core/page/section.php');
