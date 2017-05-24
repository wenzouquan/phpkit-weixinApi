<?php
namespace phpkit\weixinapi;
class WeiXinApi {
	var $postObj;
	function __construct($param = array()) {
		if (empty($param)) {
			throw new \Exception("Error WeiXinApi params missing", 1);
		}
		$this->token = trim($param['token']);
		$this->appid = trim($param['appID']);
		$this->mch_id = $param['mch_id'];
		$this->app_name = $param['app_name'];
		$this->store_id = $param['store_id'];
		$this->mch_key = $param['mch_key'];
		$this->appsecret = trim($param['appsecret']);
	}

	function cache($key, $data = null, $exp = "") {
		$value = array(
			'data' => $data,
		);
		if (intval($exp)) {
			$value['exp'] = time() + $exp;
		}
		if ($data) {
			S($key, $data);
		} else {
			$value = S($key);
			if ($value['exp'] && $value['exp'] < time()) {
				S($key, NULL);
				return null;
			} else {
				return $value['data'];
			}
		}
	}

	/********微信开发者认证**********/
	public function checkSignature() {
		$signature = $_GET["signature"];
		$timestamp = $_GET["timestamp"];
		$nonce = $_GET["nonce"];
		$token = $this->token;
		$tmpArr = array($token, $timestamp, $nonce);
		sort($tmpArr);
		$tmpStr = implode($tmpArr);
		$tmpStr = sha1($tmpStr);
		if ($tmpStr == $signature) {
			return true;
		} else {
			return false;
		}
	}

	/**获取收到的信息*/
	public function getMsg() {
		//get post data, May be due to the different environments
		$postStr = $GLOBALS["HTTP_RAW_POST_DATA"];
		//extract post data
		if (!empty($postStr)) {
			$this->postObj = simplexml_load_string($postStr, 'SimpleXMLElement', LIBXML_NOCDATA);
		}

	}

	/**发送文字**/
	public function makeText($contentStr) {

		$fromUsername = $this->postObj->FromUserName;
		$toUsername = $this->postObj->ToUserName;
		$time = time();
		$textTpl = "<xml>
							<ToUserName><![CDATA[$fromUsername]]></ToUserName>
							<FromUserName><![CDATA[$toUsername]]></FromUserName>
							<CreateTime>$time</CreateTime>
							<MsgType><![CDATA[%s]]></MsgType>
							<Content><![CDATA[%s]]></Content>
							<FuncFlag>0</FuncFlag>
							</xml>";
		$msgType = "text";
		$resultStr = sprintf($textTpl, $msgType, $contentStr);
		echo $resultStr;

	}

	/***发送图文***/

	public function makeNews($newsData = array()) {
		$CreateTime = time();
		$FuncFlag = $this->setFlag ? 1 : 0;
		$fromUsername = $this->postObj->FromUserName;
		$toUsername = $this->postObj->ToUserName;
		$newTplHeader = "<xml>
            <ToUserName><![CDATA[$fromUsername]]></ToUserName>
            <FromUserName><![CDATA[$toUsername]]></FromUserName>
            <CreateTime>{$CreateTime}</CreateTime>
            <MsgType><![CDATA[news]]></MsgType>
            <Content><![CDATA[%s]]></Content>
            <ArticleCount>%s</ArticleCount><Articles>";
		$newTplItem = "<item>
            <Title><![CDATA[%s]]></Title>
            <Description><![CDATA[%s]]></Description>
            <PicUrl><![CDATA[%s]]></PicUrl>
            <Url><![CDATA[%s]]></Url>
            </item>";
		$newTplFoot = "</Articles>
            <FuncFlag>%s</FuncFlag>
            </xml>";
		$Content = '';
		$itemsCount = count($newsData['items']);
		$itemsCount = $itemsCount < 10 ? $itemsCount : 10; //微信公众平台图文回复的消息一次最多10条
		if ($itemsCount) {
			foreach ($newsData['items'] as $key => $item) {
				if ($key <= 9) {
					$Content .= sprintf($newTplItem, $item['title'], $item['description'], $item['picurl'], $item['url']);
				}
			}
		}
		$header = sprintf($newTplHeader, $newsData['content'], $itemsCount);
		$footer = sprintf($newTplFoot, $FuncFlag);
		echo $header . $Content . $footer;
	}

	/*******获取微信号access_token***********/
	public function get_access_token() {
		//cacheDel("access_token");
		$key = "access_token_" . $this->appid . "_" . $this->store_id;
		$access_token = $this->cache($key);
		if ($access_token) {
			return $access_token;
		}
		$appid = $this->appid;
		$appsecret = $this->appsecret;
		$url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=$appid&secret=$appsecret";
		$output = $this->mycurl($url);
		$jsoninfo = json_decode($output, true);
		$access_token = $jsoninfo["access_token"];
		$this->cache($key, $access_token, 3600);
		return $access_token;
	}

	/****************创建微信菜单***********************/
	public function createMemu($data) {
		/******urlencode使用，把中文转一下，以免 json_encode使用变码****/
		foreach ($data as $k => $one) {
			foreach ($one['sub_button'] as $k2 => $one1) {
				$one['sub_button'][$k2]['name'] = urlencode($one1['name']);
			}

			$data[$k]['name'] = urlencode($one['name']);
			if ($one['sub_button']) {
				$data[$k]['sub_button'] = $one['sub_button'];
			}

		}
		/************提交**********/
		$data = array("button" => $data);
		$ACCESS_TOKEN = $this->get_access_token();
		$url = "https://api.weixin.qq.com/cgi-bin/menu/create?access_token={$ACCESS_TOKEN}";
		$data = urldecode(json_encode($data));
		$content = $this->mycurl($url, $data);
		return $content;
	}

	/****************删除微信菜单***************/
	public function MemuStop() {
		$ACCESS_TOKEN = $this->get_access_token();
		$url = "https://api.weixin.qq.com/cgi-bin/menu/delete?access_token={$ACCESS_TOKEN}";
		$content = $this->mycurl($url);
		return $content;
	}

	/****************生成带参数的二维码长度,限制为1到64***********************/
	public function qrcode($id) {
		$ACCESS_TOKEN = $this->get_access_token();
		$url = "https://api.weixin.qq.com/cgi-bin/qrcode/create?access_token={$ACCESS_TOKEN}";
		//$data='{"action_name": "QR_LIMIT_SCENE", "action_info": {"scene": {"scene_id": '.$id.'}}}';
		$data = '{"action_name": "QR_LIMIT_STR_SCENE", "action_info": {"scene": {"scene_str": "' . $id . '"}}}';
		$content = $this->mycurl($url, $data);
		$data = json_decode($content, true);
		if ($data['ticket']) {
			$url = "https://mp.weixin.qq.com/cgi-bin/showqrcode?ticket=" . $data['ticket'];
			return $url;
		} else {
			return $content;
		}

	}

	/***********获取用户信息************/
	public function UserInfo($openid) {
		$ACCESS_TOKEN = $this->get_access_token();
		//$openid="out9GuIuJBlBlZNLnP9TCRfAo8pk";
		$url = "https://api.weixin.qq.com/cgi-bin/user/info?access_token={$ACCESS_TOKEN}&openid={$openid}&lang=zh_CN";
		$content = $this->mycurl($url);
		$data = json_decode($content, true);
		return $data;
	}

	/**********授权登录后得到Code,用code获取用户信息*********/
	public function OAuthUserInfo($code) {
		$appid = $this->appid;
		$appsecret = $this->appsecret;
		$url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid={$appid}&secret={$appsecret}&code={$code}&grant_type=authorization_code";
		$content = $this->mycurl($url);
		$data = json_decode($content, true);
		return $data;
	}

	/**********授权登录后得信息*********/
	public function UserInfoBySnsapi($openid, $ACCESS_TOKEN) {
		//  $ACCESS_TOKEN=$this->get_access_token();
		$url = "https://api.weixin.qq.com/sns/userinfo?access_token={$ACCESS_TOKEN}&openid={$openid}&lang=zh_CN";
		$content = $this->mycurl($url);
		$data = json_decode($content, true);
		return $data;
	}

	/***************发送客服信息******************/
	public function sendText($openid, $text) {
		$ACCESS_TOKEN = $this->get_access_token();
		$url = "https://api.weixin.qq.com/cgi-bin/message/custom/send?access_token={$ACCESS_TOKEN}";
		$data = array(
			'touser' => $openid,
			'msgtype' => "text",
			'text' => array('content' => urlencode($text)),
		);
		$r = $this->mycurl($url, urldecode(json_encode($data)));
		return json_decode($r, true);
	}

	/**********下载多媒体文件*********/
	public function downM($MediaId, $Format) {
		$ACCESS_TOKEN = $this->get_access_token();
		$url = "http://file.api.weixin.qq.com/cgi-bin/media/get?access_token={$ACCESS_TOKEN}&media_id={$MediaId}";
		$r = $this->put_file_from_url_content($url, UploadPath . "wechatdown/{$MediaId}.{$Format}");
		if ($r == false) {
			$a = UploadPath . "wechatdown/{$MediaId}.AMR";
			$b = UploadPath . "wechatdown/{$MediaId}.mp3";
			$r = system("ffmpeg -i $a $b");
			return "{$MediaId}.{$Format}";
		} else {
			return false;
		}
	}

	/*********上传媒体TYPE分别有图片（image）、语音（voice）、视频（video）和缩略图（thumb）*****/
	public function uploadM($file, $type) {
		$file = UploadPath . "wechatdown/" . $file; //"/Uploads/sucai/20140905/54095f7898331.jpg";
		$ACCESS_TOKEN = $this->get_access_token();
		$url = "http://file.api.weixin.qq.com/cgi-bin/media/upload?access_token={$ACCESS_TOKEN}&type={$type}";
		$data = array('media' => "@" . $file);
		$r = $this->mycurl($url, $data);
		$data = json_decode($r, true);
		return $data;
	}

	/***************发送图片、语音、视频******************/
	public function sendMedia($type, $MediaId, $OPENID) {
		$data = '{"touser":"' . $OPENID . '","msgtype":"' . $type . '","' . $type . '":{ "media_id":"' . $MediaId . '","thumb_media_id":"' . $MediaId . '"}}';
		$ACCESS_TOKEN = $this->get_access_token();
		$url = "https://api.weixin.qq.com/cgi-bin/message/custom/send?access_token={$ACCESS_TOKEN}";
		$r = $this->mycurl($url, $data);
		$r = json_decode($r, true);
		return $r;
	}

	/***********群发接口,发送多图文openidArray*****************/
	public function groupSend_news($openidArray, $data) {
		$ACCESS_TOKEN = $this->get_access_token();
		$url = "https://api.weixin.qq.com/cgi-bin/media/uploadnews?access_token={$ACCESS_TOKEN}";
		$r = $this->mycurl($url, urldecode(json_encode($data)));
		$r = json_decode($r, true);
		if (!$r['media_id']) {
			return $r;
		}

		$touser = $openidArray ? '"touser": [' . $openidArray . '],' : "";
		$send = '{' . $touser . '"mpnews":{"media_id":"' . $r['media_id'] . '"},"msgtype":"mpnews"}';
		$url2 = $openidArray ? "https://api.weixin.qq.com/cgi-bin/message/mass/send?access_token={$ACCESS_TOKEN}" : "https://api.weixin.qq.com/cgi-bin/message/mass/sendall?access_token={$ACCESS_TOKEN}";
		$r2 = $this->mycurl($url2, $send);
		$r2 = json_decode($r2, true);
		return $r2;
	}

	/***群发接口,发送多文本****/
	public function groupSend_text($openidArray, $data) {
		$ACCESS_TOKEN = $this->get_access_token();
		$touser = $openidArray ? '"touser": [' . $openidArray . '],' : "";
		$send = '{' . $touser . '"msgtype": "text", "text": { "content": "' . $data . '"}}';
		$url2 = $openidArray ? "https://api.weixin.qq.com/cgi-bin/message/mass/send?access_token={$ACCESS_TOKEN}" : "https://api.weixin.qq.com/cgi-bin/message/mass/sendall?access_token={$ACCESS_TOKEN}";
		$r2 = $this->mycurl($url2, $send);
		return json_decode($r2, true);

	}

	/***群发接口,发送视频****/
	public function groupSend_video($openidArray, $data) {
		$ACCESS_TOKEN = $this->get_access_token();
		$url = "https://file.api.weixin.qq.com/cgi-bin/media/uploadvideo?access_token={$ACCESS_TOKEN}";
		$row = $data;
		$data['title'] = urlencode($data['title']);
		$data['description'] = urlencode($data['description']);
		$r = $this->mycurl($url, urldecode(json_encode($data)));
		$r = json_decode($r, true);
		$touser = $openidArray ? '"touser": [' . $openidArray . '],' : "";
		$send = '{' . $touser . '
		   "video":{
			  "media_id":"' . $r['media_id'] . '",
			  "title":"' . $row['title'] . '",
			  "description":"' . $row['description'] . '"
		   },
		   "msgtype":"video"
		  }';
		$url2 = $openidArray ? "https://api.weixin.qq.com/cgi-bin/message/mass/send?access_token={$ACCESS_TOKEN}" : "https://api.weixin.qq.com/cgi-bin/message/mass/sendall?access_token={$ACCESS_TOKEN}";
		$r2 = $this->mycurl($url2, $send);
		return json_decode($r2, true);

	}

	/***群发接口 发图片，声音****/
	public function groupSend_media($openidArray, $data) {
		$ACCESS_TOKEN = $this->get_access_token();
		$touser = $openidArray ? '"touser": [' . $openidArray . '],' : "";
		$send = '{' . $touser . '
		   "' . $data['type'] . '":{
			  "media_id":"' . $data['media_id'] . '",
		   },
		   "msgtype":"' . $data['type'] . '"
		  }';
		$url2 = $openidArray ? "https://api.weixin.qq.com/cgi-bin/message/mass/send?access_token={$ACCESS_TOKEN}" : "https://api.weixin.qq.com/cgi-bin/message/mass/sendall?access_token={$ACCESS_TOKEN}";
		$r2 = $this->mycurl($url2, $send);
		return json_decode($r2, true);
	}

	/***************
		     * 发送图文消息
		     * $data=array(array(
		     * title":"Happy Day",
		     * "description":"Is Really A Happy Day",
		     * "url":"URL",
		     * "picurl":"PIC_URL"
		     * ));
	*/
	public function sendNews($openid, $data) {
		$ACCESS_TOKEN = $this->get_access_token();
		foreach ($data as $k => $one) {
			$data[$k]['title'] = urlencode($one['title']);
			$data[$k]['description'] = urlencode($one['description']);
		}
		$url = "https://api.weixin.qq.com/cgi-bin/message/custom/send?access_token={$ACCESS_TOKEN}";
		$data = array(
			'touser' => $openid,
			'msgtype' => "news",
			'news' => array('articles' => $data),
		);
		$r = $this->mycurl($url, urldecode(json_encode($data)));
		return $r;
	}

	/***$url 地址 ￥post_file 提交的内容*/
	public function mycurl($url, $post_file) {
		$cookie_file = SCRIPT_ROOT;
		$agent = 'Mozilla/5.0 (Windows NT 6.2; WOW64; rv:28.0) Gecko/20100101 Firefox/28.0';
		//   $header="Content-Type:text/plain";////定义content-type为plain
		$header = "Content-Type:text/html;charset=UTF-8"; ////定义content-type为plain
		$ch = curl_init(); /////初始化一个CURL对象
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array('X-FORWARDED-FOR:81.18.18.18', 'CLIENT-IP:81.18.18.18')); //构造IP
		// curl_setopt($ch, CURLOPT_HEADER, 1);
		// curl_setopt($ch, CURLOPT_HEADER, true);//返回header
		// curl_setopt($ch, CURLOPT_NOBODY,true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $header); //设置HTTP头
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); ///设置不输出在浏览器上
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30); //50秒超时
		curl_setopt($ch, CURLOPT_USERAGENT, $agent);
		/******提交********/
		if ($post_file) {
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post_file); ////传递一个作为HTTP "POST"操作的所有数据的字符
		}
		curl_setopt($ch, CURLOPT_COOKIEFILE, SCRIPT_ROOT);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		/***认证**/
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		//curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,true); ;
		//curl_setopt($ch,CURLOPT_CAINFO,ROOT_PATH.'/cacert.pem');
		$content = curl_exec($ch);
		if (curl_errno($ch)) {
//出错则显示错误信息
			return curl_error($ch);
			return;
			//exit();
		}
		return $content;

	}

	/**
	 * 异步将远程链接上的内容(图片或内容)写到本地
	 *
	 * @param unknown $url
	 *            远程地址
	 * @param unknown $saveName
	 *            保存在服务器上的文件名
	 * @param unknown $path
	 *            保存路径
	 * @return boolean
	 */

	function put_file_from_url_content($url, $saveName) {
		// 设置运行时间为无限制
		set_time_limit(0);
		ignore_user_abort();
		$url = trim($url);
		$curl = curl_init();
		// 设置你需要抓取的URL
		curl_setopt($curl, CURLOPT_URL, $url);
		// 设置header
		curl_setopt($curl, CURLOPT_HEADER, 0);
		// 设置cURL 参数，要求结果保存到字符串中还是输出到屏幕上。
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
		// 运行cURL，请求网页
		$file = curl_exec($curl);
		// 关闭URL请求
		curl_close($curl);
		// 将文件写入获得的数据
		$filename = $saveName;
		$write = @fopen($filename, "w");
		if ($write == false) {
			return false;
		}
		if (fwrite($write, $file) == false) {
			return false;
		}
		if (fclose($write) == false) {
			return false;
		}
	}

	/**********V发送模板信息********/
	public function templateSend($openid, $data, $template_id) {
		$ACCESS_TOKEN = $this->get_access_token();
		$msg_url = "https://api.weixin.qq.com/cgi-bin/message/template/send?access_token=$ACCESS_TOKEN";
		$openid = $openid;
		$time = date("Y-m-d H:i:s", time());
		//  $data['time']=$time;
		$datastr = "";
		foreach ($data as $key => $one) {
			$datastr .= '"' . $key . '":{
                               "value":"' . $data[$key] . '",
                               "color":"#000000"
                           },';
		}
		$datastr = trim($datastr, ",");

		////请求包为一个json：
		$msg_json = '{
                       "touser":"' . $openid . '",
                       "template_id":"' . $template_id . '",
                       "url":"' . $data['url'] . '",
                       "topcolor":"#FF0000",
                       "data":{' . $datastr . '}
                   }';
		$r = $this->mycurl($msg_url, $msg_json);
		return $r;
	}

	/*******************************************************
		     *   将数组解析XML - 微信红包接口
	*/

	public function wxArrayToXml($parameters) {
		// dump($parameters);
		if (!is_array($parameters) || empty($parameters)) {
			die("参数不为数组无法解析");
		}

		$xml = "<xml>";
		foreach ($parameters as $key => $val) {
			if (is_numeric($val)) {
				$xml .= "<" . $key . ">" . $val . "</" . $key . ">";
			} else {
				$xml .= "<" . $key . "><![CDATA[" . $val . "]]></" . $key . ">";
			}

		}
		$xml .= "</xml>";
		return $xml;
	}

	/****************************************************
		     * 发红包接口
	*/
	public function sendredpack($data) {
		//$this->mch_id = "10036811";
		$p = array(
			'nonce_str' => $this->great_rand(30),
			'mch_billno' => $this->mch_id . date("YmdHis") . $this->great_rand(6),
			'mch_id' => $this->mch_id,
			'wxappid' => $this->appid,
			'send_name' => $this->app_name,
			're_openid' => $data['openid'],
			'total_amount' => $data['total_amount'],
			'total_num' => $data['total_num'],
			'wishing' => $data['wishing'],
			'client_ip' => getIp(),
			'act_name' => $data['act_name'],
			'remark' => $data['remark'],
		);
		$p['sign'] = $this->get_sign($p);
		//dump($p);exit();
		$xml = $this->wxArrayToXml($p); //dump($xml);exit();
		$url = "https://api.mch.weixin.qq.com/mmpaymkttransfers/sendredpack";
		$r = $this->wxHttpsRequestPem($url, $xml);
		$r1 = $this->xmlToArray($r);
		return $r1;
	}

	/*****种子红包**/
	public function sendgroupredpack($data) {
		//$this->mch_id = "10036811";
		$p = array(
			'nonce_str' => $this->great_rand(30),
			'mch_billno' => $this->mch_id . date("YmdHis") . $this->great_rand(6),
			'mch_id' => $this->mch_id,
			'wxappid' => $this->appid,
			'send_name' => $this->app_name,
			're_openid' => $data['openid'],
			'total_amount' => $data['total_amount'],
			'total_num' => $data['total_num'],
			'amt_type' => 'ALL_RAND', //红包金额设置方式
			'wishing' => $data['wishing'],
			'client_ip' => getIp(),
			'act_name' => $data['act_name'],
			'remark' => $data['remark'],
		);
		$p['sign'] = $this->get_sign($p);
		//dump($p);exit();
		$xml = $this->wxArrayToXml($p); //dump($xml);exit();
		$url = "https://api.mch.weixin.qq.com/mmpaymkttransfers/sendgroupredpack";
		$r = $this->wxHttpsRequestPem($url, $xml);
		$r1 = $this->xmlToArray($r);
		return $r1;
	}

	/****************************************************
		     * 发送零钱
	*/
	public function transfersMoney($data) {
		// $this->mch_id = "10036811";
		$p = array(

			'nonce_str' => $this->great_rand(30),
			'partner_trade_no' => date("YmdHis") . $this->great_rand(2),
			'mchid' => $this->mch_id,
			'mch_appid' => $this->appid,
			'check_name' => 'FORCE_CHECK',
			'openid' => $data['openid'],
			'amount' => $data['amount'],
			'desc' => $data['desc'],
			're_user_name' => $data['name'],
			'spbill_create_ip' => getIp(),
		);
		$p['sign'] = $this->get_sign($p);
		//dump($p);exit();
		$xml = $this->wxArrayToXml($p); //dump($xml);exit();
		$url = "https://api.mch.weixin.qq.com/mmpaymkttransfers/promotion/transfers";
		$r = $this->wxHttpsRequestPem($url, $xml);
		$r1 = $this->xmlToArray($r);
		return $r1;
	}

	function xmlToArray($xml) {
		return json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
	}

	/****************************************************
		     * 微信带证书提交数据 - 微信红包使用 企业转账支付
	*/

	public function wxHttpsRequestPem($url, $vars, $second = 30, $aHeader = array()) {
		$ch = curl_init();
		//超时时间
		curl_setopt($ch, CURLOPT_TIMEOUT, $second);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		//这里设置代理，如果有的话
		//curl_setopt($ch,CURLOPT_PROXY, '10.206.30.98');
		//curl_setopt($ch,CURLOPT_PROXYPORT, 8080);
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

		//以下两种方式需选择一种
		$cert = dirname(__FILE__);
		//第一种方法，cert 与 key 分别属于两个.pem文件
		//默认格式为PEM，可以注释
		curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
		curl_setopt($ch, CURLOPT_SSLCERT, $cert . '/cert/apiclient_cert.pem');
		//默认格式为PEM，可以注释
		curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');
		curl_setopt($ch, CURLOPT_SSLKEY, $cert . '/cert/apiclient_key.pem');

		curl_setopt($ch, CURLOPT_CAINFO, 'PEM');
		curl_setopt($ch, CURLOPT_CAINFO, $cert . '/cert/rootca.pem');
		//dump($cert.'/cert/rootca.pem');exit();
		//第二种方式，两个文件合成一个.pem文件
		//curl_setopt($ch,CURLOPT_SSLCERT,getcwd().'/all.pem');

		if (count($aHeader) >= 1) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $aHeader);
		}

		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $vars);
		$data = curl_exec($ch);
		//dump($data);exit();
		if ($data) {
			curl_close($ch);
			return $data;
		} else {
			$error = curl_errno($ch);
			echo "call faild, errorCode:$error\n";
			curl_close($ch);
			return false;
		}
	}

	/**
	 * 生成随机数
	 */
	public function great_rand($n = 30) {
		$str = '1234567890abcdefghijklmnopqrstuvwxyz';
		for ($i = 0; $i < $n; $i++) {
			$j = rand(0, 35);
			$t1 .= $str[$j];
		}
		return $t1;
	}

	/**
	 * 例如：
	 * appid：    wxd111665abv58f4f
	 * mch_id：    10000100
	 * device_info：  1000
	 * Body：    test
	 * nonce_str：  ibuaiVcKdpRxkhJA
	 * 第一步：对参数按照 key=value 的格式，并按照参数名 ASCII 字典序排序如下：
	 * stringA="appid=wxd930ea5d5a258f4f&body=test&device_info=1000&mch_i
	 * d=10000100&nonce_str=ibuaiVcKdpRxkhJA";
	 * 第二步：拼接支付密钥：
	 * stringSignTemp="stringA&key=192006250b4c09247ec02edce69f6a2d"
	 * sign=MD5(stringSignTemp).toUpperCase()="9A0A8659F005D6984697E2CA0A
	 * 9CF3B7"
	 */
	public function get_sign($data) {
		ksort($data);
		$stringA = "";
		foreach ($data as $k => $one) {
			$stringA .= "{$k}={$one}&";
		}

		$stringA = rtrim($stringA, "&");
		$stringSignTemp = "{$stringA}&key=$this->mch_key";
		$sign = strtoupper(md5($stringSignTemp));
		return $sign;
	}

	/******getJsApiTicket js 分享*******/
	private function getJsApiTicket() {
		// cacheDel("getJsApiTicket");
		$key = "getJsApiTicket_" . $this->appid . "_" . $this->store_id;
		$ticket = $this->cache($key);
		if ($ticket) {
			//return $ticket;
		}
		$accessToken = $this->get_access_token();
		// dump($accessToken);
		$url = "https://api.weixin.qq.com/cgi-bin/ticket/getticket?type=jsapi&access_token=$accessToken";
		$res = json_decode($this->mycurl($url));
		$ticket = $res->ticket;
		//S($key, NULL);
		$this->cache($key, $ticket, 3600);
		//dump($ticket);
		return $ticket;
	}

	function createNonceStr($length = 16) {
		$chars = "0123456789";
		$str = "";
		for ($i = 0; $i < $length; $i++) {
			$str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
		}
		return $str;
	}

	function GetSignPackage($url = "") {
		$timestamp = time();
		$jsapiTicket = $this->getJsApiTicket();
		$nonceStr = $this->createNonceStr();
		$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
		$url = $url ? $url : "$protocol$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
		// 这里参数的顺序要按照 key 值 ASCII 码升序排序
		$string = "jsapi_ticket=$jsapiTicket&noncestr=" . $nonceStr . "&timestamp=" . $timestamp . "&url={$url}";
		//dump($string);
		$signature = sha1($string);
		$signPackage = array(
			"appId" => $this->appid,
			"nonceStr" => $nonceStr,
			"timestamp" => $timestamp,
			"url" => $url,
			"signature" => $signature,
			"rawString" => $string,
		);
		//dump( $signPackage);exit();
		return $signPackage;
	}

	//获取素材
	function getNews($name) {
		$list = BoxModel("addon_blocks_image")->get(array('blocks_name' => $name, 'store_id' => $this->store_id, 'status' => 1), "", "order_by");
		$data = array();
		foreach ($list as $k => $v) {
			$row = array(
				'picurl' => ImgDomain . "/" . $v['image'],
				'title' => $v['title'],
				'description' => $v['content'],
				'url' => $v['url'],
			);
			$data[] = $row;

		}
		return $data;
	}

	//发送素材
	function sendMaterial($keyName, $openid) {
		if (!$keyName) {
			return;
		}
		$store_id = $this->store_id;
		$data1 = BoxModel("addon_wx_image_type")->where("keyName='$keyName' and store_id='$store_id'")->find();
		//dump($data1);
		$data = $this->getNews("wx_new_" . $keyName);
		if (!empty($data)) {
			$r = $this->sendNews($openid, $data);
		} else if ($data1['text']) {
			$r = $this->sendText($openid, $data1['text']);
		} else {
			$r = 'no Material';
		}
		return $r;
	}

}
