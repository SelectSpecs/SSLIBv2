<?php
include dirname(__FILE__) . '/Exception.php';

use SSAPIv2\Exception;

class SSAPIv2
{
    private $login;
    private $password;

    private $token;
    private $expired;
    private $apiHost = 'http://alpha.omnismain.com:3000/api/v.2';


    function __construct($login, $password)
    {
        $this->login = $login;
        $this->password = $password;
        $this->auth();

    }

    private function auth()
    {
        $body = ['email' => $this->login, 'password' => $this->password];
        $result = $this->sendRequest('/manager/auth', 'POST', $body);

        if ($result['error']) {
            throw new Exception('Auth Error', $this->parseErrorMessage($result['error']));
        } else {
            $this->token = $result['token'];
            $this->expired = $result['expired'];
        }
    }

    public function checkExpired()
    {
        if ($this->expired && strtotime($this->expired) < time()) {
           $this->auth();
        }
    }

    private function parseErrorMessage($error)
    {
        $message = $error;
        if (isset($error['message'])) {
            $message = $error['message'];
        }
        if (isset($error['name']) && isset($error[$error['name']]['message'])) {
            $message = $error[$error['name']]['message'];
        }
        if (is_array($message)) {
            $message = json_encode($message);
        }
        return $message;
    }


    public function sendRequest($url, $method = 'GET', $json = false)
    {
        $this->checkExpired();
        $body = '';
        $url = $this->apiHost . $url;
        $method = strtoupper($method);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) use (&$body) {
            $body .= $data;
            return mb_strlen($data, '8bit');
        });
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 0);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_0);

        $headers = [
            "Authorization: $this->token",
            "Content-Type: application/json"
        ];

        if ($json) {
            if ($method === 'GET') {
                $sign = strpos($url, '?') === false ? '?' : '&';
                $url .= $sign . http_build_query($json);
            }
            if (is_array($json)) {
                $json = json_encode($json);
            }
            $headers[] =  "Content-Length: " . strlen($json);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        try {
            $result = curl_exec($ch);
        } catch (\Exception $e) {
            throw new Exception($e->getMessage(), $e->getCode(), $e);
        }

        // Curl error
        if ($result === false) {
            throw new Exception('SSAPI request failed: ' . curl_errno($this->_curl) . ' - ' . curl_error($this->_curl), [
                'requestMethod' => $method,
                'requestUrl' => $url,
                'requestBody' => $json,
                'responseHeaders' => $headers,
                'responseBody' => $this->decodeErrorBody($body),
            ]);
        }
        $responseCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Decode JSON
        $response = json_decode($result, true);

        // Check the status code exists
        if ($responseCode == 200) {
            if ($response['error']) {
                throw new Exception('Request error result', $this->parseErrorMessage($result['error']));
            } else {
                return $response;
            }
        } else if ($responseCode > 200 && $responseCode < 300) {
            if ($method == 'HEAD') {
                return true;
            } else {
                throw new Exception('Unsupported data received from SSAPI: ' . $headers['content-type'], [
                    'requestMethod' => $method,
                    'requestUrl' => $url,
                    'requestBody' => $json,
                    'responseCode' => $responseCode,
                    'responseHeaders' => $headers,
                    'responseBody' => $this->decodeErrorBody($body),
                ]);
            }
        } else {
            throw new Exception("SSAPI request failed with code $responseCode.", [
                'requestMethod' => $method,
                'requestUrl' => $url,
                'requestBody' => $json,
                'responseCode' => $responseCode,
                'responseHeaders' => $headers,
                'responseBody' => $this->decodeErrorBody($body),
            ]);
        }
    }

    protected function decodeErrorBody($body)
    {
        $decoded = json_decode($body);
        if (isset($decoded['error']) && !is_array($decoded['error'])) {
            $decoded['error'] = preg_replace('/\b\w+?Exception\[/', "<span style=\"color: red;\">\\0</span>\n               ", $decoded['error']);
        }
        return $decoded;
    }
}
