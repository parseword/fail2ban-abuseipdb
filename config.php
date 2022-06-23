<?php

// Your AbuseIPDB API key goes here
define('API_KEY',
        '00000000000000000000000000000000000000000000000000000000000000000000000000000000');

/*
 * AbuseIPDB reports are public, and system log lines can contain sensitive
 * information. The $filters array lets you specify strings to be filtered out
 * of your public AbuseIPDB reports. Any string in this array will be replaced
 * with "[redacted]" in the comments submitted to AbuseIPDB.
 *
 * You might want to place email addresses, the hostnames and IP addresses of
 * your own systems, etc. into this array so they don't appear in public.
 */
$filters = [
    '192.168.69.1',
    'chungus.example.com',
];
