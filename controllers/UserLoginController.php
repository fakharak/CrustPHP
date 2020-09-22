<?php
/**
 * Author: Fakhar Anwar Khan
 * Email: fakhar_anwar123@hotmail.com
 * Description:
 * This script is part of REST API Framework that is developed by using Swoole HTTP Server and Phalcon Micro.
 *
 * License:
 *
 * Copyright (c) 2020 Fakhar Anwar Khan
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms are permitted
 * provided that the above copyright notice and this paragraph are
 * duplicated in all such forms and that any documentation,
 * advertising materials, and other materials related to such
 * distribution and use acknowledge that the software was developed
 * by Fakhar Anwar Khan. The name of the
 * Fakhar Anwar Khan may not be used to endorse or promote products derived
 * from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND WITHOUT ANY EXPRESS OR
 * IMPLIED WARRANTIES, INCLUDING, WITHOUT LIMITATION, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE.
 */

namespace Controllers;

use Phalcon\Mvc\Controller;
use Phalcon\Security\Random;
use Phalcon\Filter;
use Phalcon\Security;
use \Exception;
use \DateTime;

//For Token Generation
use Lcobucci\JWT\Builder;
use Lcobucci\JWT\ValidationData;
use Lcobucci\JWT\Signer\Hmac\Sha512;
use Phalcon\Di\FactoryDefault;
use Phalcon\Http\Response;

class UserLoginController extends Controller
{
    //Configurations for Authorizations
    private $_tokenExpireSeconds;
    private $_extendSessionNotBefore;
    private $_serverName;
    private $_db;
    private $_swResponse;
    //private $_lib;

    public function onConstruct() {
        // Token Configs
        $this->_tokenExpireSeconds = '3600'; // Default: 15 minutes
        $this->_extendSessionNotBefore = abs(0.7 * $this->_tokenExpireSeconds);
        $this->_serverName = $this->di->get('bootstrap')->_SERVER['HTTP_HOST'];
    }

    //////////////////////////////
    ////Authentication Service////
    //////////////////////////////
    public function login_user()
    {
        try {
            $this->_db = $this->di->get('db');
            $this->_swResponse = $this->di->get('swoole_response');
            //$params = $this->request->getJsonRawBody();
            $params = json_decode($this->di->get('json_raw'), true); // Injected Swoole Json Raw Content
            $paramsArray = $this->verifyParams($params);
            $user_email = $paramsArray['user_email'];
            $password = $paramsArray['password'];

            //Get User from Email
            $query = "select `id`, `user_email`, `password`, `active_token_id`, 
                             `user_api_key`, `token_expire_at`, `current_token_uuid` 
                      from `api_clients` 
                      where `user_email` = '". $user_email ."';";
            $result = mysqli_query($this->_db, $query);

            $ph_response = new Response(); //Do not use $this->response / $this->response->send(); in this context

            if (!$result) {
                throw new Exception("Error:". mysqli_error($this->_db));
            }

            if (mysqli_num_rows($result)==0) {
                throw new Exception('Error: User Account With Provided Email Does Not Exist');
            }
            $user = mysqli_fetch_assoc($result);

            // Check Password
            $matched = $this->security->checkHash($password, $user['password']);
            if (!$matched) {
                throw new Exception("Error: Incorrect User Name Or Password");
            }

            if (!is_null($user['active_token_id'])) {
                // If Login Credentials are valid and User's token was still active
                //Retrieve complete record of active token using primary_key of Token Record stored
                //$tokenRec = $this->getUserActiveToken($user['active_token_id']); // //Note: does not require to return 'user_id' as in Auth_Middleware.
                //At this point, it is possible that the Session / Timeline of the so called active token has already been expired,
                // so check session expiry, if token is not expired return stored jwt token else 'false'
                $session = $this->getSession($user['token_expire_at'], $user['active_token_id']);
                if ($session) { //User Session is alive, so refresh token expiry with elapse time, for this authenticat user
                    $this->extendTokenExpiry($user['id'], $user['token_expire_at']);
                    $this->_swResponse
                        ->setHeader('Authorization', 'Bearer ' . $session['token']);
                    throw new Exception("Notice: Current Session/Token is alive. Session Time Extended. \nUse existing active token.");
                }
                    //Else Expire token/session whose timeline is actually finished but
                    // still alive_status is falsely set to true
                $this->expireToken($user['id'], $user['active_token_id']);
            }

            // This section is reachable if and only if ..
            // provided token is not found, or
            // if this is first login from the user, or ...
            // if user previously signed-out, or
            // if User Session was Expired (for this Authentic user).
            //Therefore create a new token
            $token = $this->createToken($user['id'], $user['user_email'], $user['user_api_key'], $user['password']); // create new token and set alive-fag

            //$headers = $ph_response->getHeaders();
            $this->_swResponse->status(201, 'Created');
            $this->_swResponse->setHeader('Content-Type', 'application/json');
            $this->_swResponse->setHeader('Authorization', 'Bearer '.$token);
            $ph_response->setJsonContent(
                [
                    'response' => array (
                    "message" => 'Success',
                    "DevInstruction" => 'Check "Authorization" Header',
                    "bearer token" => $token,
                    ) //uncomment if token also required in response-body
                ]
            );

            // Free result set
            mysqli_free_result($result);
        }
        catch(Exception $e) {
            $errorResponse =  $e->getMessage();
            if (!empty($e->getCode())) {
                $errorResponse .= ' Error Code: '.$e->getCode();
            }

            $this->_swResponse->status(409, 'Conflict');
            $this->_swResponse->setHeader('Content-Type', 'application/json');

            // If user is logged-in then send back his token in response body
            if (!$session || empty($session['token']))
                $ph_response->setJsonContent(
                    ['error' => $errorResponse]
                );
            else
                $ph_response->setJsonContent(
                    ['error' => $errorResponse,
                     'token' => $session['token']
                    ]
                );
        }
        finally {
            mysqli_close($this->_db);
            $this->security->hash(rand()); // To minimise the effect of Time Attack and DoS Attack on Data Access
            //$ph_response->sendHeaders();
            $ph_response->send();
        }
    }

    ////////////////////////
    ////Helper Functions////
    ///////////////////////
    public function createToken($user_id, $email, $user_api_key, $pasword){
        $token_created_at = new \DateTime("now", new \DateTimeZone('Europe/London'));
        $token_created_at = $token_created_at->format('Y-m-d H:i:s');

        $token_expire_at =   new \DateTime("now", new \DateTimeZone('Europe/London'));
        //$tokenExpireInterval = $this->_tokenExpireSeconds;
        $tokenExpireInterval = 'PT'.$this->_tokenExpireSeconds.'S';
        $token_expire_at->add(new \DateInterval($tokenExpireInterval));
        $token_expire_at = $token_expire_at->format('Y-m-d H:i:s');

        $random = new Random();
        $tokenUUID = $random->uuid(); //Must be checked for performance
        $token = $this->_getJWToken($user_api_key, $token_created_at, $tokenUUID, $pasword);

        //Add Token Record in Database
        $multiQuery = "START TRANSACTION;
                INSERT INTO `client_tokens` (`user_id`, `token`, `email`, `created_at`) 
                VALUES 
                ($user_id, '".$token."', '".$email."', '".$token_created_at."');
                UPDATE `api_clients` SET `current_token_uuid` = '".$tokenUUID."', `active_token_id` = LAST_INSERT_ID(), `token_expire_at` = '".$token_expire_at."' 
                where `id` = $user_id;
                COMMIT;";

        $db = $this->_db;
        while(mysqli_next_result($db)) {}
        if (!mysqli_multi_query($db, $multiQuery))
            throw new Exception('Error1: '. mysqli_error($db));
        while(mysqli_next_result($db)) {}
        if ($error = mysqli_errno($db))
            throw new \Exception('Error2: '.$error);
        return $token;
    }

    public function expireToken($user_id, $token_pkey)
    {
        $expired_at = new \DateTime("now", new \DateTimeZone('Europe/London'));
        $expired_at = $expired_at->format('Y-m-d H:i:s');
        $multiQuery = "START TRANSACTION;
        UPDATE `api_clients` set `active_token_id` = NULL, `current_token_uuid` = NULL where `id` = $user_id;
        UPDATE `client_tokens` SET `expired_at` = '".$expired_at."' where `id` = $token_pkey;
        COMMIT;";
        $db = $this->_db;
        while(mysqli_next_result($db)) {}
        if(!mysqli_multi_query($db, $multiQuery))
            throw new Exception('Error1: '. mysqli_error($db));
        while(mysqli_next_result($db)) {}
        if ($error = mysqli_errno($db))
            throw new \Exception('Error2: '.$error);
        return true;
    }

    public function extendTokenExpiry($user_id, $expire_at) {
        $dateTimeNow = new \DateTime("now", new \DateTimeZone('Europe/London'));
        $expire_at = new \DateTime($expire_at, new \DateTimeZone('Europe/London'));
        // The If else will increment time elapsed to the token time
        //During Two requests user will have time interval set in $this->_tokenExpireSeconds; default 15 minutes
        if ($dateTimeNow >= $expire_at){
            $expire_at->setTimestamp($expire_at->getTimestamp()+$this->_tokenExpireSeconds);
        }
        else{
            $expire_at->setTimestamp($dateTimeNow->getTimestamp()+$this->_tokenExpireSeconds);
        }

        $expire_at = $expire_at->format('Y-m-d H:i:s');

        //formula to calculate last_modified using "token_expire_at", on the fly
        //$last_modified = $expire_at->setTimestamp($expire_at->getTimestamp()-$this->_tokenExpireSeconds)->format('Y-m-d H:i:s');
        $query = "UPDATE `api_clients` SET `token_expire_at` = '".$expire_at."' where `id` = $user_id;";
        $result = mysqli_query($this->_db, $query);
        if (!$result) {
            throw new Exception("Error: ".mysqli_error($this->_db));
        }
        $affect = mysqli_affected_rows($this->_db);
        if ($affect == -1){
            throw new Exception("Error: ".mysqli_error($this->_db));
        }
        if ($affect == 0){
            throw new Exception("Error: No Records Updated");
        }
        return true;
    }

    public function getSession($expiryDate, $token_id) {
        $dateTimeNow = new \DateTime("now", new \DateTimeZone('Europe/London'));
        $expiryDate = new \DateTime($expiryDate, new \DateTimeZone('Europe/London'));
        if ($expiryDate > $dateTimeNow) {
            $tokenQry = "select `token` from `client_tokens` where `id` = $token_id";
            $result = mysqli_query($this->_db, $tokenQry);
            return mysqli_fetch_assoc($result);
        }
        return false;
    }

    private function _getJWToken($user_api_key, $token_created_at, $tokenId, $pasword) {
        //Create JWT Token
        $signer  = new Sha512();
        $builder = new Builder();
        $token   = $builder
            ->setIssuer($this->_serverName) // Token Issuer
            ->setAudience($user_api_key) // $user_api_key is User API ID KEY that was created at the time of Account Creation
            ->setId($tokenId, true)
            ->setIssuedAt($token_created_at)
            ->setNotBefore($this->_extendSessionNotBefore)
            ->setExpiration($this->_tokenExpireSeconds) //Expiry duration in seconds
            ->sign($signer, $pasword) // A hash of original password
            ->getToken();
        return $token->__toString();
    }

    public function verifyParams($params) {
        if (!$params) {
            throw new Exception("Error: Incorrect Parameters");
        }
        $filter = new Filter();
        if(!isset($params['email'])){
            throw new \Exception("Error: Email is required");
        }
        if(!isset($params['password'])){
            throw new \Exception("Error: Password is required");
        }
        $user_email = $this->filter->sanitize($params['email'], ['email', 'trim', 'striptags']);
        if (filter_var($params['email'], FILTER_VALIDATE_EMAIL) === FALSE){
            throw new \Exception("Error: Invalid Email");
        }
        $password = $this->filter->sanitize($params['password'], ['email', 'trim', 'striptags']);  //posted password string
        if (empty($password)) {
            throw new \Exception("Error: Enter valid Password");
        }
        return [
            'user_email' => $user_email,
            'password' => $password,
        ];
    }
}
