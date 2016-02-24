<?php 

class Curl {
    private $CH;
    private $COOKIES;
    private $SERVER_URL;
    private $OPTIONS = array();
    private $DEFAULT_OPTIONS = array(
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLINFO_HEADER_OUT    => true,
        CURLOPT_COOKIESESSION  => true,
        CURLOPT_USERAGENT      => 'curl',
        CURLOPT_HEADER         => true,
        CURLOPT_NOBODY         => false,
    );

    public function __construct($server_url) {
        if (!is_string($server_url) or empty($server_url)) {
            exit('server url not set');
        }
        $this->CH = curl_init();
        $this->SERVER_URL = $server_url; // Set the server url here to also prevent the cookie from being sent to another server.
    }

    public function addOption($name, $value){
        $this->OPTIONS[$name] = $value;
    }

    public function post( $url = null ) {
        return $this->request('POST', $url);
    }

    public function get( $url = null ) {
        return $this->request('GET', $url);
    }

    private function setOptions(){
        // Final curl options
        $options = $this->OPTIONS + $this->DEFAULT_OPTIONS;
        $options[CURLINFO_HEADER_OUT] = (bool)$options[CURLOPT_HEADER];
        $formatedCookies = $this->formatCookies($this->COOKIES); // Set cookies
        if (is_string($formatedCookies)) {
            $options[CURLOPT_COOKIE] = $formatedCookies;
        }
        curl_setopt_array( $this->CH, $options );
    }

    private function setCSRFHeader() {
        // CSRF Header
        if (is_array($this->COOKIES) && array_key_exists('csrftoken', $this->COOKIES)){
            $csrfheader = array('X-CSRFToken:' . $this->COOKIES['csrftoken']);
            if (is_array($this->OPTIONS[CURLOPT_HTTPHEADER])) {
                $this->OPTIONS[CURLOPT_HTTPHEADER] += $csrfheader;
            } else {
                $this->OPTIONS[CURLOPT_HTTPHEADER] = $csrfheader;
            }
        }
    }

    private function request( $method, $url ) {
        $url_opt = $this->SERVER_URL . $url; // Accept only relative urls to SERVER_URL
        $this->addOption(CURLOPT_CUSTOMREQUEST, $method);
        $this->addOption(CURLOPT_URL, $url_opt);
        $this->setCSRFHeader();
        $this->setOptions(); // Set the other options
        $result = $this->parseResponse(curl_exec($this->CH));
        $this->COOKIES = $this->parseCookies($result['response_header']);
        return $result['response_json'];
    }

    private function parseResponse($response) {
        $result['info'] = curl_getinfo($this->CH);
        $result['info']['request_header'] = trim($result['info']['request_header']);
        $hs = intval(curl_getinfo($this->CH, CURLINFO_HEADER_SIZE)); //Get the header size to extract the header
        $result['info']['response_header'] = $headers = mb_substr($response, 0, $hs); // Get the header
        $json_body = trim(mb_substr($response, $hs)); // Get the body
        $result['info']['response_body'] = $json_body;
        $result['info']['response_json'] = null;
        if (is_string($json_body)) {
           $result['info']['response_json'] = json_decode($json_body, true);
        }
        return $result;
    }

    private function parseCookies($headers) {
        preg_match_all("/^Set-cookie: (.*?);/ism", $headers, $cookies);
        $result = array();
        foreach( $cookies[1] as $cookie ){
            $buffer_explode = strpos($cookie, "=");
            $result[ trim(mb_substr($cookie,0,$buffer_explode)) ] = mb_substr($cookie,$buffer_explode+1);
        }   
        return $result;
    }

    private function formatCookies($cookies) {
        # Return cookies for a request
        if (is_array($cookies) && !empty($cookies)) {
            $buffer = array();
            foreach($cookies as $k=>$c) $buffer[] = "$k=$c";
            return implode("; ", $buffer);
        }
        return null;
    }

    private function getCSRFHeader($cookies) {
        if (is_array($cookies) && array_key_exists('csrftoken', $cookies)){
            return array('X-CSRFToken:' . $cookies['csrftoken']);
        }
        return null;
    }

    public function __destruct() {
        if ( is_resource($this->CH)) {
            curl_close($this->CH);
        }
    }
}
