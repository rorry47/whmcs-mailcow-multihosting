<?php
/**
 * MailCow Provisioning Module for WHMCS
 *
 * Based on the original module by Websavers Inc and rorry47.
 *
 * Features:
 *  - Tariff plans via WHMCS ConfigOptions (no more config.php)
 *  - Server settings via WHMCS Server Manager (hostname + API key)
 *  - SuspendAccount / UnsuspendAccount
 *  - ChangePassword (domain admin)
 *  - Localisation via lang/ files (english, russian, ukrainian)
 *
 * Compatible with PHP 7.4+
 *
 * @see https://developers.whmcs.com/provisioning-modules/
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/MailcowAPI.php';

use Mailcow\MailcowAPI;

// ---------------------------------------------------------------------------
// Language loader
// ---------------------------------------------------------------------------

function mailcow_loadLang(): void
{
    global $_LANG;

    $langDir  = __DIR__ . '/lang/';
    $language = 'english';

    if (!empty($GLOBALS['CONFIG']['Language'])) {
        // BUG FIX: strip everything except a-z and hyphens to prevent
        // path traversal attacks via a crafted $CONFIG['Language'] value
        $language = preg_replace('/[^a-z\-]/', '', strtolower((string)$GLOBALS['CONFIG']['Language']));
        if ($language === '') {
            $language = 'english';
        }
    }

    $langFile = $langDir . $language . '.php';

    // Fall back to English if language file not found
    if (!file_exists($langFile)) {
        $langFile = $langDir . 'english.php';
    }

    if (file_exists($langFile)) {
        include $langFile;
    }
}

mailcow_loadLang();

// ---------------------------------------------------------------------------
// Translation helper
// ---------------------------------------------------------------------------

function mailcow_t(string $key, string $fallback = ''): string
{
    global $_LANG;
    return (!empty($_LANG[$key])) ? (string)$_LANG[$key] : $fallback;
}

// ---------------------------------------------------------------------------
// MetaData
// ---------------------------------------------------------------------------

function mailcow_MetaData(): array
{
    return [
        'DisplayName'              => 'MailCow',
        'APIVersion'               => '1.1',
        'RequiresServer'           => true,
        'DefaultNonSSLPort'        => '80',
        'DefaultSSLPort'           => '443',
        'ShowPanelLoginLink'       => false,
    ];
}

// ---------------------------------------------------------------------------
// ConfigOptions — per-product tariff settings
// Replaces config.php entirely. Set in WHMCS → Products → Module Settings.
// ---------------------------------------------------------------------------

function mailcow_ConfigOptions(): array
{
    return [
        // configoption1
        'Aliases Limit' => [
            'Type'        => 'text',
            'Size'        => '10',
            'Default'     => '100',
            'Description' => 'Max number of aliases for the domain',
        ],
        // configoption2
        'Mailboxes Limit' => [
            'Type'        => 'text',
            'Size'        => '10',
            'Default'     => '10',
            'Description' => 'Max number of mailboxes for the domain',
        ],
        // configoption3
        'Mailbox Quota (MB)' => [
            'Type'        => 'text',
            'Size'        => '10',
            'Default'     => '1024',
            'Description' => 'Maximum quota per individual mailbox (MB)',
        ],
        // configoption4
        'Default Mailbox Quota (MB)' => [
            'Type'        => 'text',
            'Size'        => '10',
            'Default'     => '1024',
            'Description' => 'Default quota pre-filled when creating a mailbox (MB)',
        ],
        // configoption5
        'Total Domain Quota (MB)' => [
            'Type'        => 'text',
            'Size'        => '10',
            'Default'     => '10240',
            'Description' => 'Total quota for all mailboxes in the domain combined (MB)',
        ],
        // configoption6
        'Rate Limit Value' => [
            'Type'        => 'text',
            'Size'        => '10',
            'Default'     => '10',
            'Description' => 'Rate limit value (number of messages per frame)',
        ],
        // configoption7
        'Rate Limit Frame' => [
            'Type'        => 'dropdown',
            'Options'     => 's,m,h,d',
            'Default'     => 's',
            'Description' => 'Rate limit time frame: s=second, m=minute, h=hour, d=day',
        ],
        // configoption8
        'Domains Limit' => [
            'Type'        => 'text',
            'Size'        => '10',
            'Default'     => '1',
            'Description' => 'Maximum number of domains the client can add (1 = only the main domain)',
        ],

    ];
}

// ---------------------------------------------------------------------------
// CreateAccount
// ---------------------------------------------------------------------------

function mailcow_CreateAccount(array $params): string
{
    try {
        $api = new MailcowAPI($params);
        $api->addDomain($params);
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    try {
        $api = new MailcowAPI($params);
        $api->addDomainAdmin($params);
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}


// ---------------------------------------------------------------------------
// Helper — load all client domains from WHMCS custom field
// ---------------------------------------------------------------------------

function mailcow_getClientDomains(array $params): array
{
    $primary = (string)$params['domain'];
    $domains = [$primary];

    if (!class_exists('\\WHMCS\\Database\\Capsule')) {
        return $domains;
    }
    try {
        $fieldId = mailcow_getOrCreateDomainsField((int)$params['serviceid']);
        if ($fieldId > 0) {
            $row = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $fieldId)
                ->where('relid', (int)$params['serviceid'])
                ->first();
            if ($row && !empty($row->value)) {
                $dec = json_decode((string)$row->value, true);
                if (is_array($dec) && !empty($dec)) {
                    $domains = $dec;
                }
            }
        }
    } catch (Exception $e) { /* ignore */ }

    // Always ensure primary is included
    if (!in_array($primary, $domains, true)) {
        $domains[] = $primary;
    }
    return $domains;
}

// ---------------------------------------------------------------------------
// SuspendAccount — disable domain + disable domain admin in Mailcow
// ---------------------------------------------------------------------------

function mailcow_SuspendAccount(array $params): string
{
    $allDomains = mailcow_getClientDomains($params);

    try {
        $api = new MailcowAPI($params);
        // Disable all client domains
        foreach ($allDomains as $d) {
            try {
                $api->disableDomainByName($d);
            } catch (Exception $e) {
                logModuleCall('mailcow', __FUNCTION__ . ':domain:' . $d, $params, $e->getMessage(), '');
            }
        }
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    try {
        $api = new MailcowAPI($params);
        $api->disableDomainAdmin($params);
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

// ---------------------------------------------------------------------------
// UnsuspendAccount — re-enable domain + domain admin in Mailcow
// ---------------------------------------------------------------------------

function mailcow_UnsuspendAccount(array $params): string
{
    $allDomains = mailcow_getClientDomains($params);

    try {
        $api = new MailcowAPI($params);
        // Re-enable all client domains
        foreach ($allDomains as $d) {
            try {
                $api->activateDomainByName($d);
            } catch (Exception $e) {
                logModuleCall('mailcow', __FUNCTION__ . ':domain:' . $d, $params, $e->getMessage(), '');
            }
        }
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    try {
        $api = new MailcowAPI($params);
        $api->activateDomainAdmin($params);
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

// ---------------------------------------------------------------------------
// TerminateAccount — remove mailboxes, aliases, domain, domain admin
// ---------------------------------------------------------------------------

function mailcow_TerminateAccount(array $params): string
{
    if ($params['status'] === 'Terminated') {
        return mailcow_t('mailcow_already_deleted', 'Account has already been deleted!');
    }

    $allDomains = mailcow_getClientDomains($params);
    $username   = (string)$params['username'];

    try {
        $api = new MailcowAPI($params);

        // Remove all client domains — mailboxes, aliases, domain itself
        foreach ($allDomains as $d) {
            try {
                // Delete mailboxes
                $mboxResult = $api->getMailboxesByDomain($d);
                if (!empty($mboxResult)) {
                    $usernames = array_values(array_filter(array_column($mboxResult, 'username')));
                    if (!empty($usernames)) {
                        $api->deleteMailboxes($usernames);
                    }
                }
                // Delete aliases
                $api->deleteAliasesByDomain($d);
                // Delete domain
                $api->deleteDomainByName($d);
            } catch (Exception $e) {
                logModuleCall('mailcow', __FUNCTION__ . ':domain:' . $d, $params, $e->getMessage(), '');
            }
        }

        // Remove domain admin
        $api->removeDomainAdmin($params);

    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

// ---------------------------------------------------------------------------
// ChangePassword — changes the domain admin password
// ---------------------------------------------------------------------------

function mailcow_ChangePassword(array $params): string
{
    try {
        $api    = new MailcowAPI($params);
        $result = $api->changePasswordDomainAdmin($params);
        logModuleCall('mailcow', __FUNCTION__, $params, $result, null);
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}


// ---------------------------------------------------------------------------
// ChangePackage — updates domain limits in Mailcow when tariff changes
// ---------------------------------------------------------------------------

function mailcow_ChangePackage(array $params): string
{
    // Apply new limits to ALL client domains, not just the primary one
    $allDomains = mailcow_getClientDomains($params);

    try {
        $api = new MailcowAPI($params);
        foreach ($allDomains as $d) {
            try {
                $api->editDomainByName((string)$d);
            } catch (Exception $e) {
                logModuleCall('mailcow', __FUNCTION__ . ':domain:' . $d, $params, $e->getMessage(), '');
            }
        }
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        return $e->getMessage();
    }

    return 'success';
}

// ---------------------------------------------------------------------------
// TestConnection — verifies API key + server connectivity
// ---------------------------------------------------------------------------

function mailcow_TestConnection(array $params): array
{
    try {
        $api     = new MailcowAPI($params);
        $success = $api->testConnection();
        $error   = $success ? '' : mailcow_t('mailcow_connection_fail', 'Connection failed');
    } catch (Exception $e) {
        logModuleCall('mailcow', __FUNCTION__, $params, $e->getMessage(), $e->getTraceAsString());
        $success = false;
        $error   = $e->getMessage();
    }

    return [
        'success' => $success,
        'error'   => $error,
    ];
}

// ---------------------------------------------------------------------------
// ClientArea — rendered in the client portal for this service
// ---------------------------------------------------------------------------

function mailcow_ClientArea(array $params): string
{
    $address = (!empty($params['serverhostname'])) ? $params['serverhostname'] : $params['serverip'];
    if (empty($address)) {
        return '';
    }

    $domain    = (string)$params['domain'];
    $username  = (string)$params['username'];
    $ip        = (string)$params['serverip'];
    $addr      = $address;

    $maxDomains = isset($params['configoption8']) && $params['configoption8'] !== ''
                    ? max(1, (int)$params['configoption8']) : 1;

    // Active tab
    $tab = isset($_GET['mc_tab']) ? preg_replace('/[^a-z]/', '', (string)$_GET['mc_tab']) : 'overview';
    if (!in_array($tab, ['overview', 'stats', 'domains'], true)) {
        $tab = 'overview';
    }


    // CSRF token — compatible with all WHMCS versions
    $csrfToken = '';
    if (!empty($_SESSION['WMCStokenID']))   { $csrfToken = (string)$_SESSION['WMCStokenID']; }
    elseif (!empty($_SESSION['WHMCS_token'])) { $csrfToken = (string)$_SESSION['WHMCS_token']; }
    elseif (!empty($_SESSION['token']))       { $csrfToken = (string)$_SESSION['token']; }
    else                                      { $csrfToken = session_id(); }

    $message = '';
    $error   = '';

    // ------------------------------------------------------------------
    // POST: Add domain
    // ------------------------------------------------------------------
    if (isset($_POST['mailcow_add_domain'])) {
        $postToken = isset($_POST['token']) ? (string)$_POST['token'] : '';
        if (!hash_equals($csrfToken, $postToken)) {
            return '<div class="alert alert-danger">Invalid request token.</div>';
        }

        $newDomain = strtolower(trim((string)($_POST['new_domain'] ?? '')));

        if (empty($newDomain) || !preg_match('/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $newDomain)) {
            $error = 'Invalid domain name.';
        } else {
            try {
                // Load current domains from WHMCS custom field
                $fieldId        = mailcow_getOrCreateDomainsField((int)$params['serviceid']);
                $currentDomains = [];
                if ($fieldId > 0 && class_exists('\\WHMCS\\Database\\Capsule')) {
                    $cfRow = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                        ->where('fieldid', $fieldId)->where('relid', (int)$params['serviceid'])->first();
                    if ($cfRow && !empty($cfRow->value)) {
                        $decoded = json_decode((string)$cfRow->value, true);
                        if (is_array($decoded)) $currentDomains = $decoded;
                    }
                }
                if (!in_array($domain, $currentDomains, true)) $currentDomains[] = $domain;

                if (count($currentDomains) >= $maxDomains) {
                    $error = 'Domain limit reached (' . $maxDomains . '). Upgrade your plan to add more domains.';
                } elseif (in_array($newDomain, $currentDomains, true)) {
                    $error = 'Domain ' . htmlspecialchars($newDomain) . ' is already added.';
                } else {
                    $api = new MailcowAPI($params);
                    // Security check: domain must not already exist on this server
                    if ($api->domainExists($newDomain)) {
                        $error = 'Domain ' . htmlspecialchars($newDomain) . ' already exists on this server. Contact support if you own this domain.';
                    } else {
                    $api->addDomainForAdmin($newDomain, $username, $currentDomains);
                    $currentDomains[] = $newDomain;
                    mailcow_saveDomainsField($fieldId, (int)$params['serviceid'], $currentDomains);
                    $message = 'Domain <strong>' . htmlspecialchars($newDomain) . '</strong> added successfully.';
                    } // end domainExists check
                }
            } catch (Exception $e) {
                logModuleCall('mailcow', 'ClientArea:add_domain', $params, $e->getMessage(), $e->getTraceAsString());
                $error = $e->getMessage();
            }
        }
    }


    // ------------------------------------------------------------------
    // POST: Delete domain (with confirmation)
    // ------------------------------------------------------------------
    if (isset($_POST['mailcow_delete_domain'])) {
        $postToken = isset($_POST['token']) ? (string)$_POST['token'] : '';
        if (!hash_equals($csrfToken, $postToken)) {
            return '<div class="alert alert-danger">Invalid request token.</div>';
        }

        $delDomain  = strtolower(trim((string)($_POST['del_domain'] ?? '')));
        $confirmed  = isset($_POST['confirm_delete']) && $_POST['confirm_delete'] === '1';

        if (empty($delDomain)) {
            $error = 'No domain specified.';
        } elseif ($delDomain === $domain) {
            $error = 'You cannot delete the primary domain of this service.';
        } elseif (!$confirmed) {
            $error = 'Please confirm deletion.';
        } else {
            try {
                // Verify domain is in client's list (stored in custom field)
                $fieldId    = mailcow_getOrCreateDomainsField((int)$params['serviceid']);
                $cfDomains  = [];
                if ($fieldId > 0 && class_exists('\\WHMCS\\Database\\Capsule')) {
                    $cfRow = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                        ->where('fieldid', $fieldId)->where('relid', (int)$params['serviceid'])->first();
                    if ($cfRow && !empty($cfRow->value)) {
                        $decoded = json_decode((string)$cfRow->value, true);
                        if (is_array($decoded)) $cfDomains = $decoded;
                    }
                }
                if (!in_array($domain, $cfDomains, true)) $cfDomains[] = $domain;

                if (!in_array($delDomain, $cfDomains, true)) {
                    $error = 'Domain not found or does not belong to your account.';
                } else {
                    $api = new MailcowAPI($params);
                    $api->removeDomainFromAdmin($delDomain, $username, $cfDomains);
                    $cfDomains = array_values(array_filter($cfDomains, function($d) use ($delDomain) {
                        return $d !== $delDomain;
                    }));
                    mailcow_saveDomainsField($fieldId, (int)$params['serviceid'], $cfDomains);
                    $message = 'Domain <strong>' . htmlspecialchars($delDomain) . '</strong> and all its mailboxes have been deleted.';
                }
            } catch (Exception $e) {
                logModuleCall('mailcow', 'ClientArea:delete_domain', $params, $e->getMessage(), $e->getTraceAsString());
                $error = $e->getMessage();
            }
        }
    }

    // ------------------------------------------------------------------
    // POST: DKIM regenerate
    // ------------------------------------------------------------------
    if (isset($_POST['gen_dkim'])) {
        $postToken = isset($_POST['token']) ? (string)$_POST['token'] : '';
        if (!hash_equals($csrfToken, $postToken)) {
            return '<div class="alert alert-danger">Invalid request token.</div>';
        }
        try {
            $api = new MailcowAPI($params);
            // Use regenerateDkim which deletes old key first, then creates new one
            $api->regenerateDkim($domain);
            $message = 'DKIM key regenerated successfully.';
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
        // Safe redirect back
        $safePath = '/' . ltrim(parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
        if (!empty($_SERVER['QUERY_STRING'])) {
            $safePath .= '?' . $_SERVER['QUERY_STRING'];
        }
        if (empty($error)) {
            header('Location: ' . $safePath);
            exit;
        }
    }

    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // Build tab navigation URL helper
    // ------------------------------------------------------------------
    $baseUrl = '/' . ltrim(parse_url((string)$_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
    $qs      = $_GET;
    unset($qs['mc_tab']);
    $qsBase  = http_build_query($qs);

    $tabUrl = function (string $t) use ($baseUrl, $qsBase): string {
        $q = $qsBase ? $qsBase . '&mc_tab=' . $t : 'mc_tab=' . $t;
        return htmlspecialchars($baseUrl . '?' . $q);
    };

    $csrfHidden  = htmlspecialchars($csrfToken);
    $domainH     = htmlspecialchars($domain);
    $addrH       = htmlspecialchars($addr);
    $ipH         = htmlspecialchars($ip);
    $userH       = htmlspecialchars($username);

    // ------------------------------------------------------------------
    // HTML output
    // ------------------------------------------------------------------
    $html = '';

    // Hide "Visit Website" button — works across all WHMCS versions
    $html .= '<style>.panel-service-overview .btn[href*="http"]:not(.btn-primary){display:none!important}</style>';

    if ($error)   { $html .= '<div class="alert alert-danger">'  . htmlspecialchars($error)   . '</div>'; }
    if ($message) { $html .= '<div class="alert alert-success">' . $message                             . '</div>'; }

    // Tab navigation — styled as button group
    $tabs = ['overview' => '&#9993; Overview', 'stats' => '&#128200; Statistics', 'domains' => '&#127758; Domains'];
    $html .= '<div class="btn-group" style="margin-bottom:20px;display:flex;flex-wrap:wrap;gap:4px">';
    foreach ($tabs as $key => $label) {
        $btnClass = ($tab === $key) ? 'btn btn-primary' : 'btn btn-default';
        $html .= '<a href="' . $tabUrl($key) . '" class="' . $btnClass . '">' . $label . '</a>';
    }
    $html .= '</div>';

    // ==================== TAB: OVERVIEW ====================
    if ($tab === 'overview') {

        // Direct link to Mailcow panel
        $html .= '<div style="margin-bottom:16px">';
        $html .= '<a href="https://' . $addrH . '" target="_blank" rel="noopener noreferrer" class="btn btn-primary">&#128274; Open Mailcow Panel</a>';
        $html .= '</div>';

        // Credentials
        $html .= '<div class="row"><div class="col-sm-4 text-right"><strong>Username</strong></div><div class="col-sm-8">' . $userH . '</div></div>';
        $html .= '<div class="row"><div class="col-sm-4 text-right"><strong>Mail Server</strong></div>';
        $html .= '<div class="col-sm-8"><a href="https://' . $addrH . '" target="_blank" rel="noopener noreferrer">' . $addrH . '</a></div></div>';

        $html .= '<hr>';
        $html .= '<p class="text-muted" style="font-size:13px">&#9432; DNS records for your domains are available in the <strong>Domains</strong> tab.</p>';
    }

    // ==================== TAB: STATISTICS ====================
    if ($tab === 'stats') {
        // Load all client domains from custom field
        $statsDomains = [$domain]; // always include primary
        try {
            if (class_exists('\\WHMCS\\Database\\Capsule')) {
                $sfId = mailcow_getOrCreateDomainsField((int)$params['serviceid']);
                if ($sfId > 0) {
                    $sfRow = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                        ->where('fieldid', $sfId)->where('relid', (int)$params['serviceid'])->first();
                    if ($sfRow && !empty($sfRow->value)) {
                        $dec = json_decode((string)$sfRow->value, true);
                        if (is_array($dec) && !empty($dec)) $statsDomains = $dec;
                    }
                }
            }
        } catch (Exception $e) { /* ignore */ }

        // Limits from configoptions
        $cfgMaxMboxes = max(1, (int)($params['configoption2'] ?? 10));
        $cfgQuotaMb   = max(1, (int)($params['configoption5'] ?? 10240));

        $api = new MailcowAPI($params);

        $totalUsedMb     = 0;
        $totalAllocMb    = 0;
        $totalMboxes     = 0;

        foreach ($statsDomains as $d) {
            $dH        = htmlspecialchars((string)$d);
            $dStats    = [];
            $dMboxes   = [];

            try {
                $dStats  = $api->getDomainStats((string)$d);
                $dMboxes = $api->getMailboxes((string)$d);
            } catch (Exception $e) {
                $html .= '<div class="alert alert-warning">Could not load stats for <strong>' . $dH . '</strong>: ' . htmlspecialchars($e->getMessage()) . '</div>';
                continue;
            }

            // Parse quota
            $usedBytes = 0;
            if (!empty($dStats['quota_used_in_domain'])) {
                $usedBytes = (int)$dStats['quota_used_in_domain'];
            } elseif (!empty($dStats['bytes_total'])) {
                $usedBytes = (int)$dStats['bytes_total'];
            }
            $usedMb = (int)round($usedBytes / 1048576);

            $maxQuotaRaw = isset($dStats['max_quota']) ? (int)$dStats['max_quota'] : 0;
            $totalMb     = $maxQuotaRaw > 1048576
                ? (int)round($maxQuotaRaw / 1048576)
                : ($maxQuotaRaw > 0 ? $maxQuotaRaw : $cfgQuotaMb);
            $freeMb  = max(0, $totalMb - $usedMb);

            $numMbox  = isset($dStats['mboxes_in_domain']) ? (int)$dStats['mboxes_in_domain'] : count($dMboxes);
            $maxMbox  = isset($dStats['max_num_mboxes_for_domain']) ? (int)$dStats['max_num_mboxes_for_domain']
                      : (isset($dStats['max_mailboxes']) ? (int)$dStats['max_mailboxes'] : $cfgMaxMboxes);
            if ($maxMbox === 0) $maxMbox = $cfgMaxMboxes;
            $leftMbox = max(0, $maxMbox - $numMbox);

            $allocMb = 0;
            foreach ($dMboxes as $mb) {
                $allocMb += isset($mb['quota']) ? (int)round($mb['quota'] / 1048576) : 0;
            }

            $totalUsedMb  += $usedMb;
            $totalAllocMb += $allocMb;
            $totalMboxes  += $numMbox;

            $pct      = $totalMb > 0 ? min(100, round($usedMb / $totalMb * 100)) : 0;
            $barColor = $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warning' : 'success');

            // Domain section header
            $html .= '<div class="panel panel-default" style="margin-bottom:16px">';
            $html .= '<div class="panel-heading"><strong>&#127758; ' . $dH . '</strong></div>';
            $html .= '<div class="panel-body" style="padding:12px 15px">';

            // Storage bar
            $html .= '<div class="progress" style="height:18px;margin-bottom:8px">';
            $html .= '<div class="progress-bar progress-bar-' . $barColor . '" style="width:' . $pct . '%;line-height:18px;font-size:12px">' . $pct . '%</div>';
            $html .= '</div>';
            $html .= '<table class="table table-condensed" style="margin:0 0 8px"><tbody>';
            $html .= '<tr><td><strong>Used</strong></td><td>' . number_format($usedMb) . ' MB</td>'
                   . '<td><strong>Free</strong></td><td>' . number_format($freeMb) . ' MB</td>'
                   . '<td><strong>Total quota</strong></td><td>' . number_format($totalMb) . ' MB</td></tr>';
            $html .= '<tr><td><strong>Mailboxes</strong></td><td>' . $numMbox . ' / ' . $maxMbox . '</td>'
                   . '<td><strong>Slots left</strong></td><td>' . $leftMbox . '</td>'
                   . '<td><strong>Allocated</strong></td><td>' . number_format($allocMb) . ' MB</td></tr>';
            $html .= '</tbody></table>';

            // Mailbox detail rows
            if (!empty($dMboxes)) {
                $html .= '<table class="table table-condensed table-striped" style="margin:0">';
                $html .= '<thead><tr><th>Address</th><th>Used (MB)</th><th>Quota (MB)</th><th style="width:120px">Usage</th></tr></thead><tbody>';
                foreach ($dMboxes as $mb) {
                    $mbUsed  = isset($mb['quota_used']) ? (int)round($mb['quota_used'] / 1048576) : 0;
                    $mbTotal = isset($mb['quota'])       ? (int)round($mb['quota']      / 1048576) : 0;
                    $mbPct   = $mbTotal > 0 ? min(100, round($mbUsed / $mbTotal * 100)) : 0;
                    $mbAddr  = htmlspecialchars((string)($mb['username'] ?? ''));
                    $html .= '<tr>';
                    $html .= '<td>' . $mbAddr . '</td>';
                    $html .= '<td>' . number_format($mbUsed) . '</td>';
                    $html .= '<td>' . number_format($mbTotal) . '</td>';
                    $html .= '<td><div class="progress" style="height:12px;margin:0"><div class="progress-bar" style="width:' . $mbPct . '%;line-height:12px;font-size:10px">' . ($mbPct > 10 ? $mbPct . '%' : '') . '</div></div></td>';
                    $html .= '</tr>';
                }
                $html .= '</tbody></table>';
            } else {
                $html .= '<p class="text-muted" style="margin:0;font-size:13px">No mailboxes in this domain.</p>';
            }

            $html .= '</div></div>'; // panel-body + panel
        }

        // Summary row if more than 1 domain
        if (count($statsDomains) > 1) {
            $html .= '<div class="alert alert-info" style="font-size:13px">';
            $html .= '<strong>Total across all domains:</strong> ';
            $html .= number_format($totalUsedMb) . ' MB used &nbsp;|&nbsp; ';
            $html .= $totalMboxes . ' mailboxes &nbsp;|&nbsp; ';
            $html .= number_format($totalAllocMb) . ' MB allocated';
            $html .= '</div>';
        }
    }


    // ==================== TAB: DOMAINS ====================
    if ($tab === 'domains') {
        // Load client domain list from WHMCS custom field (reliable across Mailcow versions)
        // Custom field 'mailcow_domains' stores JSON array of domains for this service
        $adminDomains = [];
        $domainFieldId = 0;

        if (class_exists('\\WHMCS\\Database\\Capsule')) {
            try {
                // Get or create the custom field
                $domainFieldId = mailcow_getOrCreateDomainsField((int)$params['serviceid']);

                if ($domainFieldId > 0) {
                    $cfRow = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                        ->where('fieldid', $domainFieldId)
                        ->where('relid', (int)$params['serviceid'])
                        ->first();

                    if ($cfRow && !empty($cfRow->value)) {
                        $decoded = json_decode((string)$cfRow->value, true);
                        if (is_array($decoded)) {
                            $adminDomains = $decoded;
                        }
                    }
                }
            } catch (Exception $e) { /* ignore */ }
        }

        // Always ensure primary domain is in the list
        if (!empty($domain) && !in_array($domain, $adminDomains, true)) {
            $adminDomains[] = $domain;
            // Save back to custom field
            if ($domainFieldId === 0) {
                $domainFieldId = mailcow_getOrCreateDomainsField((int)$params['serviceid']);
            }
            mailcow_saveDomainsField($domainFieldId, (int)$params['serviceid'], $adminDomains);
        }


        $usedDomains = count($adminDomains);
        $mcHost      = htmlspecialchars($addr);

        // Header: counter
        $html .= '<div class="row" style="margin-bottom:16px">';
        $html .= '<div class="col-sm-12"><strong>Domains:</strong> ' . (int)$usedDomains . ' / ' . (int)$maxDomains . '</div>';
        $html .= '</div>';

        // Domain list
        if (empty($adminDomains)) {
            $html .= '<p class="text-muted">No domains found.</p>';
        } else {
            foreach ($adminDomains as $d) {
                $dH      = htmlspecialchars((string)$d);
                $isPrimary = ($d === $domain);

                $html .= '<div class="panel panel-default" style="margin-bottom:12px">';
                $html .= '<div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center">';
                $html .= '<strong>' . $dH . '</strong>';
                $html .= '<div>';

                // Toggle DNS button — use data attribute to avoid JS injection
                $html .= '<a class="btn btn-xs btn-default mc-dns-toggle" data-target="mc-dns-' . $dH . '" href="#">DNS Records</a> ';

                // Delete button — not for primary domain
                if (!$isPrimary) {
                    $html .= '<button class="btn btn-xs btn-danger mc-del-toggle" data-target="mc-del-' . $dH . '">Delete</button>';
                } else {
                    $html .= '<span class="label label-default">Primary</span>';
                }

                $html .= '</div></div>'; // panel-heading

                // DNS records — hidden by default, toggled via JS
                $html .= '<div id="mc-dns-' . $dH . '" style="display:none;padding:12px 15px;font-size:12px">';
                $html .= '<table class="table table-condensed" style="margin:0">';

                $dnsEntries = [
                    [$dH,                        'MX',    '10 ' . $mcHost],
                    ['autoconfig.' . $dH,        'CNAME', $mcHost],
                    ['autodiscover.' . $dH,      'CNAME', $mcHost],
                    [$dH,                        'TXT',   'v=spf1 mx a:' . $mcHost . ' -all'],
                    ['_dmarc.' . $dH,            'TXT',   'v=DMARC1; p=none; rua=mailto:postmaster@' . $dH],
                    ['_autodiscover._tcp.' . $dH,'SRV',   '0 1 443 ' . $mcHost],
                ];

                foreach ($dnsEntries as $entry) {
                    $html .= '<tr>'
                        . '<td style="width:45%;font-weight:500">' . $entry[0] . '</td>'
                        . '<td style="width:10%"><span class="label label-default">' . $entry[1] . '</span></td>'
                        . '<td><code>' . $entry[2] . '</code></td>'
                        . '</tr>';
                }

                // DKIM — fetch from API
                try {
                    $apiDkim  = new MailcowAPI($params);
                    $dkimResp = $apiDkim->getDkim((string)$d);
                    $dkimTxt  = isset($dkimResp['body']['dkim_txt']) ? (string)$dkimResp['body']['dkim_txt'] : '';
                } catch (Exception $e) {
                    $dkimTxt = '';
                }

                if (!empty($dkimTxt)) {
                    $html .= '<tr>'
                        . '<td>dkim._domainkey.' . $dH . '</td>'
                        . '<td><span class="label label-default">TXT</span></td>'
                        . '<td style="word-break:break-all"><code>' . htmlspecialchars($dkimTxt) . '</code></td>'
                        . '</tr>';
                } else {
                    $html .= '<tr><td colspan="3" class="text-warning">DKIM not configured for this domain.</td></tr>';
                }

                $html .= '</table></div>'; // dns block + panel

                // Delete confirmation block — hidden by default
                if (!$isPrimary) {
                    $mboxCount = 0;
                    try {
                        $apiCount  = new MailcowAPI($params);
                        $mboxCount = $apiCount->countDomainMailboxes((string)$d);
                    } catch (Exception $e) { /* ignore */ }

                    $html .= '<div id="mc-del-' . $dH . '" style="display:none;padding:12px 15px;background:#fff3cd;border-top:1px solid #ffc107">';
                    $html .= '<p><strong>&#9888; Warning!</strong> You are about to permanently delete <strong>' . $dH . '</strong>.';
                    if ($mboxCount > 0) {
                        $html .= ' This domain has <strong>' . (int)$mboxCount . ' mailbox(es)</strong> — all will be deleted.';
                    }
                    $html .= '</p>';
                    $html .= '<form method="POST">';
                    $html .= '<input type="hidden" name="token" value="' . $csrfHidden . '">';
                    $html .= '<input type="hidden" name="mailcow_delete_domain" value="1">';
                    $html .= '<input type="hidden" name="del_domain" value="' . $dH . '">';
                    $html .= '<input type="hidden" name="confirm_delete" value="1">';
                    $html .= '<button type="submit" class="btn btn-danger btn-sm">&#10003; Confirm deletion</button> ';
                    $html .= '<button type="button" class="btn btn-default btn-sm mc-del-hide" data-target="mc-del-' . $dH . '">Cancel</button>';
                    $html .= '</form>';
                    $html .= '</div>';
                }

                $html .= '</div>'; // panel
            }
        }

        // Add domain form
        if ($usedDomains < $maxDomains) {
            $html .= '<hr>';
            $html .= '<h4>Add Domain</h4>';
            $html .= '<form method="POST" style="max-width:400px">';
            $html .= '<input type="hidden" name="token" value="' . $csrfHidden . '">';
            $html .= '<input type="hidden" name="mailcow_add_domain" value="1">';
            $html .= '<div class="input-group">';
            $html .= '<input type="text" name="new_domain" class="form-control" placeholder="example.com" maxlength="255">';
            $html .= '<span class="input-group-btn"><button type="submit" class="btn btn-primary">Add</button></span>';
            $html .= '</div>';
            $html .= '<small class="text-muted">Domain limits: ' . (int)$usedDomains . ' / ' . (int)$maxDomains . '</small>';
            $html .= '</form>';
        } else {
            $html .= '<div class="alert alert-warning" style="margin-top:16px">Domain limit reached. Upgrade your plan to add more domains.</div>';
        }

        // JS helpers
        $html .= '<script>';
        $html .= '(function(){'
            . 'function tog(id){var el=document.getElementById(id);if(el){el.style.display=el.style.display==="none"?"block":"none";}}'
            . 'function hide(id){var el=document.getElementById(id);if(el){el.style.display="none";}}'
            . 'document.addEventListener("click",function(e){'
            . '  var t=e.target.closest(".mc-dns-toggle");if(t){e.preventDefault();tog(t.getAttribute("data-target"));}'
            . '  var d=e.target.closest(".mc-del-toggle");if(d){tog(d.getAttribute("data-target"));}'
            . '  var h=e.target.closest(".mc-del-hide");if(h){hide(h.getAttribute("data-target"));}'
            . '});'
            . '})();';
        $html .= '</script>';
    }

    return $html;
}

// ---------------------------------------------------------------------------
// Helper — get or create 'mailcow_domains' custom field for a service
// ---------------------------------------------------------------------------

function mailcow_getOrCreateDomainsField(int $serviceId): int
{
    return mailcow_getOrCreateServiceField('mailcow_domains', $serviceId);
}

function mailcow_saveDomainsField(int $fieldId, int $serviceId, array $domains): void
{
    if (!class_exists('\\WHMCS\\Database\\Capsule') || $fieldId === 0) {
        return;
    }
    try {
        $value    = json_encode(array_values(array_unique($domains)));
        $existing = \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
            ->where('fieldid', $fieldId)
            ->where('relid', $serviceId)
            ->first();

        if ($existing) {
            \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->where('fieldid', $fieldId)
                ->where('relid', $serviceId)
                ->update(['value' => $value]);
        } else {
            \WHMCS\Database\Capsule::table('tblcustomfieldsvalues')
                ->insert(['fieldid' => $fieldId, 'relid' => $serviceId, 'value' => $value]);
        }
    } catch (Exception $e) { /* ignore */ }
}

function mailcow_getOrCreateServiceField(string $fieldName, int $serviceId): int
{
    if (!class_exists('\\WHMCS\\Database\\Capsule')) {
        return 0;
    }
    try {
        $packageId = \WHMCS\Database\Capsule::table('tblhosting')
            ->where('id', $serviceId)
            ->value('packageid');

        if (!$packageId) return 0;

        $fieldId = \WHMCS\Database\Capsule::table('tblcustomfields')
            ->where('type', 'product')
            ->where('relid', (int)$packageId)
            ->where('fieldname', $fieldName)
            ->value('id');

        if ($fieldId) return (int)$fieldId;

        return (int)\WHMCS\Database\Capsule::table('tblcustomfields')->insertGetId([
            'type'      => 'product',
            'relid'     => (int)$packageId,
            'fieldname' => $fieldName,
            'fieldtype' => 'text',
            'adminonly' => 'on',
        ]);
    } catch (Exception $e) {
        return 0;
    }
}
