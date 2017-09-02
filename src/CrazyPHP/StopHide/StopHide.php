<?php

namespace CrazyPHP\StopHide;

/**
* Unhide any shorten url with full history of redirects.
*/
class StopHide 
{
    /**
    * User agent to use in query
    * 
    * @var string
    */
    protected $user_agent = null;
    
    /**
    * Max queries to find end url
    * 
    * @var int
    */
    protected $max_queries = 5;
    
    /**
    * Query timeout
    * 
    * @var int
    */
    protected $curl_timeout = 15;
    
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
    protected $redirect_statuses = [300,301,302,303,304,307,308];
    
    /**
    * Create StopHide
    * 
    * @param int $max_queries
    * @param string $cookie_file
    * @param int $curl_timeout
    * @param string $user_agent
    */
    public function __construct($max_queries = 5, $cookie_file = null, $curl_timeout = 15, $user_agent = null)
    {
        $this->max_queries = $max_queries;
        $this->cookie_file = $cookie_file; 
        $this->curl_timeout = $curl_timeout;  
        $this->user_agent = $user_agent;  
    }
    
    /**
    * Follow all redirects and return array with results
    * 
    * @param string $url Any shorten url
    * @return array
    */
    public function resolve($url)
    {
        
        $this->history = [];
        
        $result = [
            'end_url' => null,
            'status' => 'error',//too_much|error|found
            'history' => [],
            'query_count' => 0,
        ];
        
        $data = $this->getAndParse($url); 
        
        if($data === false){
            $result['status'] = 'too_much';   
        }else{
            $last = $this->history[count($this->history)-1];
            if($last['type']=='error'){
                $result['status'] = 'error';  
            }else{
                $result['status'] = 'found';
            }
            $result['end_url'] = $last['item']['info']['url'];
        } 

        $result['history'] = $this->history;
        $result['query_count'] = count($this->history);
        
        return $result;        
    }
    
    /**
    * Get url and parse with recursion
    * 
    * @param string $url
    * @param string $referer
    * @param int $count
    * @return array last query data
    */
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
                $next_url = $this->completeUrl($item['info']['redirect_url'], $item['info']['url']);
                $data = $this->getAndParse($next_url, null, $count);     
            }elseif($item['errno']>0){
                $data = $this->appendHistory($item, 'error');    
            }else{
                if(array_key_exists('refresh', $item['headers']) && count($item['headers']['refresh'])>0){
                    $data = $this->appendHistory($item, 'redirect');
                    preg_match('/url=(.*)/imu', $item['headers']['refresh'][0], $match);
                    $next_url = $this->completeUrl($match[1], $item['info']['url']);
                    $data = $this->getAndParse($next_url, $item['info']['url'], $count);       
                }else{
                    $parse = $this->contentParse($item);
                    if($parse['redirect']){
                        $item['parse'] = $parse;
                        $this->appendHistory($item, 'redirect'); 
                        $next_url = $this->completeUrl($parse['url'], $item['info']['url']);  
                        $data = $this->getAndParse($next_url, null, $count);    
                    }else{
                        $data = $this->appendHistory($item, 'content');
                    }
                }       
            }
        }
        
        return $data;
    }
    
    /**
    * Complete url if only URI provided
    * 
    * @param string $url
    * @param string $top_url
    */
    public function completeUrl($url, $top_url)
    {
        $completed = $url;
        if(substr($url,0,1)=='/'){
            $parsed = parse_url($top_url);
            $completed = '';
            if(array_key_exists('scheme',$parsed)){
                $completed .= $parsed['scheme'].'://';    
            }else{
                $completed .= 'http://';
            }
            $completed .= $parsed['host'].$url;   
        }
        return $completed;
    }
    
    /**
    * Parse item to determine some redirects before making request
    * 
    * @param array $item
    * @return array
    */
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
    * Parse content to detect redirect
    * 
    * @param array $item
    * @return array
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
            if(filter_var($matches[1], FILTER_VALIDATE_URL) !== FALSE){
                $result['redirect'] = true;
                $result['type'] = 'js_location';
                $result['url'] = $matches[1];
                return $result;    
            }
        }  
        
        /**
        * META redirect
        */
        if(preg_match('/content\s*=\s*[\'"]+[0-9]+\s*;\s*URL\s*=\s*[\'"]?(.*)[\'"]?[\'"]+/imu',$item['resp'],$matches)){
            if(filter_var($matches[1], FILTER_VALIDATE_URL) !== FALSE){
                $result['redirect'] = true;
                $result['type'] = 'meta_refresh';
                $result['url'] = $matches[1];
                return $result;
            }
        }
        
        /**
        * link.pub
        */
        if(preg_match('/tw\([\'"]+(.*)[\'"]+\)/imu',$item['resp'],$matches)){
            if(filter_var($matches[1], FILTER_VALIDATE_URL) !== FALSE){
                $result['redirect'] = true;
                $result['type'] = 'link_pub';
                $result['url'] = $matches[1];
                return $result;
            }
        }
        
        /**
        * double qoo.by
        */
        if(preg_match('/\$\("#nexturl"\)\.attr\("href", "(.*)"\);/imu',$item['resp'],$matches)){
            if(filter_var($matches[1], FILTER_VALIDATE_URL) !== FALSE){
                $result['redirect'] = true;
                $result['type'] = 'double_qoo_by';
                $result['url'] = $matches[1];
                return $result;
            }
        }
        
        return $result;
    }
    
    /**
    * Append item to history
    * 
    * @param array $item
    * @param string $type redirect|content|error
    * @return array this very item
    */
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
    * Make a get query
    * 
    * @param string $url
    * @param string $referer
    * @return array
    */
    public function get($url, $referer = null)
    {
        
        $headers = [];
        $curl = curl_init();
        
        $curl_data = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_URL => $url,
            CURLOPT_HEADERFUNCTION => function($curl, $header) use (&$headers)
            {
                $len = strlen($header);
                $header = explode(':', $header, 2);
                //ignore invalid headers
                if(count($header) < 2){
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
        ];
        if($this->curl_timeout){
            $curl_data[CURLOPT_TIMEOUT] = $this->curl_timeout;  
        }
        if($this->cookie_file){
            $curl_data[CURLOPT_COOKIEJAR] = $this->cookie_file;  
            $curl_data[CURLOPT_COOKIEFILE] = $this->cookie_file; 
        }
        if($this->user_agent){
            $curl_data[CURLOPT_USERAGENT] = $this->user_agent;   
        }
        if($referer){
            $curl_data[CURLOPT_REFERER] = $referer;   
        }
        curl_setopt_array($curl, $curl_data);
        
        $resp = curl_exec($curl);   
        $info = curl_getinfo($curl);  
        $error = curl_error($curl);
        $errno = curl_errno($curl);
        
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