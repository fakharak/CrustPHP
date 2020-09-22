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

error_reporting(E_ALL);
ini_set('display_errors', 1);

use Swoole\Http\Request as SwooleRequest;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Server as SwooleServer;

use Phalcon\Mvc\Micro;
use Phalcon\Loader as Loader;
use Phalcon\Http\Request;
use Phalcon\Http\Response;
use Phalcon\Di\FactoryDefault;

//For hooking with events
use Phalcon\Events\Event;
use Phalcon\Events\Manager as EventsManager;
use Phalcon\Mvc\Micro\Collection as MicroCollection;

//Service Custom Namespaces
use Middlewares\AuthMiddleware as AuthMiddleware;

// API Developer's Controllers Namespaces
use Controllers\UserSignupController as UserSignupController;
use Controllers\UserLoginController as UserLoginController;
class Bootstrap
{
    private $http;
    protected $inoInst;
    protected $watchDescriptor=array();
    protected $events;

    private $app;
    protected $di;

    protected $eventsManager;
    protected $authMiddleware;
    public static $fd =array();
    public static $streamId =array();
    //public $_SERVER = array();
    public static $loader;
    public static $instance;
    //Public as it should be accessible by controllers using this singleton object through _bootstrap service / di
    public $autocab_base_Url;
    public $_tokenAuth;
    private $_baseDir;
    private $_autocab_token;
    private $_dbConn;
    private $_dbThreadId;

    private function __construct(SwooleServer $http) {
        //File Change Notifications
        $this->http = $http;

        //This code depends on inotify php-extension
        //This is optional code and requires more testing before use
        if (extension_loaded('inotify')) {
            $this->inoInst = inotify_init();
            stream_set_blocking($this->inoInst, 0);
            $this->watchDescriptor[] = inotify_add_watch(
                $this->inoInst, __DIR__, IN_CREATE | IN_DELETE | IN_CLOSE_WRITE | IN_MOVE);
            $this->watchDescriptor[] = inotify_add_watch(
                $this->inoInst, __DIR__ . '/controllers', IN_CREATE | IN_DELETE | IN_CLOSE_WRITE | IN_MOVE);
        }
//        $this->watchDescriptor = inotify_add_watch($this->inoInst, __DIR__, IN_ALL_EVENTS);

        // RegisterAutoloaders
        self::$loader = new Loader();
        self::$loader->registerNamespaces(
            [
                'Controllers' => __DIR__.'/controllers/',
                'Middlewares' => __DIR__.'/middlewares/',
                'Lcobucci\JWT' =>  __DIR__.'/lcobucci/jwt/src/',
                'Lcobucci\JWT\Signer\Hmac' => __DIR__.'/lcobucci/jwt/src/Signer/Hmac/',
                'Constants' => __DIR__.'/Constants/',
            ]
        );
        self::$loader->register();
        //  Autoloading Ends Here

        //////////////////////////////////////
        ////Autoloader for MVC Structures////
        ////////////////////////////////////

        // $loader = new \Phalcon\Loader();
        // $loader->registerDirs(array(ROOT_PATH . '/application/controller/', ROOT_PATH . '/application/model/'));
        // $loader->register();

        // $this->di = new \Phalcon\Di\FactoryDefault();
        //  //Registering the view component
        // $this->di->set('view', function () {
        // $view = new \Phalcon\Mvc\View();
        // $view->setViewsDir(ROOT_PATH . '/application/views/');
        // return $view;
        // });

        //////////////////////////////////
        //Establish Database Connection//
        ////////////////////////////////
        //include 'db_api_connect.php';
        //include 'memcache_connect.php';

        $this->di = new FactoryDefault();

        $this->_baseDir = basename(__DIR__);

        //set Production and sandbox / staging configurations

        //This one is for Production, and runs on port 8080 as defined in constructor of phsw_http_server
        if ($this->_baseDir == 'phalcoswoole') {
            $dbName = 'db_production'; // Name of your database in Production
            $dbHost = 'localhost'; // Database host for production
            $dbUser = 'db_user_production'; //Database user for Production
            $dbPassword = 'db_password_production'; // Database Password for Production

            //Uncomment and set this if you hit any external API URL
               //$this->external_api_base_Url_production = "https://thirdparty_production_api.com:8080/api/v1/";
            //Live Authentication Credentials
               //$this->_tokenAuth = '{"Username":"production_user","Password":"xyz"}'; // Credentials for External API Authentication
        } else { // This one is for Staging / Testing Environment
            $dbName = 'db_staging'; // Name of your database in Staging
            $dbHost = 'localhost'; // Database host for Staging
            $dbUser = 'db_user_staging'; //Database user for Staging
            $dbPassword = 'db_password_staging'; // Database Password for Staging

            //Uncomment and set this if you hit any external API URL
            //sandbox URl
               //$this->external_api_base_Url_production = "https://thirdparty_sandbox_api.com:8081/api/v1/";
            //sandbox Token Credentials
               //$this->_tokenAuth = '{"Username":"sandbox_user","Password":"xyz"}';
        }

        $this->di->set(
            'db',
            function ($dbConn=null) use ($dbName) {

                if (isset($dbConn)) {
                    if (mysqli_ping($dbConn))
                      return $dbConn;
                    else {
                        mysql_kill($this->_dbThreadId);
                        mysqli_close($dbConn);
                        unset($dbConn);
                    }
                }

                //if not connected then get the database connection
                if (!isset($this->_dbConn) || !mysqli_ping($this->_dbConn)) {
                    $this->_dbConn = mysqli_connect($dbHost, $dbUser, $dbPassword, $dbName);
                    $this->_dbThreadId = $this->_dbConn->thread_id;
                    if (mysqli_connect_errno()) {
                        throw new \Exception("Failed to connect to MySQL: " . mysqli_connect_error());
                    }
                }

                //return older / new "active" connection
                return $this->_dbConn;
            }, false
        );

//        $queryTimeZone = "SET time_zone = '+1:00'";
//        mysqli_query($db, $queryTimeZone); // fix $db param when use

        //Inject this singleton bootstrap object in controllers as a Dependency; Service
        $this->di->set('bootstrap', $this);

        // This will allow the controllers to obtain Autocab Token fromthis singleton
        $this->app = new Micro();
        //Create First Token to be sharedamong requests on the very first request when singleton is created
        // This function must be called after base url and token authentication crendetials have been set
        $this->getToken(true);
    }

    public function run(SwooleRequest $request, SwooleResponse $response) {
     try {
         //This code depends on inotify php-extension
         //This is optional code and requires more testing before use
         if (extension_loaded('inotify')) {
             $this->events = inotify_read($this->inoInst);
             if (FALSE !== $this->events) {
                 opcache_reset();
                 $this->http->reload();
             }
         }

        // The function injects request params as is.
        // Consumer should perform json_decode() as per their need
         $this->di->set(
            'json_raw',
            function () use ($request) {
                 return $request->rawContent();
            }, false
         );

             //Start Swoole Response Headers before the first use of $response
         $response->initHeader();
         $this->di->set(
          'swoole_response', $response
         );

         $this->di->set(
          'swoole_request', $request
         );

         //Inject the DI with latest request / response objects into Micro App object; main service container
         $this->app->setDI($this->di);

         //Hook Authentication Middleware to micro hook on event 'before route execute'
         $authMiddleware = new AuthMiddleware();
         $eventsManager = new EventsManager();
         $eventsManager->attach('micro', $authMiddleware);

         $this->app->before($authMiddleware);
         //$this->app->after($authMiddleware);
         $this->app->setEventsManager($eventsManager);

         //Code to Allow HTTP Headers
         $this->app->before(
            function () use ($app, $response, $request) {
                $origin = (!empty(($request->header['origin']) ? $request->header['Origin'] : '*'));
                $response->header("Access-Control-Allow-Origin", $origin);
                $response->header("Access-Control-Allow-Methods", 'GET, HEAD, PUT, PATCH, POST, DELETE, OPTIONS');
                $response->header("Access-Control-Allow-Headers", 'Origin, X-Requested-With, session_id, token, Content-Range, Content-Disposition, Content-Type, Authorization');
                $response->header("Access-Control-Allow-Credentials", "true");
                //  ->header('X-Content-Type-Options', 'nosniff')
                //  ->header('X-Frame-Options', 'deny')
                //  ->header('Content-Security-Policy', 'default-src \'none\'');
                //$this->app->response- // Not needed in Swoole Mode
            });

          //Code for CORs
             $this->app->options('/{catch:(.*)}', function() use ($response) {
                 $response->status(200, "OK");
                 $this->app->response->send();
             });

            //User API: Signup endpoint
            $clientSignup = new MicroCollection();
            //Set the main handler. ie. a controller instance
            $clientSignup->setHandler(UserSignupController::class, true);
            //Set a common prefix for all routes
            $clientSignup->setPrefix('/user');
            $clientSignup->get('/create', 'create_user', 'signup');
//            $clientSignup->get('/action', 'account_activation', 'account_activation');
//            $clientSignup->post('/create', 'create_user', 'signup');
            $this->app->mount($clientSignup);

            //User API: Login enpoint
            $clientLogin = new MicroCollection();
            //Set the main handler. ie. a controller instance
            $clientLogin->setHandler(UserLoginController::class, true);
            //Set a common prefix for all routes
            $clientLogin->setPrefix('/user');
            $clientLogin->post('/login', 'login_user', 'login');
            $this->app->mount($clientLogin);

            $this->app->POST(
               '/user/expire',
                function () {
                    //Abtracted Out in Auth Middleware
                    //Inside "else" block of:
                    // "if ($alive && $currentRouteName != 'expireSession') {"
                    //user is identified by the tokenId in Authorization Token passed in "Authorization:" header
                }
            )->setName('expireSession');

            // Set response while path not found
            $this->app->notFound(
                function () use ($app) {
                    $this->app->response->setStatusCode(404, 'Not Found');
                    $this->app->response->sendHeaders();

                    $message = 'The requested service not found.';
                    $this->app->response->setContent($message);
                    $this->app->response->send();
                    //return ['not found'];
                }
            );

            ob_start();
            $res=$this->app->handle($_SERVER['REQUEST_URI']); // URI string should be passed here in Phalcon 4.x
            if (is_array($res))
               echo json_encode($res);
            else
               echo $res;
         } catch (Exception $e) {
                echo $e->getMessage();
         }
         finally {
            $result = ob_get_contents();
            ob_end_clean();
            //$response->write("<pre>".print_r(json_decode($request->rawContent(), true), true)."</pre><br/>");
            //$response->write("<pre>".print_r($_SERVER, true)."</pre><br/>");
            //$response->write("<pre>".print_r($_GET, true)."</pre><br/>");
            //$response->write($result);
            $response->end($result);
            $resp->detach();
            unset($response);
         }
    }

    //Function to Call Curl: This can be called from controllers using di (dependency injction)
    public function apiGet($url, $auth=null, $post=null, $method=null) {
        //curl poster
        $headers[] = "Content-Type: application/json";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_USERPWD, "ghost2:phaew4jeu4aic5Fo6ieXae4piM3Thi");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        if (isset($auth)){
            $headers[] = "Authentication-Token: ".$auth;
        }
        if (isset($method)) {
            if ($method == "PUT")
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
            if ($method == "POST")
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        if (isset($post)){
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        }
        return curl_exec($ch);
        //curl_close($ch);
    }

    // Returns Authentication Token for the
    public function getToken(int $reset=0) {
        // The First Token is geenrated by the Constructor of this Singleton
        if ($reset || !isset($this->_autocab_token)) {
            $this->_autocab_token = json_decode(
                $this->apiGet(
                    $this->autocab_base_Url."authenticate",
                    null,
                    $this->_tokenAuth),
                true)['secret'];
          }
          return $this->_autocab_token;
    }

    // Static Function of this Singleton that spins up to execute User Request
    public static function getInstance(SwooleRequest $request, SwooleResponse $response, SwooleServer $http)
    {
        if (!self::$instance) {
            self::$instance = new self($http);
        }
        self::$instance->run($request, $response);
    }
}
