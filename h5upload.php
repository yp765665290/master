<?php
namespace aliyunOss;

class h5upload {

    private $AccessKeyID;
    private $AccessKeySecret;
    private $CallBackUrl;
    private $Host;

    public function __construct($config = []){
        $this->AccessKeyID = isset($config['AccessKeyID'])?$config['AccessKeyID']:config::AccessKeyID;
        $this->AccessKeySecret = isset($config['AccessKeySecret'])?$config['AccessKeySecret']:config::AccessKeySecret;
        $this->Host = isset($config['Host'])?$config['Host']:config::Host;
        //$this->CallBackUrl = isset($config['CallBackUrl'])?$config['CallBackUrl']:config::CallBackUrl;
    }

    /**
     * h5上传 通过调用此方法获取h5上传的临时KEY
     **/
    public function get() {
        $id = $this->AccessKeyID;
        $key = $this->AccessKeySecret;
        $host = $this->Host;
        //$callbackUrl = $this->CallBackUrl;
        $callbackUrl = \think\Env::get('aliyun.oss.callback');
        $file_type     = input('file_type');
        if ($file_type == 0)
        {
            $dir = 'zb/image/';
            $file_height = '${imageInfo.height}';
            $file_width = '${imageInfo.width}';
        }
        else
        {
            $dir = 'zb/video/';
            $file_height = '0';
            $file_width = '0';
        }
        $callback_param = array(
            'callbackUrl'      => $callbackUrl,
            'callbackBody'     => '{"is_admin":1,"object":${object},"file_size":${size},"file_height":'.$file_height.',"file_width":'.$file_width.',"userid":${x:userid},"file_md5":${x:file_md5},"imeil":${x:imeil},"device_net_ip":${x:device_net_ip},"file_type":${x:file_type},"video_frame":${x:video_frame},"video_durtion":${x:video_durtion},"video_desp":${x:video_desp}}',
            'callbackBodyType' => "application/json"
        );
        $callback_string = json_encode($callback_param);
        $base64_callback_body = base64_encode($callback_string);
        $now = time();
        $expire = 30; //设置该policy超时时间是10s. 即这个policy过了这个有效时间，将不能访问
        $end = $now + $expire;
        $expiration = tool::gmt_iso8601($end);
        //最大文件大小.用户可以自己设置
        $condition = array(0 => 'content-length-range', 1 => 0, 2 => 1048576000);
        $conditions[] = $condition;
        //表示用户上传的数据,必须是以$dir开始, 不然上传会失败,这一步不是必须项,只是为了安全起见,防止用户通过policy上传到别人的目录
        $start = array(0 => 'starts-with', 1 => '$key', 2 => $dir);
        $conditions[] = $start;
        $arr = array('expiration' => $expiration, 'conditions' => $conditions);
        $policy = json_encode($arr);
        $base64_policy = base64_encode($policy);
        $string_to_sign = $base64_policy;
        $signature = base64_encode(hash_hmac('sha1', $string_to_sign, $key, true));
        $response = array();
        $response['accessid'] = $id;
        $response['host'] = $host;
        $response['policy'] = $base64_policy;
        $response['signature'] = $signature;
        $response['expire'] = $end;
        $response['callback'] = $base64_callback_body;
        //这个参数是设置用户上传指定的前缀
        $response['dir'] = $dir;
        $response['type'] = 1;
        return $response;
    }

    public function callback()
    {
        // 1.获取OSS的签名header和公钥url header
        $authorizationBase64 = "";
        $pubKeyUrlBase64 = "";
        /*
         * 注意：如果要使用HTTP_AUTHORIZATION头，你需要先在apache或者nginx中设置rewrite，以apache为例，修改
         * 配置文件/etc/httpd/conf/httpd.conf(以你的apache安装路径为准)，在DirectoryIndex index.php这行下面增加以下两行
            RewriteEngine On
            RewriteRule .* - [env=HTTP_AUTHORIZATION:%{HTTP:Authorization},last]
         * */
        if (isset($_SERVER['HTTP_AUTHORIZATION']))
        {
            $authorizationBase64 = $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (isset($_SERVER['HTTP_X_OSS_PUB_KEY_URL']))
        {
            $pubKeyUrlBase64 = $_SERVER['HTTP_X_OSS_PUB_KEY_URL'];
        }

        if ($authorizationBase64 == '' || $pubKeyUrlBase64 == '')
        {
            header("http/1.1 403 Forbidden");
            exit();
        }

        // 2.获取OSS的签名
        $authorization = base64_decode($authorizationBase64);

        // 3.获取公钥
        $pubKeyUrl = base64_decode($pubKeyUrlBase64);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $pubKeyUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $pubKey = curl_exec($ch);
        if ($pubKey == "")
        {
            //header("http/1.1 403 Forbidden");
            exit();
        }

        // 4.获取回调body
        $body = file_get_contents('php://input');

        // 5.拼接待签名字符串
        $authStr = '';
        $path = $_SERVER['REQUEST_URI'];
        $pos = strpos($path, '?');
        if ($pos === false)
        {
            $authStr = urldecode($path)."\n".$body;
        }
        else
        {
            $authStr = urldecode(substr($path, 0, $pos)).substr($path, $pos, strlen($path) - $pos)."\n".$body;
        }

        // 6.验证签名
        $ok = openssl_verify($authStr, $authorization, $pubKey, OPENSSL_ALGO_MD5);
        if ($ok == 1)
        {
            header("Content-Type: application/json");
            $data = array("Status"=>"Ok");
            echo json_encode($data);
        }
        else
        {
            //header("http/1.1 403 Forbidden");
            exit();
        }

    }


}