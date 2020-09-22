<?php
namespace Controllers;
error_reporting(E_ALL);
ini_set('display_errors', 1);

use Phalcon\Mvc\Controller;
use Phalcon\Security;
use Phalcon\Security\Random;
use Phalcon\Filter;
use \Exception;
use \DateTime;
use Phalcon\Di\FactoryDefault;
use Phalcon\Http\Response;

use Phalcon\Http\Request;


//PHP Error Display Code Ends here

class UserSignupController extends Controller
{

  public function create_user()
  {
    try {
        //Use Swoole_Response for for setting Response Headers
           //$this->swoole_response

        if ($this->request->isGet()) {
            $jsonp_callBack = $this->request->getQuery('xjsonpcallback');
            if (!$jsonp_callBack) {
                throw new \Exception("JSONP-Error: Request Parameter 'jsonp_callBack' missing");
            }
            $params['first_name'] = $this->request->getQuery('first_name');
            $params['last_name'] = $this->request->getQuery('last_name');
            $params['email'] = $this->request->getQuery('email');
            $params['password'] = $this->request->getQuery('password');
            $params['telephone'] = $this->request->getQuery('telephone');
            $params['company_id'] = $this->request->getQuery('company_id');
            $params['customer_id'] = $this->request->getQuery('customer_id');
        }
        else { // POST
            $params = $this->di->get('json_raw'); // Injected Swoole Json Raw Content
            $params = json_decode($params, true);

            if (!$params || is_null($params) || $params === NULL) {
                // JSONify the error message instead:
                $params = json_encode(["jsonError" => json_last_error_msg()]);
                if ($params === FALSE) {
                    // This should not happen, but we go all the way now:
                    $params = '{"jsonError":"unknown"}';
                }
                throw new \Exception("Error: $params");
                // Set HTTP response status code to: 500 - Internal Server Error
                //http_response_code(500);
            }
        }

        $required_params_keys = ["first_name", "last_name", "email", "password", "telephone", "company_id", "customer_id"];
        //request keys which are exceptional to 6 characters length constraint
        $length_exception = ["first_name", "last_name", "company_id", "customer_id"];

        $params = $this->verifyParams(
            $params,
            $required_params_keys,
            $length_exception
        );

        $password = $this->security->hash($params['password']);

        //Generate User ID Key for API; User API Key)
        //This is not regular access/session token
        //In future, this can be hashed further for user and database
        //Should be passed over SSL
        $random = new Random();
        $user_api_key = $random->uuid();

        $multiQuery = "START TRANSACTION;
              INSERT INTO `api_clients` (
                `first_name`, `last_name`, `user_api_key`, `user_email`, `password`, `telephone`, `active`)
              VALUES('" . $params['first_name'] . "', '" . $params['last_name'] . "', '" . $user_api_key . "', 
              '" . $params['email'] . "', '" . $password . "', '" . $params['telephone'] . "', 1);              
              INSERT INTO `client_customer` (`api_client_id`, `company_id`, `customer_id`)
              VALUES(LAST_INSERT_ID(), '" . $params['company_id'] . "', '" . $params['customer_id'] . "');             
              COMMIT;";

        $db = $this->di->get('db');
        if(!mysqli_multi_query($db, $multiQuery))
            throw new Exception('Error: '. mysqli_error($db));
        while(mysqli_next_result($db)) {}


        if (mysqli_errno($db))
            throw new \Exception(mysqli_error($db));

        $this->swoole_response->status(201, 'Created');
        $ph_response = new Response(); //Do not use $this->response / $this->response->send(); in this context
        if (isset($jsonp_callBack)) {
            $this->swoole_response->header('Content-Type', 'text/javascript');
            $ph_response->setContent($jsonp_callBack . '(' . json_encode(["response" => "success"]) . ')');
            //$ph_response->setContent('parseResp('.json_encode(["response"=>"success"]).')');
        }
        else {
          $this->swoole_response->header('Content-Type', 'application/json');
          $ph_response->setJsonContent(
              ["response" => "success"]
          );
       }
    }
    catch(Exception $e) {
      $errorResponse =  $e->getMessage();
      $this->swoole_response->status(409, 'Conflict');
      $this->swoole_response->header('Content-Type', 'application/json');
      $ph_response = new Response();
      $ph_response->setJsonContent(
          ['error' => $errorResponse]
          );
      }
      finally {
          if (isset($db)) {
              mysqli_close($db);
          }
          //$this->security->hash(rand()); // To minimise the effect of Time Attack and DoS Attack on Data Access
          //$ph_response->sendHeaders();
          $ph_response->send();
      }
  }

    public function verifyParams(array $params, array $required_params_keys, $length_exception = null) {
        $error=false;
        $msg = null;

        $request_params_keys = array_keys($params);

        foreach ($required_params_keys as $required_param_key) {
            if (!in_array($required_param_key, $request_params_keys)) {
                $error=true;
                $msg .= "<b>$required_param_key</b> is required. \n\r<br/>";
            }
            else {
                //if length exception then false else true
                if ( (!isset($length_exception) || !in_array($required_param_key, $length_exception)
                     ) &&
                     strlen($params[$required_param_key]) < 6
                    ) {
                        $error = true;
                        $msg .= "<b>$required_param_key</b> must be at least 6 characters long. \n\r<br/>";
                   }
            }
        }

        if ($error) {
            throw new \Exception($msg);
        }

        //Trim and strip tags from all paramters
        $filter = new Filter();
        foreach ($params as $request_key => $request_param) {
            $params[$request_key] = $this->filter->sanitize($params[$request_key], ['trim', 'striptags']);
        }

        $params['email'] = $this->filter->sanitize($params['email'], 'email');
        if (empty($params['email'])) {
            throw new \Exception("Invalid Email.");
        }

        if (filter_var($params['email'], FILTER_VALIDATE_EMAIL) === FALSE){
            throw new \Exception("Error: Invalid Email");
        }

        $params['password'] = $password = $this->filter->sanitize($params['password'], 'email');  //posted password string
        if (empty($params['password'])) {
            throw new \Exception("Enter a valid Password.");
        }

        return $params;
    }
}
