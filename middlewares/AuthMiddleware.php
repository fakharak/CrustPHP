<?php

namespace Middlewares;

use Phalcon\Mvc\Micro;
use Phalcon\Mvc\Router;
use Phalcon\Events\Event;
use Phalcon\Events\Manager;
use Phalcon\Mvc\Micro\MiddlewareInterface;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Token;
use Constants\Flags;
use Constants\JWTClaims as JWTClaims;
use Lcobucci\JWT\ValidationData;
use Lcobucci\JWT\Signer\Hmac\Sha512;
use \Exception;

//$authHeader = $app->di->get('swoole_request')->header['authorization']; //To Get Request / Request Header
//$this->_swResponse->write("<b>Auth Header Sent:</b> ".$string."<br/>"); // This is equal to echo
//$this->_swResponse->write("<b>Auth Header Sent:</b> ".print_r($array, true)."<br/>"); // To Print Array
class AuthMiddleware implements MiddlewareInterface
{
    private $_db;
    private $_swResponse;

    public function beforeExecuteRoute(Event $event, $app) {
        $this->_db = $app->di->get('db');
        $this->_swResponse = $app->di->get('swoole_response');

        //In future this array can be made as part of a "route cofiguration"
        $authorizeExceptions = [
            'login',
            'signup',
            'account_activation',
        ];
        try {
            $content_type = $app->request->getHeader('Content-Type');

            //Validate Content-Type
            preg_match('/(application\/json)(\;?)(\s*)(charset=utf-8)?$/i', $content_type, $matches);
            if (in_array($app->request->getMethod(), ['POST', 'PUT']) AND $matches[1] != 'application/json') {
               throw new Exception('Only application/json is accepted for Content-Type in POST requests.', 400);
            }

            $currentRouteName = $app->router->getMatchedRoute()->getName();
            if (!in_array($currentRouteName, $authorizeExceptions)) {
                $authHeader = $app->request->getHeader('Authorization');
                if (empty($authHeader) || strlen($authHeader) < 320) {
                    throw new Exception('Valid "Authorization Token" Required in Request Header "Atuhorization', '401');
                }

                preg_match('/Bearer\s(\S+)/', $authHeader, $matches);
                if (!$matches || !isset($matches[1]) || empty(($matches[1]))) {
                    throw new Exception('Valid "Authorization Token" Required In Request Header "Authorization"', '401');
                }

                $user = $this->authorize($app, $matches[1], $currentRouteName);

                if (is_null($user['token'])) {
                    throw new Exception($user['msg'], 401);
                }

                //return the valid token in 'Authorization' header for all endpoints (except 'login' and 'signup' endpoint)
                $this->_swResponse->setHeader('Authorization', 'Bearer '.$user['token']);
                $app->di->set('loggedin_user', function() use ($user) { return $user; });
            }
            return true;
        }
        catch (Exception $e) {
            $this->_swResponse->status($e->getCode(), $e->getMessage());
            $this->_swResponse->setHeader('Content-Type', 'application/json');
            $this->_swResponse->end("error: ".$e->getMessage());
        }
    }

    private function authorize(Micro $app, $token, $currentRouteName = null) {
        $parsedToken = (new Parser())->parse($token);

        $parameters  = [
            'issuer'  => $parsedToken->getClaim(JWTClaims::CLAIM_ISSUER),
            'tokenUUID' => $parsedToken->getClaim(JWTClaims::CLAIM_ID),
            //'user_api_key' => $parsedToken->getClaim(JWTClaims::CLAIM_AUDIENCE),
            'status'  => Flags::ACTIVE,
        ];


        //Get User from user_api_key stored in audience
        $query = "select `api_clients`.`id`, `api_clients`.`password`, `api_clients`.`active_token_id`, 
                `api_clients`.`token_expire_at`, `client_customer`.`company_id`, `client_customer`.`customer_id` 
                from 
                `api_clients` join `client_customer` 
                on 
                `api_clients`.`id` = `client_customer`.`api_client_id` 
                where 
                `api_clients`.`current_token_uuid` = '". $parameters['tokenUUID'] ."';";
                      //`user_api_key` = '". $parameters['user_api_key'] ."';";

        $result = mysqli_query($this->_db, $query);
        if (!$result) {
            return ['token' => null, 'msg' => mysqli_error($this->_db)];
        }

        if (mysqli_num_rows($result)==0) {
            return ['token' => null, 'msg' => "Invalid Or Expired Token."];
        }

        $user = mysqli_fetch_assoc($result);

        //Verify Signature: depends on user's password: depends on user record
        $signer  = new Sha512();
        $verified = $parsedToken->verify($signer, $user['password']); // hash of password as verifying key and She512:HMAC as Signer and Algo
        if (!$verified)
        {
            return [
                'token' => null,
                'msg' => 'Invalid, Or Expired Token.'
            ];
        }
        //At this point, it is possible that the Session / Timeline of the so called active token has already been expired,
        // so check session expiry
        $session = $this->_getSession($user['token_expire_at']); // with proper digitally signed jwt token_expire_at can reside in token itself instead of in database 'user' table
        if ($session && $currentRouteName != 'expireSession') {
            //User Session is alive, only extend time. '2' is query latency
            //current session is also alive, so only extend session (token expiry) time
                $this->_extendTokenExpiry($user['id'], $user['token_expire_at']);
                $msg = 'Valid Token: Token Expiry Time Extended up to Time Elapsed Between Last Request to Current Request';
                return ['token' => $token, 'msg' => $msg, 'user'=>$user];
        }
        else {
            //Expire older active token/session, or a explicit user call to expire the session
            $this->_expireToken($user['id'], $user['active_token_id']);
            return ['token' => null, 'msg' => 'Expired Session: Please, login again.'];
        }
    }

    //only to fulfill interface function need
    public function call(Micro $app)
    {
        return true;
    }

    //////////////////////////
    //// Helper Functions////
    ////////////////////////

    public function _expireToken($user_id, $token_pkey)
    {
        $expired_at = new \DateTime("now", new \DateTimeZone('Europe/London'));
        $expired_at = $expired_at->format('Y-m-d H:i:s');
        $multiQuery = "START TRANSACTION;
        UPDATE api_clients set `active_token_id` = NULL, `current_token_uuid` = NULL where `id` = $user_id;
        UPDATE tokens SET `expired_at` = '".$expired_at."' where `id` = $token_pkey;
        COMMIT;";
        $db = $this->_db;
        while(mysqli_next_result($db)) {}
        if (!mysqli_multi_query($db, $multiQuery))
            throw new Exception('Error1: '. mysqli_error($db));
        while(mysqli_next_result($db)) {}
        if ($error = mysqli_errno($db))
            throw new \Exception('Error2: '.$error);
        return true;
    }

    private function _extendTokenExpiry($user_id, $expire_at) {
        $dateTimeNow = new \DateTime("now", new \DateTimeZone('Europe/London'));
        $expire_at = new \DateTime($expire_at, new \DateTimeZone('Europe/London'));
        $_tokenExpireSeconds = 3600;

        // The If else will increment time elapsed to the token time
        //During Two requests user will have time interval set in $this->_tokenExpireSeconds; default 15 minutes
        if ($dateTimeNow >= $expire_at) {
            $expire_at->setTimestamp($expire_at->getTimestamp()+$_tokenExpireSeconds);
        }
        else {
            $expire_at->setTimestamp($dateTimeNow->getTimestamp()+$_tokenExpireSeconds);
        }

        $expire_at = $expire_at->format('Y-m-d H:i:s');
        //no need to store last_modified
        //$last_modified = $dateTimeNow->format('Y-m-d H:i:s');
        //formula to calculate last_modified using "token_expire_at", on the fly
        //$last_modified = $expire_at->setTimestamp($expire_at->getTimestamp()-$this->_tokenExpireSeconds)->format('Y-m-d H:i:s');
        $query = "UPDATE api_clients SET `token_expire_at` = '".$expire_at."' where `id` = $user_id;";
        $result = mysqli_query($this->_db, $query);
        if (!$result) {
            throw new Exception(mysqli_error($this->_db));
        }
        $affect = mysqli_affected_rows($this->_db);
        if ($affect == -1){
            throw new Exception(mysqli_error($this->_db));
        }
        if ($affect == 0){
            throw new Exception("No Records Updated");
        }
        return true;
    }

    private function _getSession($expiryDate) {
        $dateTimeNow = new \DateTime("now", new \DateTimeZone('Europe/London'));
        $expiryDate = new \DateTime($expiryDate, new \DateTimeZone('Europe/London'));
        if ($expiryDate > $dateTimeNow) {
            return true;
        }
        return false;
    }
}
