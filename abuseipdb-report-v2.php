<?php

/**
 * This is an intermediary script for reporting fail2ban events to AbuseIPDB.
 *
 * You should install this script on a web server you control. The accompanying
 * config.php file needs to be present and must contain your AbuseIPDB API key.
 * Finally, fail2ban itself must be configured to post events to this script.
 *
 * See the GitHub repository for documentation.
 *
 * *****************************************************************************
 * Copyright 2016, 2022 Shaun Cummiskey <shaun@shaunc.com> <https://shaunc.com/>
 * Repository: <https://github.com/parseword/fail2ban-abuseipdb/>
 *
 * This is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This software is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this software; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */
;

/*
 * The incoming $_POST array should contain three elements:
 *
 *      'ip' = The IP address to be reported
 *      'category' = One or more numeric categories as a comma-separated list
 *      'comment' = A log excerpt demonstrating the abuse
 *
 * The 'ip' and 'category' elements are mandatory.
 */
if (empty($_POST['ip']) || empty($_POST['category'])) {
    exit;
}

/*
 * Bail if uptime is <5m to avoid re-reporting recent, but already reported,
 * incidents from fail2ban. This is mostly for compatibility with the 0.9
 * branch of fail2ban, which was slow to restore bans when the service started.
 */
if (file_exists('/proc/uptime') && round(floatval(file_get_contents('/proc/uptime')))
        < 300) {
    exit;
}

/*
 * Sanitize the comment/log entries for this report. Any string you add to the
 * $filters array in config.php will be replaced with "[redacted]" in the data
 * submitted to AbuseIPDB.
 */
$_POST['comment'] = str_replace('\\', '', $_POST['comment']);
foreach ($filters as $filter) {
    $_POST['comment'] = preg_replace("|{$filter}|mi", '[redacted]',
            $_POST['comment']);
}

// If email spam is being reported, munge any email addresses in the report
if ($_POST['category'] == 11) {
    $_POST['comment'] = str_replace('@', '[at]', $_POST['comment']);
}

// Using the sanitized data, build an array to post to AbuseIPDB
$abuseIpdbPostArray = [
    'ip'         => $_POST['ip'],
    'categories' => $_POST['category'], //Note, different names here
    'comment'    => $_POST['comment'],
];

// Post the report data to AbuseIPDB
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.abuseipdb.com/api/v2/report');
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($abuseIpdbPostArray));
curl_setopt($ch, CURLOPT_HTTPHEADER,
        ['Key: ' . API_KEY, 'Accept: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$reply = curl_exec($ch);
$err = curl_error($ch);
curl_close($ch);
