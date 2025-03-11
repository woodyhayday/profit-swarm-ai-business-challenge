<?php

// DNSimple API simple OAuth Token Generator example
// https://github.com/dnsimple/dnsimple-php
// https://developer.dnsimple.com/
//
// 1. Install via composer:
// composer require dnsimple/dnsimple-php
//
// 2. Create a new OAuth application:
// Log into dnsimple.com -> Click Account -> Click OAuth Applications -> Click Create Application
// Use the redirect URI: https://yourdomain.com/dnsimple-oauth.php
// 
// 3. Generate OAuth access token using this file
//
// 4. Put your generated tokens in a config.php file + delete this file
//
// 5. Use the functions in dnsimple-helper-functions.php to interact with the DNSimple API

// start session
session_start();

// Configuration
$client_id = '12345'; // Replace with your DNSimple client ID
$client_secret = '12345'; // Replace with your DNSimple client secret
$redirect_uri = 'https://yourdomain.com/dnsimple_oauth.php'; // Use the configured redirect URI from config
$authorize_url = 'https://dnsimple.com/oauth/authorize';
$token_url = 'https://api.dnsimple.com/v2/oauth/access_token';

// Step 1: Redirect to DNSimple for authorization
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    // Generate a random state parameter to prevent CSRF
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    
    // Build the authorization URL
    $params = [
        'response_type' => 'code',
        'client_id' => $client_id,
        'state' => $state,
        'redirect_uri' => $redirect_uri
    ];
    
    // Optional: Add account_id if you want to pre-select an account
    // if (isset($_GET['account_id'])) {
    //     $params['account_id'] = $_GET['account_id'];
    // }
    
    $auth_url = $authorize_url . '?' . http_build_query($params);
    
    // Redirect to DNSimple
    header('Location: ' . $auth_url);
    exit;
}

// Step 2: Handle the callback from DNSimple
if (isset($_GET['code'])) {
    // Verify state parameter to prevent CSRF
    if (!isset($_SESSION['oauth_state']) || $_GET['state'] !== $_SESSION['oauth_state']) {
        die('Invalid state parameter. Possible CSRF attack.');
    }
    
    // Exchange the authorization code for an access token
    $code = $_GET['code'];
    
    $post_data = [
        'grant_type' => 'authorization_code',
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'code' => $code,
        'redirect_uri' => $redirect_uri,
        'state' => $_SESSION['oauth_state']
    ];
    
    // Initialize cURL session
    $ch = curl_init($token_url);
    
    // Set cURL options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded']);
    
    // Execute cURL request
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Check for errors
    if (curl_errno($ch)) {
        die('cURL error: ' . curl_error($ch));
    }
    
    curl_close($ch);
    
    // Process the response
    if ($http_code == 200) {
        $token_data = json_decode($response, true);
        
        if (isset($token_data['access_token'])) {
            // Clear the state from session
            unset($_SESSION['oauth_state']);
            
            // Display the token
            echo '<h1>DNSimple OAuth Successful</h1>';
            echo '<p><strong>Access Token:</strong> ' . htmlspecialchars($token_data['access_token']) . '</p>';
            echo '<p><strong>Token Type:</strong> ' . htmlspecialchars($token_data['token_type']) . '</p>';
            echo '<p><strong>Account ID:</strong> ' . htmlspecialchars($token_data['account_id']) . '</p>';
            
            // You might want to store this token in your database for future use
            // saveTokenToDatabase($token_data);
        } else {
            echo '<h1>Error</h1>';
            echo '<p>Failed to retrieve access token. Response: ' . htmlspecialchars($response) . '</p>';
        }
    } else {
        echo '<h1>Error</h1>';
        echo '<p>Failed to retrieve access token. HTTP Status: ' . $http_code . '</p>';
        echo '<p>Response: ' . htmlspecialchars($response) . '</p>';
    }
}

// Handle error response from DNSimple
if (isset($_GET['error'])) {
    echo '<h1>Authorization Error</h1>';
    echo '<p><strong>Error:</strong> ' . htmlspecialchars($_GET['error']) . '</p>';
    
    if (isset($_GET['error_description'])) {
        echo '<p><strong>Description:</strong> ' . htmlspecialchars($_GET['error_description']) . '</p>';
    }
}
?>

