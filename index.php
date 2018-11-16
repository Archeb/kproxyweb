<?php

// Private web proxy script by Heiswayi Nrird (http://heiswayi.github.io)
// Released under MIT license
// Free Software should work like this: whatever you take for free, you must give back for free.

ob_start("ob_gzhandler");

if (!function_exists("curl_init")) die ("This proxy requires PHP's cURL extension. Please install/enable it on your server and try again.");

//Adapted from http://www.php.net/manual/en/function.getallheaders.php#99814
if (!function_exists("getallheaders")) {
  function getallheaders() {
    $result = array();
    foreach($_SERVER as $key => $value) {
      if (substr($key, 0, 5) == "HTTP_") {
        $key = str_replace(" ", "-", ucwords(strtolower(str_replace("_", " ", substr($key, 5)))));
        $result[$key] = $value;
      } else {
        $result[$key] = $value;
      }
    }
    return $result;
  }
}

define("PROXY_PREFIX", "http" . (isset($_SERVER['HTTPS']) ? "s" : "") . "://" . $_SERVER["SERVER_NAME"] . ($_SERVER["SERVER_PORT"] != 80 ? ":" . $_SERVER["SERVER_PORT"] : "") . $_SERVER["SCRIPT_NAME"] . "/");

//Makes an HTTP request via cURL, using request data that was passed directly to this script.
function makeRequest($url) {

  //Tell cURL to make the request using the brower's user-agent if there is one, or a fallback user-agent otherwise.
  $user_agent = $_SERVER["HTTP_USER_AGENT"];
  if (empty($user_agent)) {
    $user_agent = "Mozilla/5.0 (compatible; nrird.xyz/proxy)";
  }
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_USERAGENT, $user_agent);

  //Proxy the browser's request headers.
  $browserRequestHeaders = getallheaders();
  //(...but let cURL set some of these headers on its own.)
  //TODO: The unset()s below assume that browsers' request headers
  //will use casing (capitalizations) that appear within them.
  unset($browserRequestHeaders["Host"]);
  unset($browserRequestHeaders["Content-Length"]);
  //Throw away the browser's Accept-Encoding header if any;
  //let cURL make the request using gzip if possible.
  unset($browserRequestHeaders["Accept-Encoding"]);
  curl_setopt($ch, CURLOPT_ENCODING, "");
  //Transform the associative array from getallheaders() into an
  //indexed array of header strings to be passed to cURL.
  $curlRequestHeaders = array();
  foreach ($browserRequestHeaders as $name => $value) {
    $curlRequestHeaders[] = $name . ": " . $value;
  }
  curl_setopt($ch, CURLOPT_HTTPHEADER, $curlRequestHeaders);

  //Proxy any received GET/POST/PUT data.
  switch ($_SERVER["REQUEST_METHOD"]) {
    case "GET":
      $getData = array();
      foreach ($_GET as $key => $value) {
          $getData[] = urlencode($key) . "=" . urlencode($value);
      }
      if (count($getData) > 0) {
        //Remove any GET data from the URL, and re-add what was read.
        //TODO: Is the code in this "GET" case necessary?
        //It reads, strips, then re-adds all GET data; this may be a no-op.
        $url = substr($url, 0, strrpos($url, "?"));
        $url .= "?" . implode("&", $getData);
      }
    break;
    case "POST":
      curl_setopt($ch, CURLOPT_POST, true);
      //For some reason, $HTTP_RAW_POST_DATA isn't working as documented at
      //http://php.net/manual/en/reserved.variables.httprawpostdata.php
      //but the php://input method works. This is likely to be flaky
      //across different server environments.
      //More info here: http://stackoverflow.com/questions/8899239/http-raw-post-data-not-being-populated-after-upgrade-to-php-5-3
      curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents("php://input"));
    break;
    case "PUT":
      curl_setopt($ch, CURLOPT_PUT, true);
      curl_setopt($ch, CURLOPT_INFILE, fopen("php://input"));
    break;
  }

  //Other cURL options.
  curl_setopt($ch, CURLOPT_HEADER, true);
  curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt ($ch, CURLOPT_FAILONERROR, true);

  //Set the request URL.
  curl_setopt($ch, CURLOPT_URL, $url);

  //Make the request.
  $response = curl_exec($ch);
  $responseInfo = curl_getinfo($ch);
  $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
  curl_close($ch);

  //Setting CURLOPT_HEADER to true above forces the response headers and body
  //to be output together--separate them.
  $responseHeaders = substr($response, 0, $headerSize);
  $responseBody = substr($response, $headerSize);

  return array("headers" => $responseHeaders, "body" => $responseBody, "responseInfo" => $responseInfo);
}

//Converts relative URLs to absolute ones, given a base URL.
//Modified version of code found at http://nashruddin.com/PHP_Script_for_Converting_Relative_to_Absolute_URL
function rel2abs($rel, $base) {
  if (empty($rel)) $rel = ".";
  if (parse_url($rel, PHP_URL_SCHEME) != "" || strpos($rel, "//") === 0) return $rel; //Return if already an absolute URL
  if ($rel[0] == "#" || $rel[0] == "?") return $base.$rel; //Queries and anchors
  extract(parse_url($base)); //Parse base URL and convert to local variables: $scheme, $host, $path
  $path = isset($path) ? preg_replace('#/[^/]*$#', "", $path) : "/"; //Remove non-directory element from path
  if ($rel[0] == '/') $path = ""; //Destroy path if relative url points to root
  $port = isset($port) && $port != 80 ? ":" . $port : "";
  $auth = "";
  if (isset($user)) {
    $auth = $user;
    if (isset($pass)) {
      $auth .= ":" . $pass;
    }
    $auth .= "@";
  }
  $abs = "$auth$host$path$port/$rel"; //Dirty absolute URL
  for ($n = 1; $n > 0; $abs = preg_replace(array("#(/\.?/)#", "#/(?!\.\.)[^/]+/\.\./#"), "/", $abs, -1, $n)) {} //Replace '//' or '/./' or '/foo/../' with '/'
  return $scheme . "://" . $abs; //Absolute URL is ready.
}

//Proxify contents of url() references in blocks of CSS text.
function proxifyCSS($css, $baseURL) {
  return preg_replace_callback(
    '/url\((.*?)\)/i',
    function($matches) use ($baseURL) {
        $url = $matches[1];
        //Remove any surrounding single or double quotes from the URL so it can be passed to rel2abs - the quotes are optional in CSS
        //Assume that if there is a leading quote then there should be a trailing quote, so just use trim() to remove them
        if (strpos($url, "'") === 0) {
          $url = trim($url, "'");
        }
        if (strpos($url, "\"") === 0) {
          $url = trim($url, "\"");
        }
        if (stripos($url, "data:") === 0) return 'url("' . $url . '")'; //The URL isn't an HTTP URL but is actual binary data. Don't proxify it.
        return "url(" . PROXY_PREFIX . rel2abs($url, $baseURL) . ")";
    },
    $css);
}

// Create log
function recordLog($url) {
  $userip = $_SERVER['REMOTE_ADDR'];
  $rdate = date("d-m-Y", time());
  $data = $rdate.','.$userip.','.$url.PHP_EOL;
  $logfile = 'logs/'.$userip.'_log.txt';
  $fp = fopen($logfile, 'a');
  fwrite($fp, $data);
}

$proxy_prefix = PROXY_PREFIX;
$htmlcode = <<<ENDHTML
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>临时快捷梯子</title>
    <style>
        * {
            padding: 0;
            margin: 0
        }

        body {
            background: #f3f3f3;
            font: 400 16px sans-serif;
            color: #555
        }

        nav {
            max-width: 800px;
            margin: 80px auto 60px;
            text-align: center;
            font-size: 18px;
            color: silver
        }

        nav a {
            display: inline-block;
            margin: 0 14px;
            text-decoration: none;
            color: #6e6e6e;
            font-weight: 700;
            font-size: 16px
        }

        nav a.active {
            color: #6CAEE0
        }

        .form {
            box-sizing: border-box;
            width: 100%;
            max-width: 500px;
			min-width: 350px;
            margin: 50px auto;
            padding: 55px;
            background-color: #fff;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, .1);
            font: 400 14px sans-serif;
            text-align: center
        }

        .form .form-row {
            text-align: left;
        }

        .form .form-title-row {
            margin: 0 auto 40px auto;
			text-align: left;
        }

        .form h1 {
            display: block;
            box-sizing: border-box;
            color: #4C565E;
            font-size: 24px;
            padding: 0 0 3px;
            margin: 0;
            border-bottom: 2px solid #6CAEE0
        }

        .form .form-row>label span {
            display: block;
            box-sizing: border-box;
            color: #5f5f5f;
            padding: 0 0 10px;
            font-weight: 700
        }

        .form input {
            color: #5f5f5f;
            box-sizing: border-box;
            box-shadow: 1px 2px 4px 0 rgba(0, 0, 0, .08);
            padding: 12px 18px;
            border: 1px solid #dbdbdb;
			margin-bottom: 10px;
        }

        .form input[type=email],
        .form input[type=password],
        .form input[type=text],
        .form textarea {
            width: 100%
        }

        .form input[type=number] {
            max-width: 100px
        }

        .form input[type=checkbox],
        .form input[type=radio] {
            box-shadow: none;
            width: auto
        }

        .form textarea {
            color: #5f5f5f;
            box-sizing: border-box;
            box-shadow: 1px 2px 4px 0 rgba(0, 0, 0, .08);
            padding: 12px 18px;
            border: 1px solid #dbdbdb;
            resize: none;
            min-height: 80px;
        }

        .form select {
            background-color: #fff;
            color: #5f5f5f;
            box-sizing: border-box;
            width: 240px;
            box-shadow: 1px 2px 4px 0 rgba(0, 0, 0, .08);
            padding: 12px 18px;
            border: 1px solid #dbdbdb
        }

        .form .form-radio-buttons>div {
            margin-bottom: 10px
        }

        .form .form-radio-buttons label span {
            margin-left: 8px;
            color: #5f5f5f
        }

        .form .form-radio-buttons input {
            width: auto
        }

        .form button {
            border-radius: 2px;
            background-color: #6caee0;
            color: #fff;
            font-weight: 700;
            box-shadow: 1px 2px 4px 0 rgba(0, 0, 0, .08);
            padding: 14px 22px;
            border: 0;
			margin-top: 10px;
			cursor: pointer;
        }

        p.explanation {
            padding: 15px 20px;
            line-height: 1.5;
            background-color: #FFFFE0;
            font-size: 13px;
            text-align: center;
            margin-top: 40px;
            color: #6B6B48;
            border-radius: 3px;
            border-bottom: 2px solid #ECECD0;
			border-right: 2px solid #ECECD0;
            text-align: left
        }

        @media (max-width:600px) {
            form {
                padding: 30px
            }
			body {
				background: #fff;
			}
			form {
				box-shadow: none;
			}
        }
    </style>
</head>

<body>
    <div class="form">
        <div class="form-title-row">
            <h1>临时快捷梯子</h1>
        </div>
        <div class="form-row">
            <label>
		<span>Enter full URL:</span>
		<input type="text" id="site" placeholder="http://www.google.com" >
		</label>
        </div>
        <div class="form-row">
            <button onclick="goproxy()">代理方式(Cookie)</button>
            <button onclick="goproxy2()">代理方式(URI)</button>
        </div>
        <div class="form-row">
        <br>
            <p>一键直达：</p>
            <button style="background-color:#ff5722" onclick="goproxy('https://www.google.com/')">Google</button>
            <button style="background-color:#ff5722" onclick="goproxy('https://zh.wikipedia.org/')">ZH Wikipedia</button>
            <button style="background-color:#ff5722" onclick="goproxy('https://web.telegram.org/')">Telegram</button>
        </div>
        <p class="explanation"><strong>温馨提示</strong><br/>如果需要代理别的网站，请手动清除cookie<br>或者访问 <a href="/clean.php">/clean.php</a> 来清除cookie<br>原版：https://github.com/heiswayi/web-proxy-script</p>
        
    </div>
    <script>
    function goproxy(url){
        if(!url){
            url=document.getElementById('site').value;
        }
        window.document.cookie='kp_url=' +url;
        location.reload(); 
        return false;
    }
    function goproxy2(url){
        if(!url){
            url=document.getElementById('site').value;
        }
        //window.document.cookie='';
        window.location.href='/index.php/'+url;
    }
    </script>
</body>

</html>
ENDHTML;
$url = substr($_SERVER["REQUEST_URI"], strlen($_SERVER["SCRIPT_NAME"]) + 1);
if(substr($url,0,2)=="//"){
    $url="https:".$url;
}
if(substr($url,0,4)!="http" && isset($_COOKIE['kp_url'])){
    $url=$_COOKIE['kp_url'].$_SERVER["REQUEST_URI"];
}
if (empty($url)) die($htmlcode);

if (strpos($url, "//") === 0) $url = "http:" . $url; //Assume that any supplied URLs starting with // are HTTP URLs.
if (!preg_match("@^.*://@", $url)) $url = "http://" . $url; //Assume that any supplied URLs without a scheme are HTTP URLs.

// recordLog($url);

$response = makeRequest($url);
$rawResponseHeaders = $response["headers"];

$responseBody = $response["body"];
$responseInfo = $response["responseInfo"];

//cURL can make multiple requests internally (while following 302 redirects), and reports
//headers for every request it makes. Only proxy the last set of received response headers, <-有时候会在302设置cookie，蛇皮啊
//corresponding to the final request made by cURL for any given call to makeRequest().
$responseHeaderBlocks = array_filter(explode("\r\n\r\n", $rawResponseHeaders));
foreach ($responseHeaderBlocks as $oneHeaderBlock){
    $headerLines = explode("\r\n", $oneHeaderBlock);
    foreach ($headerLines as $header) {
      if (stripos($header, "Set-Cookie")===0) {
          //傻逼php不能设置多个相同header项
        $cookiename=trim(explode("=",explode(";",explode(":",$header)[1])[0])[0]);
        $cookiecontent=trim(explode("=",explode(";",explode(":",$header)[1])[0])[1]);
        setcookie($cookiename,$cookiecontent);
      }
    }
}
$lastHeaderBlock = end($responseHeaderBlocks);
$headerLines = explode("\r\n", $lastHeaderBlock);
foreach ($headerLines as $header) {
   
  if (stripos($header, "Content-Length") === false && stripos($header, "Transfer-Encoding") === false) {
    header($header);
  }
}

$contentType = "";
if (isset($responseInfo["content_type"])) $contentType = $responseInfo["content_type"];
//删除content encoding
header("content-encoding: ");
//This is presumably a web page, so attempt to proxify the DOM.
if (stripos($contentType, "text/html") !== false) {
    
    if(strpos($responseBody,'charset=gbk')){
        header("content-type: text/html;charset=gbk");
    }else{
        $responseBody = mb_convert_encoding($responseBody, "HTML-ENTITIES", mb_detect_encoding($responseBody));
    }
  //Attempt to normalize character encoding.
  

  //Parse the DOM.
  $doc = new DomDocument();
  @$doc->loadHTML($responseBody);
  $xpath = new DOMXPath($doc);

  //Rewrite forms so that their actions point back to the proxy.
  foreach($xpath->query('//form') as $form) {
    $method = $form->getAttribute("method");
    $action = $form->getAttribute("action");
    //If the form doesn't have an action, the action is the page itself.
    //Otherwise, change an existing action to an absolute version.
    $action = empty($action) ? $url : rel2abs($action, $url);
    //Rewrite the form action to point back at the proxy.
    $form->setAttribute("action", PROXY_PREFIX . $action);
  }
  //Profixy <style> tags.
  foreach($xpath->query('//style') as $style) {
    $style->nodeValue = proxifyCSS($style->nodeValue, $url);
  }
  //Proxify tags with a "style" attribute.
  foreach ($xpath->query('//*[@style]') as $element) {
    $element->setAttribute("style", proxifyCSS($element->getAttribute("style"), $url));
  }
  //Proxify any of these attributes appearing in any tag.
  $proxifyAttributes = array("href", "src");
  foreach($proxifyAttributes as $attrName) {
    foreach($xpath->query('//*[@' . $attrName . ']') as $element) { //For every element with the given attribute...
      //ehentai h@h地址不处理
      if($element->getAttribute('id')=="sm"){
          continue;
      }
      $attrContent = $element->getAttribute($attrName);
      if ($attrName == "href" && (stripos($attrContent, "javascript:") === 0 || stripos($attrContent, "mailto:") === 0)) continue;
      $attrContent = rel2abs($attrContent, $url);
      $attrContent = PROXY_PREFIX . $attrContent;
      $element->setAttribute($attrName, $attrContent);
    }
  }

  //Attempt to force AJAX requests to be made through the proxy by
  //wrapping window.XMLHttpRequest.prototype.open in order to make
  //all request URLs absolute and point back to the proxy.
  //The rel2abs() JavaScript function serves the same purpose as the server-side one in this file,
  //but is used in the browser to ensure all AJAX request URLs are absolute and not relative.
  //Uses code from these sources:
  //http://stackoverflow.com/questions/7775767/javascript-overriding-xmlhttprequest-open
  //https://gist.github.com/1088850
  //TODO: This is obviously only useful for browsers that use XMLHttpRequest but
  //it's better than nothing.

  $head = $xpath->query('//head')->item(0);
  $body = $xpath->query('//body')->item(0);
  $prependElem = $head != NULL ? $head : $body;

  //Only bother trying to apply this hack if the DOM has a <head> or <body> element;
  //insert some JavaScript at the top of whichever is available first.
  //Protects against cases where the server sends a Content-Type of "text/html" when
  //what's coming back is most likely not actually HTML.
  //TODO: Do this check before attempting to do any sort of DOM parsing?
  if ($prependElem != NULL) {

    $scriptElem = $doc->createElement("script",
      '(function() {

        if (window.XMLHttpRequest) {

          function parseURI(url) {
            var m = String(url).replace(/^\s+|\s+$/g, "").match(/^([^:\/?#]+:)?(\/\/(?:[^:@]*(?::[^:@]*)?@)?(([^:\/?#]*)(?::(\d*))?))?([^?#]*)(\?[^#]*)?(#[\s\S]*)?/);
            // authority = "//" + user + ":" + pass "@" + hostname + ":" port
            return (m ? {
              href : m[0] || "",
              protocol : m[1] || "",
              authority: m[2] || "",
              host : m[3] || "",
              hostname : m[4] || "",
              port : m[5] || "",
              pathname : m[6] || "",
              search : m[7] || "",
              hash : m[8] || ""
            } : null);
          }

          function rel2abs(base, href) { // RFC 3986

            function removeDotSegments(input) {
              var output = [];
              input.replace(/^(\.\.?(\/|$))+/, "")
                .replace(/\/(\.(\/|$))+/g, "/")
                .replace(/\/\.\.$/, "/../")
                .replace(/\/?[^\/]*/g, function (p) {
                  if (p === "/..") {
                    output.pop();
                  } else {
                    output.push(p);
                  }
                });
              return output.join("").replace(/^\//, input.charAt(0) === "/" ? "/" : "");
            }

            href = parseURI(href || "");
            base = parseURI(base || "");

            return !href || !base ? null : (href.protocol || base.protocol) +
            (href.protocol || href.authority ? href.authority : base.authority) +
            removeDotSegments(href.protocol || href.authority || href.pathname.charAt(0) === "/" ? href.pathname : (href.pathname ? ((base.authority && !base.pathname ? "/" : "") + base.pathname.slice(0, base.pathname.lastIndexOf("/") + 1) + href.pathname) : base.pathname)) +
            (href.protocol || href.authority || href.pathname ? href.search : (href.search || base.search)) +
            href.hash;

          }

          var proxied = window.XMLHttpRequest.prototype.open;
          window.XMLHttpRequest.prototype.open = function() {
              if (arguments[1] !== null && arguments[1] !== undefined) {
                var url = arguments[1];
                url = rel2abs("' . $url . '", url);
                url = "' . PROXY_PREFIX . '" + url;
                arguments[1] = url;
              }
              return proxied.apply(this, [].slice.call(arguments));
          };

        }

      })();'
    );
    $scriptElem->setAttribute("type", "text/javascript");

    $prependElem->insertBefore($scriptElem, $prependElem->firstChild);

  }

  echo "<!-- Proxified page constructed by https://nrird.xyz/proxy -->\n" . $doc->saveHTML();
  if(strpos($_SERVER['HTTP_USER_AGENT'],'Kindle')){
  echo "<style>img#sm{width:100%}</style>";
  }
  if(!isset($_COOKIE['kp_hide_menu'])){
  echo '<div id="kp_menu">Powered By <a href="https://github.com/Archeb/kproxyweb">KProxyWeb</a>&nbsp;|&nbsp;<a href="/clean.php?hidemenu">隐藏工具条</a>&nbsp;|&nbsp;<a href="/clean.php">退出代理</a></div><style>#kp_menu{font-size:14px;position:fixed;right:0;bottom:0;opacity:0.7;background-color:#333;padding:5px 10px;color:#fafafa;z-index:999;} #kp_menu a{color:#fafafa;text-decoration:none;}</style>';
  }
} else if (stripos($contentType, "text/css") !== false) { //This is CSS, so proxify url() references.
  echo proxifyCSS($responseBody, $url);
} else { //This isn't a web page or CSS, so serve unmodified through the proxy with the correct headers (images, JavaScript, etc.)
  header("Content-Length: " . strlen($responseBody));
  echo $responseBody;
}
