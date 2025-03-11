<?php

// DNSimple API simple helper functions
// https://github.com/dnsimple/dnsimple-php
// https://developer.dnsimple.com/
//
// 1. Install via composer:
// composer require dnsimple/dnsimple-php
//
// 2. Generate OAuth access token (see dnsimple-oauth.php):
// https://developer.dnsimple.com/v2/oauth/
//
// 3. Put your generated tokens in here (or better yet, in a config.php)
//
// 4. Use the functions below to interact with the DNSimple API (even simpler)

// oauth access
define('DNSIMPLE_ACCESS_TOKEN', 'dnsimple_o_12345');
define('DNSIMPLE_ACCOUNT_ID', '12345');

// defaults:
define('DNSIMPLE_AUTORENEW_DEFAULT', false);
define('DNSIMPLE_PRIVACY_DEFAULT', true);

require 'vendor/autoload.php'; // Ensure you have the DNSimple PHP client installed via Composer

use Dnsimple\Client;

$dnsimple_client = false;

function dnsimple_client() {
    
    global $dnsimple_client;

    if ( empty($dnsimple_client) ) {
        $dnsimple_client = new Client(DNSIMPLE_ACCESS_TOKEN);
    }

    return $dnsimple_client;
}

function dnsimple_is_domain_available($domain) {
    $response = dnsimple_client()->registrar->checkDomain(DNSIMPLE_ACCOUNT_ID, $domain);
    return $response->getData();
}

function dnsimple_register_domain($domain, $auto_renew = DNSIMPLE_AUTORENEW_DEFAULT, $privacy = DNSIMPLE_PRIVACY_DEFAULT) {
    $data = [
        'registrant_id' => 'registrant_id', // Replace with actual registrant ID
        'auto_renew' => $auto_renew,
        'privacy' => $privacy
    ];
    $response = dnsimple_client()->registrar->registerDomain(DNSIMPLE_ACCOUNT_ID, $domain, $data);
    return $response->getData();
}

function dnsimple_set_domain_nameservers($domain, $nameservers) {
    $response = dnsimple_client()->registrar->updateDomainDelegation(DNSIMPLE_ACCOUNT_ID, $domain, $nameservers);
    return $response->getData();
}

function dnsimple_get_domain_nameservers($domain) {
    $response = dnsimple_client()->registrar->getDomainDelegation(DNSIMPLE_ACCOUNT_ID, $domain);
    return $response->getData();
}

function dnsimple_check_domain_status($domain) {
    $response = dnsimple_client()->domains->getDomain(DNSIMPLE_ACCOUNT_ID, $domain); // Replace 'account_id' with your actual account ID
    return $response->getData();
}