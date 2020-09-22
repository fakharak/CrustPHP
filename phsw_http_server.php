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
 * by Mr. Fakhar Anwar Khan. The name of Mr. Fakhar Anwar Khan may not be used to endorse or promote products derived
 * from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED ``AS IS'' AND WITHOUT ANY EXPRESS OR
 * IMPLIED WARRANTIES, INCLUDING, WITHOUT LIMITATION, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE.
 */

class AsynchHttpServer
{
    public static $instance;
    public static $masterProc;
    public static $managerProc;
    public static $workerProcs= array();
    public function __construct()
    {
        if (basename(__DIR__) == 'phalcoswoole') {
            $http = new Swoole\Http\Server("0.0.0.0", 8080, SWOOLE_PROCESS);
        } else {
            $http = new Swoole\Http\Server("0.0.0.0", 8081, SWOOLE_PROCESS);
        }

        $http->set([
            //'ssl_cert_file' => $ssl_dir . '/ssl.crt',
            //'ssl_key_file' => $ssl_dir . '/ssl.key',
            'max_request' => 10000,
            'worker_num' => 8, //One to Four time of CPU Cores
            'reactor_num' => 8, //One to Four time of CPU Cores
            'daemonize' => 3,
            'max_request' => 0,
            'dispatch_mode' => 2,
            'open_http2_protocol' => true, // Enable HTTP2 protocol
//            'open_http_protocol' => true,
//            'open_mqtt_protocol' => true,

            //'task_max_request' => swoole_cpu_num(),
            //'worker_num' => swoole_cpu_num(),
            //'enable_coroutine' => true ,
            //'max_coroutine' => 1024,
            //'pid_file' => '/tmp/App.pid',
            //'upload_tmp_dir' => '/tmp/',
            //'document_root' => '/tmp/',
            //'enable_static_handler' => true,
            //'http_parse_post' => true,
            //'daemonize' => false,
        ]);

//      $http->on('start', function(Swoole\Http\Server $http) { ... });
        $http->on('WorkerStart', array($this, 'onWorkerStart'));
        include 'bootstrap.php';
//        $http->on('request', 'Bootstrap::getInstance');
        $http->on('request', function($request, $response) use ($http) {

            //////////////////////////////////////////////////////////////
            // Bridge Swoole HTTP request to plain PHP request////////////
            // Swoole provides PHP Global variables, but in empty form////
            //////////////////////////////////////////////////////////////
            $_GET = $_POST = $_COOKIE = $_REQUEST = $_SERVER = $_FILES = [];

            $server = $request->server;
            foreach ($server as $key => $value) {
                $_SERVER[strtoupper($key)] = $value;
            }

            $host_port = $request->header['host'];
            $host_port = explode(":", $host_port);
            $_SERVER['HTTP_HOST'] = $host_port[0];
            $_SERVER['REMOTE_PORT'] = $host_port[1];
            $_SERVER['CONTENT_TYPE'] = $_SERVER['HTTP_CONTENT_TYPE'] = $request->header['content-type'];
            $_SERVER['CONTENT_LENGTH'] = $request->header['content-length'];
            $_SERVER['HTTP_AUTHORIZATION'] = (isset($request->header['authorization']) ? $request->header['authorization']: null);
            $HTTP_RAW_POST_DATA = json_encode($request->rawContent());

            // Set POST, and set REQUEST with POST (a PHP tradition)
            $_POST = json_decode($request->rawContent(), true);
            if (!empty($_POST )) {
                $_REQUEST += $_POST;
            }

            // Set $_GET, and set $_REQUEST with GET (a PHP tradition)
            // Note: $_GET contains Query Params, so do the $_REQUEST
            $_GET = $request->get;
            if (!empty($_GET)) {
                $_REQUEST += $_GET;
            }
            $_GET['_url']   = $request->server['request_uri']; // For Compatibility

            $_COOKIE = $request->cookie;
            if (!empty($_COOKIE)) {
                $_REQUEST += $_COOKIE;
            }

            $_FILES = $request->files;
            if (!empty($_FILES)) {
                $_REQUEST += $_FILES;
            }

            call_user_func_array('Bootstrap::getInstance', [$request, $response, $http]);
        });

        $http->start();
    }

    public function onWorkerStart($http, $worker_id)
    {
        self::$masterProc = $http->master_pid;
        self::$managerProc = $http->manager_pid;
        self::$workerProcs[] = $worker_id;
    }

    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
}
AsynchHttpServer::getInstance();
