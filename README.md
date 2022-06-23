# fail2ban-abuseipdb

This repository contains an intermediary PHP "helper" script (and configuration) 
for submitting sanitized [fail2ban](https://github.com/fail2ban/fail2ban/) 
events to the [AbuseIPDB](https://abuseipdb.com) abuse reporting system.

This is the code that used to live on wiki.shaunc.com until I shut that down
due (ironically) to relentless abuse from spambots. This code is licensed
under the GPLv2, same as fail2ban itself, and can be freely incorporated into
any project with a GPLv2-compliant license.

## Background

fail2ban is a utility that parses various system and software log files looking
for signs of network abuse, and then firewalls out the offending IP addresses.
It's becoming more common to crowdsource these incidents to centralized databases,
so administrators can watch for patterns of abuse or pre-emptively block known
malicious IPs. One of these efforts is [AbuseIPDB](https://abuseipdb.com).

fail2ban now comes with an action file for AbuseIPDB and it can be configured
to report events there out-of-the-box. However, that action definition
submits your log excerpts **verbatim** directly to AbuseIPDB. These reports
are public, and system log lines can contain sensitive information. I needed
a way to sanitize and redact these reports, so I wrote a helper script.

Instead of submitting reports directly to AbuseIPDB, a PHP script running on a
web server you control is used as an intermediary: 

1. fail2ban submits its data to the PHP script on your server;
2. The PHP script performs whatever scrubbing/redaction you need it to do;
3. The report is then passed on to AbuseIPDB.

This way, you can avoid having sensitive information like email addresses, 
server names, etc. showing up in your public AbuseIPDB reports. If you know PHP,
you can tweak the helper script to perform other tasks, too; for example, 
mine also logs everything to a separate MySQL database.

## Requirements

You'll need to have:

- One system running a web server like Apache or Nginx, with support for PHP 
(mod_php, PHP-FPM, etc.). PHP must have the cURL functions enabled.

- One or more systems running fail2ban.

- A registered AbuseIPDB account and its associated API key.

The fail2ban program on your client system(s) will send HTTP POST requests to the 
PHP script running on your web server. That script will do some sanitizing and
redaction on your logs, and then submit the cleaned report to AbuseIPDB.

## Installing / Configuring

To get things up and running, follow these instructions.

### Register for AbuseIPDB

If you don't already have an AbuseIPDB account, visit [their site](https://abuseipdb.com/) 
to register. Once you have an account, login and go 
to [https://www.abuseipdb.com/account/api](https://www.abuseipdb.com/account/api) 
to obtain your API key.

### Set up your web environment

Create a directory somewhere in your web server's document root to hold the
PHP script and its config file. You only need to do this once. If you have 
multiple servers running fail2ban, you'll point them all at the same URL.

For purposes of these instructions, let's assume you called your new
directory `abusereport` and the corresponding URL is `https://your.example.com/abusereport/`.

This directory needs to be accessible to your servers that run fail2ban, but it 
shouldn't be accessible to anyone else. You don't want some moron to discover
it and submit bogus abuse reports with your API key. It's wise to use .htaccess
or some other mechanism to restrict access to this directory by IP address.

### Install the intermediary script

Place the `abuseipdb-report-v2.php` and `config.php` files in your `abusereport`
directory.

Edit `config.php` so it includes your AbuseIPDB API key.

Also in `config.php` is an array called `$filters`. This array manages the
redactions that the script will perform on your log excerpts. AbuseIPDB reports 
are public, and system log lines can contain sensitive information. You should
edit the `$filters` array to include whatever strings you don't want to show
up in your public AbuseIPDB reports. 

For example, a `$filters` array like this would strip out a server's hostname
and IP address from any logs:

```php
$filters = [
    '192.168.69.1',
    'chungus.example.com',
];
```

### Install the abuseipdb.local action file

Place the `abuseipdb.local` file into fail2ban's action definition directory. This is
probably at `/etc/fail2ban/action.d/` but your mileage may vary. When fail2ban
sees you have an `abuseipdb.local` file, it will use that one instead of the
default `abuseipdb.conf`.

Edit the `abuseipdb.local` file and find the `[Init]` section at the bottom.
Change the `helper_script_url` option so it points to your copy of the PHP 
script. The URL should be in quotes. Here's an example:

    [Init]
    # Option:  helper_script_url
    # Notes:   The URL to the helper PHP script on your web server
    # Values:  STRING
    helper_script_url = "http://your.example.com/abusereport/abuseipdb-report-v2.php"

### Edit fail2ban's jail.local file

Locate fail2ban's `jail.local` file and make a backup copy of it. The file is
probably in `/etc/fail2ban` but your mileage may vary. 

After making a backup, edit the file and ensure the "ACTIONS" section near
the top contains this line:

`action_abuseipdb = abuseipdb`

This line defines the `action_abuseipdb` action. Now you need to tell fail2ban
to use it. In the "JAILS" section of the `jail.local` file, you can configure
individual jails to use `action_abuseipdb`. When calling this action, you must
specify one or more [AbuseIPDB category IDs](https://abuseipdb.com/categories). 

For example, here's a jail config for sshd:

    [sshd]
    mode    = aggressive
    enabled = true
    backend = gamin
    port    = ssh
    findtime = 7200
    bantime = 1209600
    logpath = %(sshd_log)s
    action  = %(action_mwl)s
              %(action_abuseipdb)s[abuseipdb_category="18,22"]

Here you can see there are two actions configured. The first is `action_mwl` which
is a fail2ban built-in action that sends an email notification. Next is `action_abuseipdb` 
which causes fail2ban to send an HTTP POST request to the PHP script URL you 
specified in `abuseipdb.local`.

Notice how the categories are passed into the action. This is critical because
the PHP script won't do anything without at least one category ID. AbuseIPDB
has defined a bunch of [category IDs](https://abuseipdb.com/categories) you can
use for different types of reports. This jail is set to use categories 18 
(brute-force) and 22 (SSH).

### Restart fail2ban

Restart fail2ban, however that's done on your machine, or else just reboot the
machine. Inspect fail2ban's log file for any error messages. You can login to
your AbuseIPDB account and check out your [reports page](https://www.abuseipdb.com/account/reports)
to see whether the reports are being submitted properly.

### (Optional) SELinux module installation

**This step will not be required for most users**. Don't perform this step
unless you know for sure that SELinux is the problem.

If you're running fail2ban on a server where SELinux is enforcing, you may
encounter problems with this setup. That's because we're doing things that the
stock fail2ban and its SELinux policies weren't necessarily designed to do. 
SELinux-related errors will appear in `/var/log/messages` 
and `/var/log/audit/audit.log`, or the equivalent on your OS.

You can try installing the `fail2ban-selinux` package (RHEL/CentOS), which 
contains additional SELinux policies. This may not be sufficient in some cases, 
particularly if you've compiled your own PHP binary. If you're still running 
into SELinux errors, this repository contains a custom SELinux policy module 
that might help. To install it,

1. Copy the `custom-fail2ban.te` file to `/tmp`

2. Run the following commands:

```bash
/usr/bin/checkmodule -M -m -o /tmp/custom-fail2ban.mod /tmp/custom-fail2ban.te
/usr/bin/semodule_package -o /tmp/custom-fail2ban.pp -m /tmp/custom-fail2ban.mod
/usr/sbin/semodule -i /tmp/custom-fail2ban.pp
```

## This $#!7 Sucks And Doesn't Work!!!

You can [open a GitHub issue](https://github.com/parseword/fail2ban-abuseipdb/issues/new) 
and I'll try to check it out. Please note this project is pretty low on my 
priority list and I might not address your ticket right away.

## Disclaimer

I'm not affiliated with fail2ban or AbuseIPDB. These projects and their names 
are the property of their respective owners.

## Author

Shaun Cummiskey    
Web: [https://shaunc.com/](https://shaunc.com/)    
Email: shaun {at} shaunc.com
