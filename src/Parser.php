<?php namespace iWedmak\ExtraCurl;

use Cache;
use MarkWilson\XmlToJson\XmlToJsonConverter;
use Yangqi\Htmldom\Htmldom as Htmldom;

class Parser {

    public $c;
    public $header;
    public $opts;
    public $proxy_attempts;
    private $redirect_count;
    
    public function __construct()
    {
        $this->c=new \Curl\Curl();
        $this->c->setopt(CURLOPT_COOKIEFILE, 'cookie_jar.txt');
        $this->c->setopt(CURLOPT_COOKIEJAR, 'cookie_jar.txt');
        $this->c->setopt(CURLOPT_ENCODING, 'utf-8');
        //$this->c->setopt(CURLOPT_SSL_VERIFYPEER, false);
        //$this->c->setopt(CURLOPT_RETURNTRANSFER, true);
        $this->c->setCookie('language', 'en_EN');
        $this->c->setCookie('lw', 's');
        $this->header['User-Agent']='Mozilla/5.0 (iPad; U; CPU OS 3_2 like Mac OS X; en-us) AppleWebKit/531.21.10 (KHTML, like Gecko) Version/4.0.4 Mobile/7B334b Safari/531.21.102011-10-16 20:23:10';
        $this->header['Accept-language']='en';
        $this->header['Cookie']='language=en_EN; lw=s';
        $this->opts= array(
            'http'=>array(
                'method'=>"GET",
                'request_fulluri'=>true,
            )
        );
        $this->proxy_attempts=0;
        $this->redirect_count=0;
        /*
        "Accept-language: en\r\n" .
        "Cookie: language=en_EN; lw=s\r\n".
        "User-Agent: Mozilla/5.0 (iPad; U; CPU OS 3_2 like Mac OS X; en-us) AppleWebKit/531.21.10 (KHTML, like Gecko) Version/4.0.4 Mobile/7B334b Safari/531.21.102011-10-16 20:23:10\r\n"
        //*/
        //$this->c->setopt(CURLOPT_SSL_VERIFYPEER, false);
        //$this->c->setopt(CURLOPT_SSL_VERIFYHOST, false);
        //$this->c->setopt(CURLOPT_CAPATH, "sddsdf/cacert.pem");
        //$this->c->setopt(CURLOPT_SSLVERSION, CURL_SSLVERSION_DEFAULT);
        //$this->c->setopt(CURLOPT_VERBOSE , true);
        //$this->c->setopt(CURLOPT_SSL_CIPHER_LIST, "rsa_rc4_128_sha");
        //$this->c->setopt(CURLOPT_FOLLOWLOCATION, true);
        //$this->c->setopt(CURLOPT_RETURNTRANSFER, TRUE);
    }
    
    public function get($url, $time=5, $type='curl')
    {
        if($time==0)
        {
            Cache::forget($url);
        }
        
        if(Cache::has($url))
        {
            $responce = Cache::get($url);
        }
        else
        {
            switch ($type) 
            {
                case 'curl':
                    $this->c->get($url);
                    if(isset($this->c->error_code) && !empty($this->c->error_code))
                    {
                        return false;
                    }
                    $responce=$this->c->response;
                    break;
                case 'file':
                    $header = implode("\r\n", array_map(
                        function ($v, $k) { return $k . ':' . $v; }, 
                        $this->header, 
                        array_keys($this->header)
                    ));
                    $this->opts['http']['header']=$header;
                    $context = stream_context_create($this->opts);
                    $responce = @file_get_contents($url, false, $context);
                    break;
            }
            $doc = @simplexml_load_string($responce, 'SimpleXMLElement', LIBXML_NOCDATA);
            if ($doc) {
                $converter = new XmlToJsonConverter();
                $responce=json_decode($converter->convert($doc), true);
            } 
            Cache::put($url, $responce, $time);
        }
        if(!$responce)
        {
            Cache::forget($url);
        }
        return $responce;
    }
    
    public function getHtml($url, $time=5, $type='curl')
    {
        $resp=$this->get($url, $time, $type);
        $html=new Htmldom;
        $html->str_get_html($resp);
        return $html;
    }
    
    public function setAgent($type='mobile', $agent=false)
    {
        if($type=='mobile')
        {
            $str='Mozilla/5.0 (Linux; U; Android 4.0.3; de-ch; HTC Sensation Build/IML74K) AppleWebKit/534.30 (KHTML, like Gecko) Version/4.0 Mobile Safari/534.30';
            $this->c->setopt(CURLOPT_USERAGENT, $str);
            $this->header['User-Agent']=$str;
        }
        else
        {
            
        }
        
        if($agent)
        {
            $this->c->setopt(CURLOPT_USERAGENT, $agent);
            $this->header['User-Agent']=$agent;
        }
    }
    
    public function setProxy()
    {
        $time=30;
        $url='http://www.freeproxy-list.ru/api/proxy?anonymity=false&token=demo';
        $resp=$this->get($url, $time, 'file');
        $array=explode(PHP_EOL, $resp);
        $num=rand(0,count($array)-1);
        $proxy=$array[$num];
        //set proxy
        $this->opts['http']['proxy']='tcp://'.$proxy;
        $this->c->setopt(CURLOPT_PROXY, $proxy);
        
        //test proxy
        $command="curl --proxy $proxy --connect-timeout 1 http://google.com/ > /dev/null 2>&1 && echo true || echo false";
        if(!exec($command))
        {
            //if proxy test fails
            //remove broken proxy
            unset($array[$num]);
            $resp=implode(PHP_EOL,$array);
            //but back to cache
            Cache::put($url, $resp, $time);
            $this->proxy_attempts++;
            if($this->proxy_attempts>5)
            {
                //if enoth attempts break this
                return false;
            }
            //set new proxy and test it
            $this->setProxy();
        }
    }
    
    public function returnHeaders()
    {
        $this->c->setopt(CURLOPT_RETURNTRANSFER, true);
        $this->c->setopt(CURLOPT_HEADER, true);
        $this->c->setopt(CURLOPT_VERBOSE, true);
    }
    
    public function redirect()
    {
        $this->redirect_count++;
        $parse=parse_url(curl_getinfo($this->c->curl, CURLINFO_EFFECTIVE_URL));
        if(isset($this->c->response_headers) && !empty($this->c->response_headers) && $this->redirect_count<=5)
        {
            //pre($this->parser->c->response_headers);
            foreach($this->c->response_headers as $head)
            {
                
                if(mb_stripos($head, 'Location')!==false)
                {
                    $data=explode('Location:',$head);
                    if(isset($data[1]) && !empty($data[1]))
                    {
                        if(mb_stripos($head, 'http')!==false)
                        {
                            $url=trim($data[1]);
                        }
                        else
                        {
                            $url=$parse['host'].trim($data[1]);
                        }
                        return $url;
                    }
                }
            }
        }
        return false;
    }

}
?>
