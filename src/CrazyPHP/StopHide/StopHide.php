<?php

namespace CrazyPHP\StopHide;

class StopHide 
{
    /**
    * User agent to use in query
    * 
    * @var string
    */
    public $user_agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/58.0.3029.110 Safari/537.36';
    
    /**
    * Max queries to find end url
    * 
    * @var int
    */
    protected $max_queries = 5;
    
    /**
    * Absolute path to cookie file
    * 
    * @var mixed
    */
    protected $cookie_file = null;
    
    /**
    * History of queries
    * 
    * @var array
    */
    protected $history = [];
    
    /**
    * All redirect statuses
    * 
    * @var array
    */
    protected $redirect_statuses = [300,301,302,303,304,307,308,];
    
    /**
    * All content statuses
    * 
    * @var array
    */
    protected $content_statuses = [200,201,202,203,204,205,206,];
    
    /**
    * Create StopHide machine
    * 
    * @param int $max_queries
    * @param mixed $cookie_file
    */
    public function __construct($max_queries = 5, $cookie_file = null)
    {
        $this->max_queries = $max_queries;
        $this->cookie_file = $cookie_file;    
    }
    
    /**
    * Follow all redirects and return array with history
    * 
    * @param string $url Any shorten url
    */
    public function resolve($url)
    {
        
        $result = [
            'end_url' => null,
            'status' => 'error',//too_much|error|found
            'history' => [],
            'redirect_count' => 0,
        ];
        
        $data = $this->getAndParse($url); 
        
        if($data === false){
            $result['status'] = 'too_much';   
        }else{
            $last = $this->history[count($this->history)-1];
            if($last['type']=='error'){
                $result['status'] = 'error';
                $result['end_url'] = $last['item']['info']['url'];      
            }else{
                $result['status'] = 'found';
                $result['end_url'] = $last['item']['info']['url']; 
            }
        } 
        
        $result['history'] = $this->history;
        $result['redirect_count'] = count($this->history);
        
        return $result;        
    }
    
    public function getAndParse($url, $referer = null, $count = 0){
        
        $count++; 
        if($count>$this->max_queries){
            return false;    
        } 
        
        $item = $this->get($url, $referer);
        $parse = $this->itemParse($item);
        
        if($parse['redirect']){
            $item['parse'] = $parse;
            $this->appendHistory($item, 'redirect');   
            $data = $this->getAndParse($parse['url'], null, $count);    
        }else{ 
            if(in_array($item['info']['http_code'], $this->redirect_statuses)){
                $this->appendHistory($item, 'redirect');   
                $data = $this->getAndParse($item['info']['redirect_url'], null, $count);     
            }elseif(in_array($item['info']['http_code'], $this->content_statuses)){
                if(array_key_exists('refresh', $item['headers']) && count($item['headers']['refresh'])>0){
                    $data = $this->appendHistory($item, 'redirect');
                    preg_match('/url=(.*)/imu', $item['headers']['refresh'][0], $match);
                    $data = $this->getAndParse($match[1], $item['info']['url'], $count);       
                }else{
                    $parse = $this->contentParse($item);
                    if($parse['redirect']){
                        $item['parse'] = $parse;
                        $this->appendHistory($item, 'redirect');   
                        $data = $this->getAndParse($parse['url'], null, $count);    
                    }else{
                        $data = $this->appendHistory($item, 'content');
                    }
                }       
            }else{
                $data = $this->appendHistory($item, 'error'); 
            }
        }
        
        return $data;
    }
    
    public function itemParse($item)
    {
        $result = [
            'redirect' => false,
            'type' => null,
            'url' => null,
        ];    
        
        $parsed_url = parse_url($item['info']['url']); 
        
        /**
        * VK.CC detect   
        */
        if($parsed_url['host']=='vk.cc'){
            $parsed_redirect = parse_url($item['info']['redirect_url']); 
            parse_str($parsed_redirect['query'], $output);
            $result['url'] = $output['to'];
            $result['redirect'] = true;
            $result['type'] = 'vk_cc';
            return $result;
        }
        
        /**
        * VK away 
        */
        if(array_key_exists('query', $parsed_url)){
            parse_str($parsed_url['query'], $parsed_str);  
            if(substr_count($item['info']['url'], 'vk.com/away.php')>0){
                $result['url'] = $parsed_str['to'];
                $result['redirect'] = true;
                $result['type'] = 'vk_away';
                return $result;           
            }
        }
        
        /**
        * OK dk 
        */
        if(array_key_exists('query', $parsed_url)){
            parse_str($parsed_url['query'], $parsed_str);  
            if(substr_count($item['info']['url'], 'ok.ru/dk?')>0){  
                $result['url'] = $parsed_str['st_link'];
                $result['redirect'] = true;
                $result['type'] = 'ok_dk';
                return $result;           
            }
        }
        
        return $result;
    }
    
    /**
    * parse content to detect redirect
    * 
    * @param array $item
    */
    public function contentParse($item)
    {
        $result = [
            'redirect' => false,
            'type' => null,
            'url' => null,
        ];
        
        /**
        * Simple javascript redirect
        */
        if(preg_match('/\.location\s*=\s*[\'"]+(.*)[\'"]+/imu',$item['resp'],$matches)){
            $result['redirect'] = true;
            $result['type'] = 'js_location';
            $result['url'] = $matches[1];
            return $result;
        }  
        
        /**
        * META redirect
        */
        if(preg_match('/content\s*=\s*[\'"]+[0-9]+\s*;\s*URL\s*=\s*[\'"]+(.*)[\'"]+[\'"]+/imu',$item['resp'],$matches)){
            $result['redirect'] = true;
            $result['type'] = 'meta_refresh';
            $result['url'] = $matches[1];
            return $result;
        }
        
        return $result;
    }
    
    public function appendHistory($item, $type = 'redirect')
    {
        $data = [
            'item'=>$item,
            'type'=>$type,
        ];
        
        $this->history[] = $data; 
        
        return $data;   
    }
    
    /**
    * Make a query
    * 
    * @param string $url
    */
    public function get($url, $referer = null)
    {
        $headers = [];
        // Get cURL resource
        $curl = curl_init();
        // Set some options - we are passing in a useragent too here
        curl_setopt_array($curl, [
            CURLOPT_COOKIEJAR => $this->cookie_file,
            CURLOPT_COOKIEFILE => $this->cookie_file,
            CURLOPT_REFERER => $referer,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $url,
            CURLOPT_USERAGENT => $this->user_agent,
            CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers)
            {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                if(count($header) < 2){ // ignore invalid headers
                    return $len;
                }
                $name = strtolower(trim($header[0]));
                if(!array_key_exists($name, $headers)){
                    $headers[$name] = [trim($header[1])];
                }else{
                    $headers[$name][] = trim($header[1]);
                }
                return $len;
            },
        ]);
        // Send the request & save response to $resp 
        $resp = curl_exec($curl);   
        $info = curl_getinfo($curl);  
        $error = curl_error($curl);
        $errno = curl_errno($curl);
        // Close request to clear up some resources
        curl_close($curl);  
        
        return [
            'resp'=>$resp,
            'info'=>$info,
            'error'=>$error,
            'errno'=>$errno,
            'headers'=>$headers,
        ];  
    }
}