<?php

// Example script to connect a website to Google Analytics
// Read more: https://profitswarm.ai/automation-set-up-google-analytics-for-new-site/

// Call the function like this:
$result = add_property_to_google_analytics($site_url, GOOG_ANALYTICS_DEFAULT_ACCOUNT_ID, null, false);

// store your Google Data API JWT in config.google.key.json

// check config.google.key.json exists
if ( !file_exists(ROOT_PATH.'/config.google.key.json') ){
	exit('config.google.key.json not found. Please make a copy of config.google.key.json.example and name it config.google.key.json in the root directory, pasting your Google Service Account JSON key in the file.');
}

// for GA api access, needs this
function get_google_service_account_token( $json_key_path = ROOT_PATH.'/config.google.key.json', $debug = false ) {

    $client = new Google_Client();
    $client->setAuthConfig($json_key_path);
    $client->setScopes(['https://www.googleapis.com/auth/analytics.edit']);
    $client->useApplicationDefaultCredentials();
    $token = $client->fetchAccessTokenWithAssertion();
    if (isset($token['access_token'])) {
        return $token['access_token'];
    }

    return false;

}

// gets the account id for a site
function get_google_analytics_account_id( $site_url, $access_token = null, $debug = false ) {

    if ( $access_token === null ) {
        $access_token = get_google_service_account_token(ROOT_PATH.'/config.google.key.json', $debug);
        if (!$access_token) {
            headers_500(array('error' => 'Could not get token'));
            return false;
        }
    }

    $urls = array( strtolower($site_url) );
    if ( strpos($site_url, 'http') === false ) {
        $urls[] = 'https://'.$site_url;
        $urls[] = 'http://'.$site_url;
    }

    // 1. List accounts
    $accounts_url = 'https://analyticsadmin.googleapis.com/v1beta/accounts';
    $accounts = json_decode(curl_google_api_get($accounts_url, $access_token), true);

    if ( is_array( $accounts ) && isset( $accounts['accounts'] )) foreach ($accounts['accounts'] as $account) {
        
        $account_id = $account['name']; // e.g. "accounts/123456"
        
        // 2. List properties for this account
        $props_url = "https://analyticsadmin.googleapis.com/v1beta/properties";
        $properties = json_decode(curl_google_api_get($props_url, $access_token, ['filter' => 'parent:'.$account_id]), true);        
        if ( $debug ) echo '<br>Google Analytics Properties (https://analyticsadmin.googleapis.com/v1beta/properties): ' . print_r($properties, true) . '<br>';
        
        if( is_array($properties) && isset($properties['properties']) && is_array($properties['properties'])) foreach ($properties['properties'] as $property) {

            // check property name is in urls
            if ( in_array($property['name'], $urls) ) {
                return true;
            }
            if ( in_array($property['displayName'], $urls) ) {
                return true;
            }
            
            // 3. Optionally, check $property['displayName'] or list data streams and check their defaultUri
            // For GA4, list data streams:
            $streams_url = "https://analyticsadmin.googleapis.com/v1beta/{$property['name']}/dataStreams";
            $streams = json_decode(curl_google_api_get($streams_url, $access_token, null), true);
            if ( $debug ) echo '<br>Google Analytics Streams (https://analyticsadmin.googleapis.com/v1beta/'.$property['name'].'/dataStreams): ' . print_r($streams, true) . '<br>';
            
            if ( is_array( $streams ) && isset( $streams['dataStreams'] ) && is_array($streams['dataStreams']) ) foreach ($streams['dataStreams'] as $stream) {
                if ( isset($stream['webStreamData']['defaultUri']) && in_array($stream['webStreamData']['defaultUri'], $urls ) ) {
                    return true;
                }
            }
        }
    }
    return false;
}

// https://developers.google.com/analytics/devguides/config/admin/v1/rest/v1beta/properties#Property 
function add_property_to_google_analytics( $site_url, $account_id = GOOG_ANALYTICS_DEFAULT_ACCOUNT_ID, $access_token = null, $debug = false ) {

    if ( $access_token === null ) {
        $access_token = get_google_service_account_token(ROOT_PATH.'/config.google.key.json', $debug);
        if (!$access_token) {
            headers_500(array('error' => 'Could not get token'));
            return false;
        }
    }

    // first check it doesn't already exist    
    $exists = get_google_analytics_account_id( $site_url, null, $debug );
    if ( $exists ) {
        if ( $debug ) echo '<br>Google Analytics Property already exists: ' . $exists . '<br>';
        headers_500(array('error' => 'Google Analytics Property already exists: ' . $exists));
        return false;
    }

    $url = 'https://analyticsadmin.googleapis.com/v1beta/properties';
    $data = [
        "parent" => "accounts/{$account_id}",
        "displayName" => $site_url,
        "industryCategory" => "OTHER", // see docs for full list
        "timeZone" => "UTC",
        "currencyCode" => "USD"
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $access_token",
        "Content-Type: application/json"
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err) return false;
    $result = json_decode($response, true);

    if ( is_array($result) && isset($result['name']) ) {

        // get account property name (id):
        $property_name = $result['name'];

        // add datastream to property
        $datastream_measurement_id = add_google_analytics_datastream_to_property( $property_name, $site_url, $access_token, $debug );

        // properties/24234 => 24234
        $property_id = str_replace('properties/', '', $property_name);

        // e.g. properties/587326807
        return array(
            'property_id' => $property_id,
            'datastream_measurement_id' => $datastream_measurement_id
        );
    }

    return false;

}

// https://developers.google.com/analytics/devguides/config/admin/v1/rest/v1beta/properties.dataStreams#DataStream
function add_google_analytics_datastream_to_property( $property_name, $site_url, $access_token = null, $debug = false ){

    if ( $access_token === null ) {
        $access_token = get_google_service_account_token(ROOT_PATH.'/config.google.key.json', $debug);
        if (!$access_token) {
            headers_500(array('error' => 'Could not get token'));
            return false;
        }
    }

    if ( empty($property_name) || empty($site_url) ) {
        headers_500(array('error' => 'Missing property or site_url'));
        return false;
    }

    // properties/12345
    $url = 'https://analyticsadmin.googleapis.com/v1beta/'.$property_name.'/dataStreams';

    $data = [
        "displayName" => $site_url,
        "type" => "WEB_DATA_STREAM",
        "webStreamData" => [
            "defaultUri" => $site_url
        ]
    ];
    

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $access_token",
        "Content-Type: application/json"
    ]);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ( $debug ) echo '<br>Google Analytics Datastream Response ('.curl_getinfo($ch, CURLINFO_HTTP_CODE).'): ' . $response . '<br>';

    if ($err) return false;
    $result = json_decode($response, true);

    if ( is_array($result) && isset($result['name']) ) {

        // get datasatream  name (id):
        $datastream_name = $result['name'];

        // take properties/487294491/dataStreams/12345 and get 12345
        $datastream_name = str_replace($property_name.'/dataStreams/', '', $datastream_name);

        // really for GA all we want is webstreamdata.measurementId
        if ( isset($result['webStreamData']) && is_array($result['webStreamData']) && isset($result['webStreamData']['measurementId']) ) {
            return $result['webStreamData']['measurementId'];
        }

    }

    /* 

    <!-- Google tag (gtag.js) -->
    <script async src="https://www.googletagmanager.com/gtag/js?id=<MEASUREMENT_ID>"></script>
    <script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());

    gtag('config', '<MEASUREMENT_ID>');
    </script>

    */

    return false;
    
}


function curl_google_api_get($url, $access_token, $params = null) {

    if ( $params ) {
        // attach to url as query string
        $url .= '?'.http_build_query($params);
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $access_token",
        "Content-Type: application/json"
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);

    return $response;

}